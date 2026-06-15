<?php

namespace App\Jobs;

use App\Support\MessageLog;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Alerts a shop (and the operator) when their WhatsApp connection drops, and
 * again when it comes back. Sent through a SEPARATE "alert" Evolution instance
 * (config whatsapp.alert_instance) — never the shop's own number, because that
 * is exactly the one that's offline. If no alert instance is configured the
 * job no-ops gracefully (the panel still shows the disconnected state).
 */
class WhatsappConnectionAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId, public bool $down) {}

    public function handle(WhatsAppManager $wa): void
    {
        $alertInstance = trim((string) config('whatsapp.alert_instance', ''));
        if ($alertInstance === '') {
            Log::warning('wa_alert.no_alert_instance', ['tenant' => $this->tenantId]);
            return;
        }

        $t = Tenant::find($this->tenantId);
        if (! $t) return;

        $recipients = $t->ownerAlertNumbers();
        if ($op = preg_replace('/[^0-9]/', '', (string) config('whatsapp.operator_alert_phone', ''))) {
            $recipients[] = $op;
        }
        $recipients = array_values(array_unique(array_filter($recipients)));
        if (! $recipients) {
            Log::warning('wa_alert.no_recipients', ['tenant' => $t->id]);
            return;
        }

        $panel = rtrim((string) config('app.url'), '/') . '/panel/setup';
        $msg = $this->down
            ? "⚠️ {$t->name}: your WhatsApp went offline.\n\nCustomers can't reach your shop right now. To reconnect, open your panel → Setup → Connect WhatsApp and scan the QR.\n{$panel}"
            : "✅ {$t->name}: your WhatsApp is back online. Your shop is receiving messages again.";

        $gw = $wa->driver('evolution');
        foreach ($recipients as $to) {
            try {
                $gw->sendText($alertInstance, $to, $msg);
                MessageLog::record($t->id, $to, $alertInstance, 'out', 'system', $msg);
            } catch (\Throwable $e) {
                Log::warning('wa_alert.send_failed', ['tenant' => $t->id, 'to' => $to, 'err' => $e->getMessage()]);
            }
        }
    }
}
