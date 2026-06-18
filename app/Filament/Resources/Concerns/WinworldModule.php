<?php
namespace App\Filament\Resources\Concerns;

/**
 * Shows Win World production resources only for a tenant that has the
 * module enabled (tenant setting `module_winworld`). Keeps these screens
 * out of Pal's and every other grocery tenant.
 */
trait WinworldModule
{
    public static function wwEnabled(): bool
    {
        $t = auth()->user()?->tenant;
        return $t ? (bool) $t->setting('module_winworld', false) : false;
    }

    public static function shouldRegisterNavigation(): bool { return static::wwEnabled(); }
    public static function canViewAny(): bool { return static::wwEnabled(); }
    public static function canAccess(): bool { return static::wwEnabled(); }
}
