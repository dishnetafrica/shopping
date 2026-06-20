<?php
namespace App\Filament\Concerns;

use App\Support\Vertical;

/**
 * Vertical-aware navigation gate for Filament Resources and Pages.
 *
 * The consuming class sets:
 *     protected static string $verticalFeature = 'kitchen_board';
 * using one of the feature keys in App\Support\Vertical::MATRIX. The screen then
 * registers / is accessible only for tenants whose vertical (or an explicit feature_*
 * override) enables that feature.
 *
 * Mirrors the existing WinworldModule pattern, but reads the single `vertical` source of
 * truth instead of one ad-hoc boolean. canViewAny() is harmless/unused on Pages.
 */
trait VerticalGate
{
    public static function shouldRegisterNavigation(): bool
    {
        return static::verticalAllows();
    }

    public static function canViewAny(): bool
    {
        return static::verticalAllows();
    }

    public static function canAccess(): bool
    {
        return static::verticalAllows();
    }

    protected static function verticalAllows(): bool
    {
        $tenant = auth()->user()?->tenant;
        if (! $tenant) {
            return false;
        }

        $feature = property_exists(static::class, 'verticalFeature') ? static::$verticalFeature : null;

        return $feature ? Vertical::shows($tenant, $feature) : true;
    }
}
