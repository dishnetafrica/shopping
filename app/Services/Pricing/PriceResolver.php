<?php

namespace App\Services\Pricing;

use App\Models\CustomerProfile;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

/**
 * v4.9 — Dealer Programme 4a: THE one place dealer pricing lives (spec §5).
 *
 * compute() is pure and harness-tested. Ladder: per-product override beats tier percentage;
 * the margin floor beats everything — a resolved price NEVER goes below cost × (1 + floor%),
 * and never above retail. Callers: the bot's OrderCalculator today (4a); the storefront authed
 * feed and quotation surfaces join in 4b — all through this class, pricing logic nowhere else.
 *
 * Tier defaults come from settings (Settings → Storefront → Dealer programme):
 *   dealer_pct (default 10) · wholesale_pct (default 15) · dealer_min_margin_pct (default 5).
 * All schema-guarded: before `php artisan migrate` runs, every phone resolves as retail.
 */
final class PriceResolver
{
    private static ?bool $hasCols = null;

    /**
     * Pure pricing ladder. Returns [price, source] where source ∈ retail|tier|override|clamped.
     *
     * @param float      $retail   the catalogue selling price (source of truth)
     * @param float|null $cost     cost price (null/0 = unknown → floor cannot apply)
     * @param float|null $override products.dealer_price (null/0 = none)
     * @param float|null $pct      tier/customer discount percent (null/0 = none)
     * @param float      $floorPct minimum margin over cost, e.g. 5.0
     */
    public static function compute(float $retail, ?float $cost, ?float $override, ?float $pct, float $floorPct): array
    {
        if ($retail <= 0) return [$retail, 'retail'];            // price-on-request stays untouched

        $price = $retail;
        $source = 'retail';
        if ($override !== null && $override > 0) {
            $price = round($override, 2);
            $source = 'override';
        } elseif ($pct !== null && $pct > 0) {
            $price = round($retail * (1 - min(90.0, $pct) / 100), 2);
            $source = 'tier';
        }

        if ($source !== 'retail' && $cost !== null && $cost > 0) {
            $min = round($cost * (1 + max(0.0, $floorPct) / 100), 2);
            if ($price < $min) {                                  // never sell below the floor
                $price = min($retail, $min);
                $source = 'clamped';
            }
        }
        if ($price > $retail) {                                   // a dealer never pays MORE than retail
            $price = $retail;
            $source = 'retail';
        }

        return [$price, $source];
    }

    /** The customer's dealer profile — null for ordinary customers or before the migration ran. */
    public static function profileFor(Tenant $tenant, ?string $phone): ?CustomerProfile
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($digits === '') return null;
        if (self::$hasCols === null) {
            try { self::$hasCols = Schema::hasColumn('customer_profiles', 'dealer_tier'); }
            catch (\Throwable $e) { self::$hasCols = false; }
        }
        if (! self::$hasCols) return null;
        try {
            $p = CustomerProfile::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('phone', $digits)->first();
            return ($p && in_array((string) $p->dealer_tier, ['dealer', 'wholesale'], true)) ? $p : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Effective discount percent for a dealer profile (customer override, else the tier default setting). */
    public static function pctFor(Tenant $tenant, CustomerProfile $p): float
    {
        $own = $p->dealer_discount_pct;
        if ($own !== null && (float) $own > 0) return (float) $own;
        return (string) $p->dealer_tier === 'wholesale'
            ? (float) $tenant->setting('wholesale_pct', 15)
            : (float) $tenant->setting('dealer_pct', 10);
    }

    public static function floorFor(Tenant $tenant): float
    {
        return (float) $tenant->setting('dealer_min_margin_pct', 5);
    }
}
