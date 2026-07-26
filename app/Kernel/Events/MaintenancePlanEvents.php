<?php declare(strict_types=1);

namespace App\Kernel\Events;

/**
 * The maintenance-plan vocabulary (Asset Lifecycle · Phase 2 · S6). AUDIT events on the spine — the
 * maintenance_plans row is the source of truth. Pure contract.
 */
final class MaintenancePlanEvents
{
    public const STATUSES = ['active', 'paused', 'ended'];

    public const CREATED      = 'MaintenancePlanCreated';
    public const VISIT_LOGGED = 'ServiceVisitLogged';
    public const ENDED        = 'MaintenancePlanEnded';

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}
