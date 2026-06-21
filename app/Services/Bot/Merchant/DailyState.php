<?php
namespace App\Services\Bot\Merchant;

use App\Models\Tenant;

/**
 * Day-scoped shop state stored in tenant settings.daily_state. Auto-resets when the
 * stored date != today (so "today only" semantics need no cron). The reset logic is
 * pure/unit-tested; read/write through the tenant is a thin wrapper.
 */
class DailyState
{
    public const EMPTY = [
        'date' => null, 'unavailable' => [], 'specials' => [], 'menu' => [],
        'hours' => ['open' => null, 'close' => null, 'closed' => false],
        'notice' => [], 'notes' => [],
    ];

    /** Pure: return $state if it is for $today, otherwise a fresh empty state stamped today. */
    public static function fresh(?array $state, string $today): array
    {
        if (is_array($state) && ($state['date'] ?? null) === $today) {
            return $state + self::EMPTY;                       // backfill any missing keys
        }
        return ['date' => $today] + self::EMPTY;
    }

    public static function get(Tenant $tenant): array
    {
        return self::fresh($tenant->setting('daily_state'), self::today());
    }

    public static function put(Tenant $tenant, array $state): void
    {
        $state['date'] = self::today();
        $tenant->putSetting('daily_state', $state);
    }

    private static function today(): string
    {
        return date('Y-m-d');
    }
}
