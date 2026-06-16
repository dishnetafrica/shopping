<?php
namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Marketing\AudienceResolver;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\Idempotency;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a marketing campaign to its audience as a SLOW, RESUMABLE drip — one
 * message at a time with randomised delays, a per-day cap, batch pauses, and
 * quiet-hours. Broadcasting fast on an unofficial WhatsApp connection is the
 * quickest way to get a number banned, so every limit here is a deliberate
 * safety measure. A 5,000-recipient campaign is spread over several days
 * automatically; ResumeCampaigns picks it back up each window.
 *
 * Per-tenant overrides (Tenant settings), with safe defaults:
 *   bcast_daily_cap   max sends per campaign per day         (800)
 *   bcast_min_delay   min seconds between messages           (6)
 *   bcast_max_delay   max seconds between messages           (12)
 *   bcast_batch_size  messages before a longer pause         (40)
 *   bcast_batch_pause seconds to pause after each batch      (90)
 *   bcast_quiet_start hour (0-23) sending stops at night     (21)
 *   bcast_quiet_end   hour (0-23) sending resumes in morning (8)
 */
class SendCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;       // each invocation is bounded by PER_RUN below
    private const PER_RUN = 200;      // messages per job invocation (keeps us well under timeout)

    public function __construct(public int $tenantId, public int $campaignId) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa, AudienceResolver $aud): void
    {
        $ctx->set($this->tenantId);

        $t = Tenant::find($this->tenantId);
        $c = Campaign::find($this->campaignId);
        if (! $t || ! $t->whatsapp_instance || ! $c) return;
        if (in_array($c->status, ['sent', 'cancelled'], true)) return;

        $tz  = (string) $t->setting('timezone', 'Africa/Kampala');
        $now = now($tz);

        // Tunables (clamped to sane floors so a bad setting can't unleash a flood).
        $cap        = max(1,  (int) $t->setting('bcast_daily_cap', 800));
        $minD       = max(3,  (int) $t->setting('bcast_min_delay', 6));
        $maxD       = max($minD, (int) $t->setting('bcast_max_delay', 12));
        $batchSize  = max(5,  (int) $t->setting('bcast_batch_size', 40));
        $batchPause = max(10, (int) $t->setting('bcast_batch_pause', 90));
        $qStart     = (int) $t->setting('bcast_quiet_start', 21);
        $qEnd       = (int) $t->setting('bcast_quiet_end', 8);

        // Quiet hours -> pause; ResumeCampaigns re-dispatches once the window opens.
        if ($this->inQuietHours((int) $now->format('G'), $qStart, $qEnd)) {
            $c->update(['status' => 'paused']);
            return;
        }

        // Daily cap already reached for this campaign today -> pause until tomorrow.
        $sentToday = CampaignMessage::where('campaign_id', $c->id)
            ->where('status', 'sent')
            ->where('sent_at', '>=', $now->copy()->startOfDay()->utc())
            ->count();
        $allow = $cap - $sentToday;
        if ($allow <= 0) {
            $c->update(['status' => 'paused']);
            return;
        }

        $phones   = $aud->phones($c->audience, $c->category);
        $targeted = count($phones);
        $caption  = $this->compose($c);
        $gateway  = $wa->forTenant($t);

        $stats = is_array($c->stats) ? $c->stats : [];
        $stats['targeted']   = $targeted;
        $stats['started_at'] = $stats['started_at'] ?? $now->toIso8601String();
        $c->update(['status' => 'sending', 'stats' => $stats]);

        $runCount = 0; $batchN = 0; $hitCap = false;
        foreach ($phones as $to) {
            if ($runCount >= self::PER_RUN) break;          // hand off to a fresh invocation

            $recipient = Idempotency::recipient((string) $to);
            if (! CampaignMessage::claim($t->id, $c->id, $recipient)) {
                continue;                                    // already handled in a prior run
            }

            try {
                if ($c->image_url) {
                    $gateway->sendImage($t->whatsapp_instance, $to, $c->image_url, $caption);
                } else {
                    $gateway->sendText($t->whatsapp_instance, $to, $caption);
                }
                MessageLog::record($t->id, $to, $t->whatsapp_instance, 'out', 'system', '[campaign] ' . $caption);
                CampaignMessage::markSent($c->id, $recipient, null, 'sent');
            } catch (\Throwable $e) {
                CampaignMessage::markSent($c->id, $recipient, null, 'failed');
            }

            $runCount++; $batchN++; $allow--;
            if ($allow <= 0) { $hitCap = true; break; }      // daily cap reached mid-run

            // Pace: short jitter per message, a longer breather after each batch.
            if ($batchN % $batchSize === 0) {
                sleep($batchPause);
            } else {
                sleep(random_int($minD, $maxD));
            }
        }

        // Refresh totals for the dashboard.
        $sent   = CampaignMessage::where('campaign_id', $c->id)->where('status', 'sent')->count();
        $failed = CampaignMessage::where('campaign_id', $c->id)->where('status', 'failed')->count();
        $done   = $sent + $failed;
        $stats['sent'] = $sent; $stats['failed'] = $failed;

        if ($done >= $targeted) {
            $stats['finished_at'] = now()->toIso8601String();
            $c->update(['status' => 'sent', 'stats' => $stats]);
            return;
        }

        if ($hitCap) {
            // Out of today's allowance - resume tomorrow.
            $c->update(['status' => 'paused', 'stats' => $stats]);
            return;
        }

        // Hit the per-run cap but still within today's allowance - continue shortly.
        $c->update(['status' => 'sending', 'stats' => $stats]);
        self::dispatch($this->tenantId, $this->campaignId)
            ->delay(now()->addSeconds(random_int($minD, $maxD)));
    }

    /** True when the current hour is inside the nightly quiet window. */
    private function inQuietHours(int $hour, int $start, int $end): bool
    {
        if ($start === $end) return false;            // disabled
        return $start < $end
            ? ($hour >= $start && $hour < $end)       // same-day window
            : ($hour >= $start || $hour < $end);      // wraps past midnight (e.g. 21->8)
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
