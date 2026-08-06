<?php
namespace App\Jobs;

use App\Models\Order;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\HumanText;
use App\Support\MessageLog;
use App\Support\TenantContext;
use App\Support\Vertical;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WhatsApp message to the customer when an order's status changes (dispatched by OrderObserver).
 *
 * v4.6.2: the wording is now VERTICAL-AWARE. The original copy was written for a restaurant
 * ("the kitchen is starting on it", "👨‍🍳 being prepared", "our rider") and was sent to every
 * tenant — a wholesale customer buying 20 cartons of flour was told a chef was cooking it.
 * Restaurant/snacks tenants keep the kitchen voice; every other vertical (grocery, wholesale/
 * manufacturer, solar, auto parts, legacy-unset) gets trade wording ("packed at our store",
 * "on the way to you"). The hardcoded "left Kampala … on its way to Juba" route is gone.
 *
 * Owners can override ANY status line with a tenant setting — key `status_msg_<status slug>`
 * (lowercase, spaces → underscores: status_msg_preparing, status_msg_out_for_delivery, …) with
 * placeholders {name} (customer first name), {order_no}, {business}, {status}. Same pattern as
 * the sales-cadence templates (sales_msg_*). Greeting uses HumanText::firstName — "HOTEL DE
 * JUBA" is greeted as "Hotel", "ABDIFATAH AHMED SHOP NO 48" as "Abdifatah", never raw CAPS.
 */
class SendOrderStatusNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId, public int $orderId, public string $status) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa): void
    {
        $ctx->set($this->tenantId);
        $order = Order::find($this->orderId);
        $tenant = Tenant::find($this->tenantId);
        if (!$order || !$tenant || !$order->customer_phone) return;

        $first = HumanText::firstName((string) $order->customer_name) ?: 'there';
        $name  = $tenant->name;
        $no    = (string) $order->order_no;

        // v4.43 — CONFIRMATION IS THE ONE STATUS THAT NEEDS THE DETAIL.
        //
        // "Your order ORD-1041 is confirmed" contains no fact anyone can be held to. The itemised
        // numbers only reached the customer on the invoice, which auto-issues on DELIVERY — after
        // the argument at the truck has already happened. For this status we send the full frozen
        // order instead: every line, quantity, unit price, total, address and terms, plus a
        // one-tap confirm link. See App\Services\Sales\OrderConfirmationService.
        //
        // The `status_msg_confirmed` / `status_msg_accepted` owner override is superseded while
        // this is on (documented in Settings) — two messages for one event would be worse than
        // either alone. Turning `notify_order_confirmation` OFF restores the old one-liner exactly.
        if (in_array($this->status, ['Confirmed', 'Accepted'], true)
            && (bool) $tenant->setting('notify_order_confirmation', true)) {
            \App\Jobs\NotifyOrderConfirmation::dispatch($this->tenantId, (int) $order->id, 'confirmed');

            return;
        }

        // 1) Owner override wins for any status (settings key status_msg_<slug>).
        $slug = strtolower(str_replace([' ', '-'], '_', $this->status));
        $tpl  = trim((string) $tenant->setting('status_msg_' . $slug, ''));
        if ($tpl !== '') {
            $text = strtr($tpl, [
                '{name}'     => $first,
                '{order_no}' => $no,
                '{business}' => $name,
                '{status}'   => $this->status,
            ]);
        } else {
            $vertical = Vertical::of($tenant);
            $kitchen  = in_array($vertical, [Vertical::RESTAURANT, Vertical::SNACKS], true);

            $messages = $kitchen
                ? [
                    // Restaurant kitchen voice (unchanged for food tenants).
                    'Accepted'         => "\u{1F373} Hi {$first}! {$name} has accepted your order *{$no}*. The kitchen is starting on it now.",
                    'Preparing'        => "\u{1F468}\u{200D}\u{1F373} {$first}, your order *{$no}* is being prepared. It won't be long!",
                    'Ready'            => "\u{2705} {$first}, your order *{$no}* is ready! It will be dispatched shortly.",
                    'Dispatched'       => "\u{1F6F5} Your {$name} order *{$no}* is on the way! Our rider will reach you soon.",
                    'Rejected'         => "We're sorry {$first} \u{1F64F} — {$name} couldn't take order *{$no}* right now. Reply here and we'll help.",
                    'Confirmed'        => "Hi {$first}! \u{1F389} Your {$name} order *{$no}* is confirmed. We're preparing it now.",
                    'Packed'           => "\u{1F4E6} {$first}, your order *{$no}* is packed and ready to go!",
                    'In transit'       => "\u{1F69A} {$first}, your order *{$no}* is in transit. We'll let you know as soon as it arrives.",
                    'Out for delivery' => "\u{1F6F5} Your {$name} order is on the way! Our rider will reach you shortly.",
                    'Delivered'        => "\u{2705} Your order *{$no}* has been delivered. Thank you for choosing {$name}! \u{1F64F}",
                    'Cancelled'        => "Your {$name} order *{$no}* has been cancelled. Reply here if you need help.",
                ]
                : [
                    // Trade voice — grocery / wholesale / manufacturer / solar / auto parts / legacy.
                    'Accepted'         => "\u{2705} Hi {$first}! {$name} has accepted your order *{$no}*. We're getting it ready.",
                    'Preparing'        => "\u{1F4E6} {$first}, your order *{$no}* is being packed at our store.",
                    'Ready'            => "\u{2705} {$first}, your order *{$no}* is packed and ready. It will be dispatched shortly.",
                    'Dispatched'       => "\u{1F69A} Your {$name} order *{$no}* is on the way to you.",
                    'Rejected'         => "We're sorry {$first} \u{1F64F} — {$name} can't take order *{$no}* right now. Reply here and we'll help.",
                    'Confirmed'        => "Hi {$first}! \u{2705} Your {$name} order *{$no}* is confirmed. We're getting it ready.",
                    'Packed'           => "\u{1F4E6} {$first}, your order *{$no}* is packed and ready to go!",
                    'In transit'       => "\u{1F69A} {$first}, your order *{$no}* is in transit. We'll let you know as soon as it arrives.",
                    'Out for delivery' => "\u{1F69A} Your {$name} order *{$no}* is out for delivery and will reach you shortly.",
                    'Delivered'        => "\u{2705} Your order *{$no}* has been delivered. Thank you for choosing {$name}! \u{1F64F}",
                    'Cancelled'        => "Your {$name} order *{$no}* has been cancelled. Reply here if you need help.",
                ];

            // Pickup tenants (snacks / advance-booking) don't deliver — reword the
            // delivery-specific stages so the customer is told to collect, not wait for a rider.
            if ($vertical === Vertical::SNACKS) {
                $messages = array_merge($messages, [
                    'Ready'            => "\u{2705} {$first}, your order *{$no}* is ready for collection! Come by {$name} when you're ready.",
                    'Dispatched'       => "\u{1F6CD} {$first}, your {$name} order *{$no}* is ready for pickup. See you soon!",
                    'Out for delivery' => "\u{1F6CD} {$first}, your {$name} order *{$no}* is ready for pickup. See you soon!",
                    'Delivered'        => "\u{2705} Thanks for collecting order *{$no}*, {$first}! Hope to see you again at {$name}. \u{1F64F}",
                ]);
            }

            $text = $messages[$this->status] ?? null;
        }

        if (!$text) return;

        $inst = $tenant->sendInstance();
        $wa->forTenant($tenant)
           ->sendText($inst, $order->customer_phone, $text);

        MessageLog::record(
            $this->tenantId, $order->customer_phone, $inst,
            'out', 'system', $text
        );
    }
}
