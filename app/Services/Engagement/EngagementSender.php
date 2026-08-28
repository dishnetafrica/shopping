<?php declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\CustomerProfile;
use App\Models\EngagementCandidate;
use App\Models\Tenant;
use App\Services\Comms\Marketing\DailyLimiter;
use App\Services\Comms\Marketing\FrequencyGuard;
use App\Services\Comms\Marketing\MarketingConsent;
use App\Services\Comms\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2b — the PACED, guarded sender for the daily re-engagement list. Off by default.
 *
 * It sends only to customers the daily plan already chose (chosen = true, not yet sent today), and
 * re-runs EVERY safety rule at send time — because state drifts between planning and sending:
 *   · MarketingConsent::isEligible ...... opted-in AND not opted-out AND not suppressed (re-checked now)
 *   · FrequencyGuard::recentlyMarketed .. no re-marketing inside marketing_min_interval_days
 *   · DailyLimiter::remaining ........... the shared per-tenant marketing daily cap
 *   · engagement_send_daily_cap ......... the engine's own, tighter ceiling (start ~15)
 *   · quality auto-pause ................ stop for the day if too many engine sends are failing
 *
 * Every message goes through the existing Comms spine (WhatsAppService::queueRaw → dispatcher →
 * Evolution sendText) tagged related_type='campaign' with meta.source='engagement', so it counts
 * against the SAME daily cap and frequency ledger as any other campaign — one shared ceiling, not a
 * second bucket. It stamps last_whatsapp_at so the cooldown holds, and records the send on the
 * candidate row. No approved templates (Evolution can't); the copy is fixed by EngagementMessage.
 */
final class EngagementSender
{
    public const CAP_DEFAULT     = 15;   // engine's own daily ceiling
    public const BATCH_DEFAULT   = 2;    // per tick
    private const QUALITY_MIN     = 8;   // don't judge quality below this many sends
    private const QUALITY_MAX_FAIL = 0.40;

    public function __construct(private ?WhatsAppService $wa = null)
    {
        $this->wa ??= app(WhatsAppService::class);
    }

    /**
     * Send up to $limit messages from today's chosen list. Returns a per-tick tally.
     *
     * @return array{sent:int, blocked:int, skipped:int, failed:int, capped:bool, paused:bool}
     */
    public function sendTick(Tenant $tenant, int $limit, ?Carbon $date = null): array
    {
        $date  ??= Carbon::today();
        $planDate = $date->toDateString();
        $out = ['sent' => 0, 'blocked' => 0, 'skipped' => 0, 'failed' => 0, 'capped' => false, 'paused' => false];

        if ($this->qualityPaused($tenant, $planDate)) {
            $out['paused'] = true;
            return $out;
        }

        $cap        = max(0, (int) $tenant->setting('engagement_send_daily_cap', self::CAP_DEFAULT));
        $engineLeft = $cap - $this->sentToday($tenant, $planDate);
        $budget     = min(max(0, $engineLeft), DailyLimiter::remaining($tenant), max(0, $limit));
        if ($budget <= 0) {
            $out['capped'] = true;
            return $out;
        }

        $shop = trim((string) ($tenant->name ?? '')) ?: 'us';

        $rows = EngagementCandidate::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('plan_date', $planDate)
            ->where('chosen', true)->whereNull('send_status')
            ->orderByDesc('score')->limit($budget + 10)->get();   // +buffer: some will be skipped, not sent

        foreach ($rows as $cand) {
            if ($out['sent'] >= $budget) {
                break;
            }
            $phone = (string) $cand->phone;

            // Re-run consent + frequency AT SEND TIME (state can change after planning).
            if (! MarketingConsent::isEligible($tenant, $phone)) {
                $this->mark($cand, 'blocked', 'not eligible at send');
                $out['blocked']++;
                continue;                              // costs no budget — nothing left the building
            }
            if (FrequencyGuard::recentlyMarketed($tenant, $phone)) {
                $this->mark($cand, 'skipped', 'frequency cap');
                $out['skipped']++;
                continue;
            }

            $text = EngagementMessage::compose([
                'name' => $cand->name, 'shop' => $shop,
                'product' => $cand->product_name, 'campaign_type' => $cand->campaign_type,
            ]);

            try {
                $m = $this->wa->queueRaw($tenant, $phone, $text, [
                    'related_type' => 'campaign',       // shares the marketing cap + frequency ledger
                    'to_name'      => $cand->name,
                    'meta'         => ['source' => 'engagement', 'candidate_id' => $cand->id, 'campaign_type' => $cand->campaign_type],
                ]);
            } catch (\Throwable $e) {
                $this->mark($cand, 'failed', mb_substr($e->getMessage(), 0, 118));
                $out['failed']++;
                continue;
            }

            $status = (string) $m->status;
            if (in_array($status, ['failed', 'cancelled'], true)) {
                // persist() refused it (bad number / suppressed / no instance) — not counted as a send.
                $this->mark($cand, $status === 'cancelled' ? 'blocked' : 'failed', mb_substr((string) $m->last_error, 0, 118), $m->id);
                $out[$status === 'cancelled' ? 'blocked' : 'failed']++;
                continue;
            }

            // Queued for delivery. Stamp the send on the candidate + the profile (so the cooldown holds).
            $this->mark($cand, 'queued', null, $m->id);
            if ($cand->customer_profile_id) {
                try {
                    CustomerProfile::withoutGlobalScopes()->where('id', $cand->customer_profile_id)
                        ->update(['last_whatsapp_at' => now()]);
                } catch (\Throwable $e) { /* cooldown stamp is best-effort */ }
            }
            $out['sent']++;

            if ($out['sent'] < $budget) {
                sleep(random_int(4, 9));               // jitter — never a metronome on an unofficial number
            }
        }

        return $out;
    }

    /** How many the ENGINE has queued/sent today (its own ceiling, separate from the shared cap). */
    public function sentToday(Tenant $tenant, string $planDate): int
    {
        return (int) EngagementCandidate::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('plan_date', $planDate)
            ->whereIn('send_status', ['queued', 'sent'])->count();
    }

    /** Stop for the day if too high a share of today's engine sends have failed at the provider. */
    public function qualityPaused(Tenant $tenant, string $planDate): bool
    {
        try {
            $ids = EngagementCandidate::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('plan_date', $planDate)
                ->whereNotNull('comm_message_id')->pluck('comm_message_id')->all();
            if (count($ids) < self::QUALITY_MIN) {
                return false;
            }
            $failed = (int) DB::table('comm_messages')->whereIn('id', $ids)
                ->whereIn('status', ['failed'])->count();

            return ($failed / max(1, count($ids))) > self::QUALITY_MAX_FAIL;
        } catch (\Throwable $e) {
            return false;                              // never block sending on a metrics query error
        }
    }

    private function mark(EngagementCandidate $cand, string $status, ?string $detail, ?int $commId = null): void
    {
        $cand->send_status = $status;
        $cand->send_detail = $detail;
        if ($commId !== null) {
            $cand->comm_message_id = $commId;
        }
        if (in_array($status, ['queued', 'sent'], true)) {
            $cand->sent_at = now();
        }
        $cand->save();
    }
}
