<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * The default module-flag map for a tenant (Roadmap E1.4).
 *
 * Standard modules (sales/purchases/inventory/finance/reports/whatsapp) are enabled for
 * everyone — they match today's behaviour and menuFor() already defaults them on.
 *
 * The one non-trivial flag is `restaurant` (default OFF since E1.3). This rule preserves
 * it for tenants that actually use restaurant features, WITHOUT re-introducing the
 * "restaurant on by default" clutter for the wholesale-trading tenants the product targets:
 *
 *   - A TRADING tenant (order_system_enabled = true) gets restaurant ON only when it shows an
 *     EXPLICIT restaurant signal (vertical restaurant/snacks, restaurant_mode, a configured
 *     thali, or an explicit feature_* override). The bare grocery DEFAULT does NOT count —
 *     that default-on visibility is exactly the clutter E1.3 removes.
 *   - A NON-TRADING tenant keeps restaurant ON whenever any restaurant feature is currently
 *     visible (so a grocery/restaurant/snacks shop loses nothing).
 *
 * Reuses App\Support\Vertical so the flag is derived from the SAME logic the panel uses to
 * show/hide those features (single source of truth).
 */
class ModuleDefaults
{
    /** Keys that are always enabled by default. */
    public const STANDARD = ['sales', 'purchases', 'inventory', 'finance', 'reports', 'whatsapp'];

    /** @return array<string,bool> full module map for this tenant */
    public static function forTenant(Tenant $t): array
    {
        $map = [];
        foreach (self::STANDARD as $m) {
            $map[$m] = true;
        }
        $map['restaurant'] = self::restaurant($t);

        return $map;
    }

    /** Whether the restaurant module should be ON for this tenant. */
    public static function restaurant(Tenant $t): bool
    {
        $s = is_array($t->settings) ? $t->settings : [];

        // Currently-visible restaurant features (same computation the panel uses).
        $kitchen = Vertical::shows($t, 'kitchen_board');
        $pos     = Vertical::shows($t, 'pos');
        $riders  = Vertical::shows($t, 'riders');

        $thaliCfg        = is_array($s['thali'] ?? null) ? $s['thali'] : [];
        $thaliConfigured = ! empty($thaliCfg['enabled']) || ! empty($thaliCfg['days']);
        $thali           = array_key_exists('feature_thali', $s) ? (bool) $s['feature_thali'] : $thaliConfigured;

        $anyVisible = $kitchen || $pos || $riders || $thali;

        // An explicit, positive restaurant/food signal (not the grocery default).
        $vertical = $t->setting('vertical', null);
        $explicit = in_array($vertical, [Vertical::RESTAURANT, Vertical::SNACKS], true)
            || (bool) $t->setting('restaurant_mode', false)
            || $thali
            || ($t->setting('feature_kitchen_board', null) === true)
            || ($t->setting('feature_pos', null) === true)
            || ($t->setting('feature_riders', null) === true);

        $isTrading = (bool) $t->setting('order_system_enabled', false);

        // Trading tenants: only explicit signals keep restaurant on (clears default clutter).
        // Everyone else: preserve whatever restaurant features are currently visible.
        return $isTrading ? $explicit : ($anyVisible || $explicit);
    }
}
