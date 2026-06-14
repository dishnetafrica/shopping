<?php
namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Marketing\AudienceResolver;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a marketing campaign to its audience, ONE message at a time with a
 * randomised delay between sends. Broadcasting fast on an unofficial WhatsApp
 * connection is the quickest way to get a number banned — the throttle is a
 * deliberate safety measure, not a performance bug.
 */
class SendCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;   // long-running by design (throttled)

    public function __construct(public int $tenantId, public int $campaignId) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa, AudienceResolver $aud): void
    {
        $ctx->set($this->tenantId);

        $t = Tenant::find($this->tenantId);
        $c = Campaign::find($this->campaignId);
        if (! $t || ! $t->whatsapp_instance || ! $c) return;

        $phones  = $aud->phones($c->audience, $c->category);
        $caption = $this->compose($c);
        $gateway = $wa->forTenant($t);

        $stats = is_array($c->stats) ? $c->stats : [];
        $stats['targeted']   = count($phones);
        $stats['started_at'] = now()->toIso8601String();
        $c->update(['status' => 'sending', 'stats' => $stats]);

        $sent = 0; $failed = 0; $skipped = 0;
        foreach ($phones as $to) {
            $recipient = \App\Support\Idempotency::recipient((string) $to);
            // 2D — claim this recipient. A retried/restarted job skips anyone already handled.
            if (! \App\Models\CampaignMessage::claim($t->id, $c->id, $recipient)) {
                $skipped++;
                continue;
            }
            try {
                if ($c->image_url) {
                    $gateway->sendImage($t->whatsapp_instance, $to, $c->image_url, $caption);
                } else {
                    $gateway->sendText($t->whatsapp_instance, $to, $caption);
                }
                MessageLog::record($t->id, $to, $t->whatsapp_instance, 'out', 'system', '[campaign] ' . $caption);
                \App\Models\CampaignMessage::markSent($c->id, $recipient, null, 'sent');
                $sent++;
            } catch (\Throwable $e) {
                \App\Models\CampaignMessage::markSent($c->id, $recipient, null, 'failed');
                $failed++;
            }
            // Throttle: 4–9s jitter between messages (ban-risk mitigation).
            usleep(random_int(4, 9) * 1000000);
        }

        $stats['sent']        = $sent;
        $stats['failed']      = $failed;
        $stats['finished_at'] = now()->toIso8601String();
        $c->update(['status' => 'sent', 'stats' => $stats]);
    }

    protected function compose(Campaign $c): string
    {
        $lines = [];
        if ($c->message) $lines[] = trim($c->message);

        if (is_array($c->product_ids) && $c->product_ids) {
            $prods = Product::whereIn('id', $c->product_ids)->get();
            foreach ($prods as $p) {
                $lines[] = '• ' . $p->name . ' — UGX ' . number_format((float) $p->price);
            }
        }

        if ($c->cta) {
            $lines[] = '';
            $lines[] = $c->cta;
        }
        return implode("\n", $lines);
    }
}
