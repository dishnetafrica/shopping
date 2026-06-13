<?php
namespace App\Jobs;

use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Send a plain WhatsApp message to the shop owner's alert number(s). */
class NotifyOwner implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId, public string $text, public ?string $to = null) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa): void
    {
        $ctx->set($this->tenantId);
        $t = Tenant::find($this->tenantId);
        if (! $t || ! $t->whatsapp_instance) return;

        $nums = $this->to
            ? [preg_replace('/[^0-9]/', '', $this->to)]
            : $t->ownerAlertNumbers();
        $nums = array_filter($nums);
        if (! $nums) return;

        foreach ($nums as $n) {
            try {
                $wa->forTenant($t)->sendText($t->whatsapp_instance, $n, $this->text);
                MessageLog::record($t->id, $n, $t->whatsapp_instance, 'out', 'system', $this->text);
            } catch (\Throwable $e) { /* best-effort */ }
        }
    }
}
