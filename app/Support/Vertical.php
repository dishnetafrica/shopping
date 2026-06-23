<?php
namespace App\Support;

/**
 * Single source of truth for which surfaces a tenant sees, driven by its `vertical`
 * profile (grocery | restaurant | snacks) instead of scattered boolean flags.
 *
 * decide() is pure (no framework, no DB, no auth) and is what qa/vertical_visibility.php
 * exercises. shows() is the thin runtime wrapper that pulls the vertical + overrides off a
 * Tenant model via the standard $tenant->setting() API.
 *
 * Safety: every gated feature defaults OFF for a vertical that does not list it, so a
 * grocery tenant keeps its exact pre-vertical navigation. This layer is nav-visibility
 * ONLY — it never touches the bot, the order pipeline, or the tenantHasModifiers safety
 * gate. Legacy tenants with no `vertical` set are inferred from their old flags, so
 * nothing changes for them until an admin sets a vertical explicitly.
 */
class Vertical
{
    public const GROCERY    = 'grocery';
    public const RESTAURANT = 'restaurant';
    public const SNACKS     = 'snacks';
    public const MANUFACTURER = 'manufacturer';

    public const ALL = [self::GROCERY, self::RESTAURANT, self::SNACKS, self::MANUFACTURER];

    /**
     * feature key => list of verticals where it is shown BY DEFAULT.
     * A feature absent from this map is universal (shown to every vertical).
     * An explicit feature_<key> tenant setting (true/false) overrides the default,
     * which is how the "opt" cells in the matrix get turned on/off per tenant.
     */
    public const MATRIX = [
        'item_options'  => [self::RESTAURANT],
        'kitchen_board' => [self::RESTAURANT],
        'daily_thali'   => [self::SNACKS],
        'riders'        => [self::GROCERY, self::RESTAURANT, self::MANUFACTURER],
        'pos'           => [self::GROCERY, self::RESTAURANT, self::MANUFACTURER],
    ];

    /** Pretty labels for the admin select. */
    public const LABELS = [
        self::GROCERY      => 'Grocery',
        self::RESTAURANT   => 'Restaurant',
        self::SNACKS       => 'Snacks / advance booking',
        self::MANUFACTURER => 'Manufacturer / wholesale brand',
    ];

    /**
     * Pure decision: given a vertical, its per-feature overrides, and a feature key,
     * return whether the feature is visible. No DB, no auth — safe to unit test.
     */
    public static function decide(string $vertical, array $overrides, string $feature): bool
    {
        if (array_key_exists($feature, $overrides)) {
            return (bool) $overrides[$feature];
        }

        $on = self::MATRIX[$feature] ?? null;
        if ($on === null) {
            return true; // unknown feature => universal
        }

        return in_array($vertical, $on, true);
    }

    public static function isValid(?string $vertical): bool
    {
        return $vertical !== null && in_array($vertical, self::ALL, true);
    }

    /**
     * Resolve a tenant's vertical. Uses the explicit `vertical` setting when present and
     * valid; otherwise infers from legacy flags so pre-vertical tenants behave unchanged.
     */
    public static function of($tenant): string
    {
        $v = $tenant->setting('vertical', null);
        if (is_string($v) && self::isValid($v)) {
            return $v;
        }

        // Legacy inference (tenants created before the vertical field existed):
        if ($tenant->setting('restaurant_mode', false)) {
            return self::RESTAURANT;
        }
        if ($tenant->setting('feature_thali', false)) {
            return self::SNACKS;
        }

        return self::GROCERY;
    }

    /**
     * Per-feature overrides pulled from feature_* settings, plus the legacy Daily-Thali
     * toggle so existing thali tenants keep working regardless of their vertical.
     */
    public static function overrides($tenant): array
    {
        $out = [];

        foreach (array_keys(self::MATRIX) as $feature) {
            $val = $tenant->setting('feature_' . $feature, null);
            if ($val !== null) {
                $out[$feature] = (bool) $val;
            }
        }

        // Legacy: the existing settings.feature_thali toggle maps onto daily_thali.
        $thali = $tenant->setting('feature_thali', null);
        if ($thali !== null && ! array_key_exists('daily_thali', $out)) {
            $out['daily_thali'] = (bool) $thali;
        }

        return $out;
    }

    /** Runtime wrapper used by Filament gates (and available to the bot). */
    public static function shows($tenant, string $feature): bool
    {
        return self::decide(self::of($tenant), self::overrides($tenant), $feature);
    }
}
