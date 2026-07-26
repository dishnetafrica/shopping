<?php declare(strict_types=1);

namespace App\Kernel\Events;

/**
 * The work-order vocabulary (Asset Lifecycle · Phase 2 · S5). AUDIT events on the spine — the
 * work_orders.status column is the source of truth. Completion emits a TYPE-specific event
 * (InstallationCompleted for installs — the name the rest of the system listens for). Pure contract.
 */
final class WorkOrderEvents
{
    /** The kinds of field work one generic job entity covers. */
    public const TYPES = ['installation', 'service', 'repair', 'inspection'];

    /** The lifecycle a job moves through. */
    public const STATUSES = ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled'];

    public const CREATED = 'WorkOrderCreated';

    /** Event emitted when a job transitions INTO each status (completion uses the type map below). */
    public const ON_STATUS = [
        'scheduled'   => 'WorkOrderScheduled',
        'in_progress' => 'WorkOrderStarted',
        'completed'   => 'WorkOrderCompleted',
        'cancelled'   => 'WorkOrderCancelled',
    ];

    /** Completion event by job type — installs emit InstallationCompleted (per the Phase 2 brief). */
    public const COMPLETED_BY_TYPE = [
        'installation' => 'InstallationCompleted',
        'service'      => 'ServiceVisitCompleted',
        'repair'       => 'RepairCompleted',
        'inspection'   => 'InspectionCompleted',
    ];

    public static function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function completedEventFor(string $type): string
    {
        return self::COMPLETED_BY_TYPE[$type] ?? 'WorkOrderCompleted';
    }
}
