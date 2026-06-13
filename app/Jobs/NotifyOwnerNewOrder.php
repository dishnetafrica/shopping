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

/** WhatsApp the owner a summary the moment a new order lands. */
class NotifyOwnerNewOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId, public int $orderId) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa): void
    {
        $ctx->set($this->tenantId);
        $t = Tenant::find($this->tenantId);
        $o = Order::find($this->orderId);
        if (! $t || ! $o || ! $t->whatsapp_instance) return;

        $nums = array_filter($t->ownerAlertNumbers());
        if (! $nums) return;

        $cur   = (string) $t->setting('currency', 'UGX');
        $items = $o->items_text ?: collect($o->items_json ?? [])
            ->map(fn ($l) => (($l['qty'] ?? 1) . 'x ' . ($l['name'] ?? '')))->implode(', ');

        $txt = "\u{1F6D2} *New order {$o->order_no}*\n"
            . "\u{1F464} " . ($o->customer_name ?: 'Customer') . ' (' . $o->customer_phone . ")\n"
            . ($o->location ? "\u{1F4CD} {$o->location}\n" : '')
            . "\u{1F9FE} {$items}\n"
            . "\u{1F4B0} {$cur} " . number_format((float) $o->total) . "\n"
            . 'Open: ' . url('/panel');

        foreach ($nums as $n) {
            try {
                $t->whatsapp_driver
                    ? $wa->driver($t->whatsapp_driver)->sendText($t->whatsapp_instance, $n, $txt)
                    : $wa->driver()->sendText($t->whatsapp_instance, $n, $txt);
                MessageLog::record($t->id, $n, $t->whatsapp_instance, 'out', 'system', $txt);
            } catch (\Throwable $e) { /* best-effort */ }
        }
    }
}
