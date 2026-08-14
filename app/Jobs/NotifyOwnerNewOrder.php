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

        // House rule: the sell side reports in the tenant's BASE currency (USD). The order total is stored
        // in its own transaction currency (UGX for a local order), so CONVERT it to base for the alert —
        // "$315.00", not "UGX 1,151,325". Fx::convert(total, txnCurrency → base) handles USD/UGX/SSP.
        $base = \App\Services\Money\SalesBaseCurrency::baseCurrency($t);
        $txn  = \App\Services\Money\SalesBaseCurrency::txnCurrency($o, $t);
        $amount = \App\Services\Money\Fx::forTenant($t)->convert((float) $o->total, $txn, $base);
        $tz  = (string) $t->setting('timezone', config('app.timezone', 'Africa/Kampala'));
        $msg = OrderNotificationMessage::build([
            'order_no'       => $o->order_no,
            'customer_name'  => $o->customer_name,
            'customer_phone' => $o->customer_phone,
            'items_text'     => $o->items_text,
            'items_json'     => is_array($o->items_json) ? $o->items_json : [],
            'total'          => $amount,
            'location'       => $o->location,
            'created_at'     => optional($o->created_at)->timestamp,
        ], $base, $tz);

        $gateway = $wa->forTenant($t);
        $inst = $t->sendInstance();

        foreach ($recipients as $r) {
            // Claim first (durable ledger). Already-claimed -> someone handled it.
            if (! OrderNotificationSend::claim($t->id, $o->id, $r->id, self::EVENT)) {
                continue;
            }
            try {
                $res = $gateway->sendText($inst, $r->phone, $msg);
                // v5.05 (audit P1-2) — the gateway returns a body whether the API accepted the
                // message or rejected it. Recording a rejection as a delivered send is how a shop
                // ends up with an order nobody was told about and a transcript that says otherwise.
                if (is_array($res) && ($res['_ok'] ?? true) === false) {
                    throw new \RuntimeException('whatsapp api rejected the send (HTTP ' . ($res['_status'] ?? '?') . ')');
                }
                $messageId = is_array($res) ? ($res['key']['id'] ?? ($res['id'] ?? null)) : null;
                OrderNotificationSend::markSent($o->id, $r->id, self::EVENT, $messageId);
                MessageLog::record($t->id, $r->phone, $inst, 'out', 'system', $msg);
            } catch (\Throwable $e) {
                // v5.05 (audit P1-3) — this used to DELETE the ledger row, so after a failed send
                // there was no evidence a notification had ever been attempted: nothing to query,
                // nothing to alert on, and the job swallowed the throwable so the queue never
                // retried either. The row now STAYS with sent_at NULL, which makes
                // "select * from order_notification_sends where sent_at is null" the answer to
                // "which orders did nobody hear about". claim() re-takes a stale unsent row, so a
                // retry is still possible — visibility and retry, instead of one or the other.
                OrderNotificationSend::markFailed($o->id, $r->id, self::EVENT);
                Log::warning('order_notify.send_failed', [
                    'tenant' => $t->id, 'order' => $o->id, 'recipient' => $r->id, 'err' => $e->getMessage(),
                ]);
            }
        }
    }
}
