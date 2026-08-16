<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

use App\Models\CommSuppression;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Cross-campaign per-customer frequency protection.
 *
 * Stops a customer being marketed to again inside `marketing_min_interval_days` (tenant setting,
 * default 3) — even when three separate campaigns each individually pass their own checks. Without
 * this, Monday/Tuesday/Wednesday campaigns would each send to the same person.
 *
 * Derived from the send ledger (comm_messages that actually went — status sent/delivered/read/replied),
 * not a denormalised timestamp, so there is a single source of truth and no drift. A provider-rejected
 * send never counts as "recently marketed" (it isn't status='sent'), which is why the dispatcher's
 * _ok fix matters here too.
 */
final class FrequencyGuard
{
    public const DEFAULT_MIN_DAYS = 3;

    /** These statuses mean the message left the building (so it "counts" against frequency). */
    private const SENT_STATES = ['sent', 'delivered', 'read', 'replied'];

    public static function minDays(Tenant $tenant): int
    {
        return max(0, (int) $tenant->setting('marketing_min_interval_days', self::DEFAULT_MIN_DAYS));
    }

    /** True if this number received a marketing WhatsApp within the tenant's minimum interval. */
    public static function recentlyMarketed(Tenant $tenant, string $phone): bool
    {
        $days = self::minDays($tenant);
        if ($days <= 0) {
            return false;                       // frequency protection disabled for this tenant
        }
        $digits = CommSuppression::normalise($phone, 'whatsapp');
        if ($digits === '') {
            return false;
        }

        return DB::table('comm_messages')
            ->where('tenant_id', $tenant->id)
            ->where('channel', 'whatsapp')
            ->where('related_type', 'campaign')
            ->where('to_email', $digits)
            ->whereIn('status', self::SENT_STATES)
            ->where('sent_at', '>=', now()->subDays($days))
            ->exists();
    }
}
