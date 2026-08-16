<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

use App\Models\CommSuppression;
use App\Models\CustomerProfile;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Explicit marketing consent for WhatsApp broadcasts.
 *
 * The rule the whole engine rests on: a prior inbound WhatsApp message is NOT consent. A customer is
 * eligible only after an explicit opt-in, and stops being eligible the moment they opt out.
 *
 * Two representations of opt-out, written together, each with one job:
 *   - `comm_suppressions` (channel='whatsapp') is the AUTHORITATIVE do-not-contact list the send path
 *     already checks. Keyed by digits, so it works regardless of how a profile stored its phone.
 *   - `customer_profiles.marketing_opted_out_at` is the audit/display copy (best-effort profile match).
 *
 * Re-enabling after an opt-out is deliberately NOT automatic (requirement 6): only `adminReEnable()`
 * lifts a suppression, and only an authorized caller reaches it.
 */
final class MarketingConsent
{
    /** Record an explicit opt-in (bot/checkout/panel). Never clears a prior opt-out — that needs admin. */
    public static function recordOptIn(Tenant $tenant, string $phone, string $source): void
    {
        $p = self::findProfile((int) $tenant->id, $phone);
        if ($p && $p->marketing_opted_in_at === null) {
            $p->marketing_opted_in_at  = now();
            $p->marketing_opt_in_source = mb_substr($source, 0, 40);
            $p->save();
        }
    }

    /**
     * Opt a number out. Authoritative suppression row first (always succeeds, keyed by digits), then a
     * best-effort audit stamp on the profile. Idempotent: a second STOP is a no-op.
     */
    public static function optOut(int $tenantId, string $phone, string $source): void
    {
        $digits = CommSuppression::normalise($phone, 'whatsapp');
        if ($digits === '') {
            return;
        }

        DB::transaction(function () use ($tenantId, $digits, $source) {
            CommSuppression::add($tenantId, $digits, 'unsubscribed', mb_substr($source, 0, 60), 'whatsapp');

            $p = self::findProfile($tenantId, $digits);
            if ($p && $p->marketing_opted_out_at === null) {
                $p->marketing_opted_out_at   = now();
                $p->marketing_opt_out_source = mb_substr($source, 0, 40);
                $p->save();
            }
        });
    }

    /**
     * Authorized re-enable — the ONLY path that lifts an opt-out. Removes the suppression row, clears
     * the profile opt-out stamp, and records a fresh explicit opt-in.
     */
    public static function adminReEnable(int $tenantId, string $phone, string $source = 'admin'): void
    {
        $digits = CommSuppression::normalise($phone, 'whatsapp');
        if ($digits === '') {
            return;
        }

        DB::transaction(function () use ($tenantId, $digits, $source) {
            DB::table('comm_suppressions')
                ->where('tenant_id', $tenantId)->where('channel', 'whatsapp')->where('address', $digits)
                ->delete();

            $p = self::findProfile($tenantId, $digits);
            if ($p) {
                $p->marketing_opted_out_at   = null;
                $p->marketing_opt_out_source = null;
                $p->marketing_opted_in_at    = $p->marketing_opted_in_at ?: now();
                $p->marketing_opt_in_source  = mb_substr($source, 0, 40);
                $p->save();
            }
        });
    }

    /**
     * Eligible right now: explicitly opted in, not opted out, and not on the do-not-contact list.
     * Suppression is checked because an opt-out lands there first (and works even when the profile
     * stored its phone in a different format).
     */
    public static function isEligible(Tenant $tenant, string $phone): bool
    {
        $digits = CommSuppression::normalise($phone, 'whatsapp');
        if ($digits === '' || strlen($digits) < 7) {
            return false;
        }
        if (CommSuppression::isSuppressed((int) $tenant->id, $digits, 'whatsapp')) {
            return false;
        }
        $p = self::findProfile((int) $tenant->id, $digits);

        return $p !== null && $p->marketingEligible();
    }

    /**
     * Find a profile by phone without depending on stored formatting. Narrow by the last-9-digit tail
     * (survives country-code differences), then confirm on full digits in PHP. Portable (no DB regex).
     */
    private static function findProfile(int $tenantId, string $phone): ?CustomerProfile
    {
        $digits = CommSuppression::normalise($phone, 'whatsapp');
        if ($digits === '') {
            return null;
        }
        $tail = substr($digits, -9);

        $candidates = CustomerProfile::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('phone', 'like', '%' . $tail)
            ->limit(25)->get();

        foreach ($candidates as $cand) {
            if (CommSuppression::normalise((string) $cand->phone, 'whatsapp') === $digits) {
                return $cand;
            }
        }

        return null;
    }
}
