<?php
namespace App\Jobs;

use App\Models\Order;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        $first = trim(explode(' ', (string) $order->customer_name)[0] ?: 'there');
        $name  = $tenant->name;

        $messages = [
            // Restaurant kitchen flow
            'Accepted'         => "\u{1F373} Hi {$first}! {$name} has accepted your order *{$order->order_no}*. The kitchen is starting on it now.",
            'Preparing'        => "\u{1F468}\u{200D}\u{1F373} {$first}, your order *{$order->order_no}* is being prepared. It won't be long!",
            'Ready'            => "\u{2705} {$first}, your order *{$order->order_no}* is ready! It will be dispatched shortly.",
            'Dispatched'       => "\u{1F6F5} Your {$name} order *{$order->order_no}* is on the way! Our rider will reach you soon.",
            'Rejected'         => "We're sorry {$first} \u{1F64F} — {$name} couldn't take order *{$order->order_no}* right now. Reply here and we'll help.",
            // Legacy grocery flow (kept for existing tenants)
            'Confirmed'        => "Hi {$first}! \u{1F389} Your {$name} order *{$order->order_no}* is confirmed. We're preparing it now.",
            'Packed'           => "\u{1F4E6} {$first}, your order *{$order->order_no}* is packed and ready to go!",
            'Out for delivery' => "\u{1F6F5} Your {$name} order is on the way! Our rider will reach you shortly.",
            'Delivered'        => "\u{2705} Your order *{$order->order_no}* has been delivered. Thank you for choosing {$name}! \u{1F64F}",
            'Cancelled'        => "Your {$name} order *{$order->order_no}* has been cancelled. Reply here if you need help.",
        ];

        // Pickup tenants (snacks / advance-booking) don't deliver — reword the
        // delivery-specific stages so the customer is told to collect, not wait for a rider.
        if (\App\Support\Vertical::of($tenant) === \App\Support\Vertical::SNACKS) {
            $messages = array_merge($messages, [
                'Ready'            => "\u{2705} {$first}, your order *{$order->order_no}* is ready for collection! Come by {$name} when you're ready.",
                'Dispatched'       => "\u{1F6CD} {$first}, your {$name} order *{$order->order_no}* is ready for pickup. See you soon!",
                'Out for delivery' => "\u{1F6CD} {$first}, your {$name} order *{$order->order_no}* is ready for pickup. See you soon!",
                'Delivered'        => "\u{2705} Thanks for collecting order *{$order->order_no}*, {$first}! Hope to see you again at {$name}. \u{1F64F}",
            ]);
        }

        $text = $messages[$this->status] ?? null;
        if (!$text) return;

        $wa->forTenant($tenant)
           ->sendText($tenant->whatsapp_instance, $order->customer_phone, $text);

        MessageLog::record(
            $this->tenantId, $order->customer_phone, $tenant->whatsapp_instance,
            'out', 'system', $text
        );
    }
}
