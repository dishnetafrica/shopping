<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

use App\Models\CommCampaign;
use App\Models\CommMessage;
use App\Models\CommSuppression;
use App\Models\Tenant;
use App\Services\Marketing\CampaignExclusions;
use Illuminate\Support\Facades\DB;

/**
 * The single choke point every marketing send passes through — requirement 7.
 *
 * Returns a skip REASON (or null = safe to send). The reason is recorded on the comm_messages row so
 * a skipped recipient is auditable, never silent.
 *
 * Consent/opt-in is enforced upstream in the audience SQL (only opted-in, not-opted-out profiles enter
 * the funnel), so this guard is the last-second, time-sensitive layer: campaign still running, not
 * opted-out-since, not suppressed/staff, within frequency, under the daily cap, not already sent. It
 * runs at enqueue AND again immediately before the wire (the `send` stage), because opt-out and the
 * campaign being paused can both happen in between.
 *
 * Rate limiting (check 9) is structural — the sweep enqueues at most `throttle_per_minute` per pass —
 * so it is not a per-candidate test here.
 */
final class CampaignSafetyGuard
{
    public const OK               = null;
    public const R_NOT_RUNNING    = 'campaign_not_running';
    public const R_OPTED_OUT      = 'opted_out';
    public const R_SUPPRESSED     = 'suppressed';
    public const R_FREQUENCY      = 'frequency_capped';
    public const R_DAILY_LIMIT    = 'daily_limit';
    public const R_DUPLICATE      = 'duplicate';
    public const R_BAD_NUMBER     = 'invalid_number';

    /**
     * @param  string  $stage  'enqueue' (full check) · 'send' (last-second re-check) · 'preview'
     *                         (dry-run: same exclusion checks as enqueue, but WITHOUT the "is the
     *                         campaign running" gate, since a preview is run against a draft campaign).
     * @return string|null     a reason constant, or null when the send is allowed.
     */
    public static function blockedReason(Tenant $tenant, CommCampaign $campaign, string $phone, string $stage = 'enqueue'): ?string
    {
        $digits = CommSuppression::normalise($phone, 'whatsapp');
        if ($digits === '' || strlen($digits) < 7) {
            return self::R_BAD_NUMBER;
        }

        // Campaign must still be live. At send it must be SENDING; a paused/cancelled campaign must not
        // fire an already-queued row. A preview deliberately skips this gate — it dry-runs a draft.
        if ($stage !== 'preview') {
            $liveStatuses = $stage === 'send'
                ? [CommCampaign::ST_SENDING]
                : [CommCampaign::ST_SCHEDULED, CommCampaign::ST_SENDING];
            if (! in_array($campaign->status, $liveStatuses, true)) {
                return self::R_NOT_RUNNING;
            }
        }

        // Opt-out is authoritative in comm_suppressions (reason 'unsubscribed'); other exclusions
        // (staff numbers, the settings list) are reported as generic suppression.
        if (CommSuppression::isSuppressed((int) $tenant->id, $digits, 'whatsapp')) {
            return self::R_OPTED_OUT;
        }
        if (CampaignExclusions::for($tenant)->blocks($digits)) {
            return self::R_SUPPRESSED;
        }

        // Daily tenant cap. At SEND it is the hard guarantee (messages actually sent today < limit);
        // at enqueue it also accounts for in-flight rows so pending can't pile up past the cap.
        $overCap = $stage === 'send'
            ? ! DailyLimiter::underSendCap($tenant)
            : DailyLimiter::remaining($tenant) <= 0;
        if ($overCap) {
            return self::R_DAILY_LIMIT;
        }

        // Frequency + idempotency are settled at enqueue; re-checking them at send would risk a
        // campaign blocking its own in-flight row. A preview reports them too (same funnel as enqueue).
        if ($stage === 'enqueue' || $stage === 'preview') {
            if (FrequencyGuard::recentlyMarketed($tenant, $digits)) {
                return self::R_FREQUENCY;
            }
            if (self::alreadyQueued($campaign, $digits)) {
                return self::R_DUPLICATE;
            }
        }

        return self::OK;
    }

    /** Already a comm_messages row for this campaign + recipient (any status). One send per recipient. */
    private static function alreadyQueued(CommCampaign $campaign, string $digits): bool
    {
        return DB::table('comm_messages')
            ->where('tenant_id', $campaign->tenant_id)
            ->where('related_type', 'campaign')->where('related_id', $campaign->id)
            ->where('channel', 'whatsapp')->where('to_email', $digits)
            ->exists();
    }
}
