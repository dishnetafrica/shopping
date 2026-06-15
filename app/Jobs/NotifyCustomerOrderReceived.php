<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderNotificationSend;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends the customer an instant "we've received your order" WhatsApp the moment
 * a WEBSITE order is placed (bot orders are already confirmed in-chat; POS is at
 * the counter). Sent through the shop's own instance. Idempotent via the
 * order_notification_sends ledger (recipient_id 0, event 'web_customer_ack') so
 * a job retry can never double-message the customer.
 */
class NotifyCustomerOrderReceived implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT = 'web_customer_ack';

    public function __construct(public int $tenantId, public int $orderId) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa): void
    {
        $ctx->set($this->tenantId);
        $t = Tenant::find($this->tenantId);
        $o = Order::find($this->orderId);
        if (! $t || ! $o || ! $t->whatsapp_instance || ! $o->customer_phone) return;

        // Claim first (durable ledger). Already-claimed -> a retry/duplicate; skip.
        if (! OrderNotificationSend::claim($t->id, $o->id, 0, self::EVENT)) return;

        $cur   = (string) $t->setting('currency', 'UGX');
        $first = trim((string) strtok((string) $o->customer_name, ' '));
        $hi    = $first !== '' ? "Hi {$first}" : 'Hi';
        $total = $o->total ? (' (' . $cur . ' ' . number_format((float) $o->total) . ')') : '';

        $msg = "{$hi} \u{1F64F} We've received your order *{$o->order_no}*{$total}.\n"
             . "{$t->name} will confirm and arrange delivery shortly. Reply here if you'd like to change anything.";

        try {
            $res = $wa->forTenant($t)->sendText($t->whatsapp_instance, $o->customer_phone, $msg);
            $messageId = is_array($res) ? ($res['key']['id'] ?? ($res['id'] ?? null)) : null;
            OrderNotificationSend::markSent($o->id, 0, self::EVENT, $messageId);
            MessageLog::record($t->id, $o->customer_phone, $t->whatsapp_instance, 'out', 'system', $msg);
        } catch (\Throwable $e) {
            OrderNotificationSend::releaseClaim($o->id, 0, self::EVENT);
            Log::warning('order_customer_ack.send_failed', [
                'tenant' => $t->id, 'order' => $o->id, 'err' => $e->getMessage(),
            ]);
        }
    }
}
