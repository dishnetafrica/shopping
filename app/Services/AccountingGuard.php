<?php

namespace App\Services;

use App\Models\Tenant;

/** Period lock: refuse create/edit/void of entries dated on/before the tenant's lock date. */
class AccountingGuard
{
    /** The lock cutoff (Y-m-d) for a tenant, or null if none. */
    public static function lockDate(int $tenantId): ?string
    {
        try {
            $d = optional(Tenant::find($tenantId))->setting('period_lock_date');
            return $d ? (string) $d : null;
        } catch (\Throwable $e) { return null; }
    }

    /** True if $whenDate (a date/datetime) falls in the locked period. */
    public static function isLocked(int $tenantId, $whenDate): bool
    {
        $lock = self::lockDate($tenantId);
        if (! $lock) return false;
        try {
            $when = $whenDate instanceof \DateTimeInterface ? $whenDate : new \DateTime((string) $whenDate);
            return $when <= new \DateTime($lock . ' 23:59:59');
        } catch (\Throwable $e) { return false; }
    }
}
