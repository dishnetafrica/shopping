<?php
namespace App\Filament\Resources\Concerns;

/**
 * Win World production resources are RETIRED from the consumer panel. They are hidden
 * for every tenant — no tenant in the grocery/restaurant/snacks product needs the MES.
 *
 * The resources are kept in the repo (not deleted) so a future manufacturing tenant is a
 * one-line restore, not a rewrite. To bring it back, gate wwEnabled() on a tenant
 * setting again (e.g. $t->setting('module_winworld', false)) or an internal flag.
 */
trait WinworldModule
{
    public static function wwEnabled(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool { return static::wwEnabled(); }
    public static function canViewAny(): bool { return static::wwEnabled(); }
    public static function canAccess(): bool { return static::wwEnabled(); }
}
