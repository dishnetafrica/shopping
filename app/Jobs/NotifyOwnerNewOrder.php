<?php
namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderNotificationRecipient;
use App\Models\OrderNotificationSend;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\OrderNotificationMessage;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp every ACTIVE order-notification recipient the moment a new order
 * lands. Idempotent via the order_notification_sends ledger: a duplicate event
 * or job retry never double-notifies. A send that fails releases its claim so a
 * later retry can try again (without ever duplicating a successful send).
 */
class NotifyOwnerNewOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT = 'order_placed';

    public function __construct(public int $tenantId, public int $orderId) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa): void
    {
        $ctx->set($this->tenantId);
        $t = Tenant::find($this->tenantId);
        $o = Order::find($this->orderId);
        if (! $t || ! $o || ! $t->whatsapp_instance) return;

        $recipients = OrderNotificationRecipient::query()
            ->where('active', true)
            ->get();
        if ($recipients->isEmpty()) return;

        $cur = (string) $t->setting('currency', 'UGX');
        $tz  = (string) $t->setting('timezone', config('app.timezone', 'Africa/Kampala'));
        $msg = OrderNotificationMessage::build([
            'order_no'       => $o->order_no,
            'customer_name'  => $o->customer_name,
            'customer_phone' => $o->customer_phone,
            'items_text'     => $o->items_text,
            'items_json'     => is_array($o->items_json) ? $o->items_json : [],
            'total'          => $o->total,
            'location'       => $o->location,
            'created_at'     => optional($o->created_at)->timestamp,
        ], $cur, $tz);

        $gateway = $wa->forTenant($t);

        foreach ($recipients as $r) {
            // Claim first (durable ledger). Already-claimed -> someone handled it.
            if (! OrderNotificationSend::claim($t->id, $o->id, $r->id, self::EVENT)) {
                continue;
            }
            try {
                $res = $gateway->sendText($t->whatsapp_instance, $r->phone, $msg);
                $messageId = is_array($res) ? ($res['key']['id'] ?? ($res['id'] ?? null)) : null;
                OrderNotificationSend::markSent($o->id, $r->id, self::EVENT, $messageId);
                MessageLog::record($t->id, $r->phone, $t->whatsapp_instance, 'out', 'system', $msg);
            } catch (\Throwable $e) {
                // Release the claim so a retry can attempt again — no duplicate, never stuck.
                OrderNotificationSend::releaseClaim($o->id, $r->id, self::EVENT);
                Log::warning('order_notify.send_failed', [
                    'tenant' => $t->id, 'order' => $o->id, 'recipient' => $r->id, 'err' => $e->getMessage(),
                ]);
            }
        }
    }
}
