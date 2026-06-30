<?php
namespace App\Support;

use App\Models\Tenant;

/**
 * Configuration + pure logic for the Task Engine (Operations Layer).
 *
 * An order moves through ordered STAGES. Each stage owns exactly one task at a time,
 * assigned to whoever currently fills that stage's ROLE (role -> user indirection, so
 * a staff change never touches the workflow). Tasks are completed, not status-changed;
 * completing one opens the next stage's task automatically.
 *
 * Everything here is deterministic and DB-free so it can be unit-tested in isolation.
 * Per-tenant overrides live in settings.workflow; the Keshwala government-invoice flow
 * is the built-in default.
 */
final class WorkflowConfig
{
    /** Default flow (Keshwala Rwanda): ordered stages, each with role + SLA minutes. */
    public const DEFAULT_STAGES = [
        ['key' => 'sales',    'title' => 'Sales submitted',    'role' => 'salesperson',    'task' => 'Submit order',            'sla' => 30,   'upload' => false],
        ['key' => 'office',   'title' => 'Office review',      'role' => 'office_manager', 'task' => 'Review & approve order',  'sla' => 120,  'upload' => false],
        ['key' => 'gov',      'title' => 'Government invoice',  'role' => 'invoice_officer','task' => 'Create Rwanda invoice',   'sla' => 240,  'upload' => true],
        ['key' => 'store',    'title' => 'Store preparation',  'role' => 'store_manager',  'task' => 'Reserve & pack goods',    'sla' => 120,  'upload' => false],
        ['key' => 'delivery', 'title' => 'Delivery',           'role' => 'driver',         'task' => 'Deliver & collect proof', 'sla' => 1440, 'upload' => true],
    ];

    /** Roles a tenant assigns people to. Label per key for the UI. */
    public const ROLE_LABELS = [
        'salesperson'     => 'Salesperson',
        'office_manager'  => 'Office manager',
        'invoice_officer' => 'Invoice officer',
        'store_manager'   => 'Store manager',
        'driver'          => 'Driver',
        'procurement'     => 'Procurement',
    ];

    public static function enabled(Tenant $t): bool
    {
        return (bool) $t->setting('workflow_enabled', false);
    }

    /** Ordered stage list for a tenant (override via settings.workflow.stages, else default). */
    public static function stages(Tenant $t): array
    {
        $custom = $t->setting('workflow.stages', null);
        $stages = is_array($custom) && $custom ? $custom : self::DEFAULT_STAGES;
        return array_values(array_filter($stages, fn ($s) => ! empty($s['key']) && ! empty($s['role'])));
    }

    public static function stage(Tenant $t, string $key): ?array
    {
        foreach (self::stages($t) as $i => $s) {
            if (($s['key'] ?? null) === $key) return $s + ['_index' => $i];
        }
        return null;
    }

    public static function firstStage(Tenant $t): ?array
    {
        $s = self::stages($t);
        return $s ? ($s[0] + ['_index' => 0]) : null;
    }

    /** The stage after $key, or null if $key is the last (order then completes). */
    public static function nextStage(Tenant $t, string $key): ?array
    {
        $stages = self::stages($t);
        foreach ($stages as $i => $s) {
            if (($s['key'] ?? null) === $key) {
                return isset($stages[$i + 1]) ? ($stages[$i + 1] + ['_index' => $i + 1]) : null;
            }
        }
        return null;
    }

    /** role_key => [user_id, ...] from settings.workflow.roles. */
    public static function roleMap(Tenant $t): array
    {
        $map = (array) $t->setting('workflow.roles', []);
        $out = [];
        foreach ($map as $role => $users) {
            $ids = array_values(array_filter(array_map('intval', (array) $users)));
            if ($ids) $out[$role] = $ids;
        }
        return $out;
    }

    /**
     * Pick the owner for a role: the candidate user with the fewest open tasks
     * (fair load-balancing, deterministic, no pointer state). $openCounts is
     * user_id => open-task-count. Returns null if the role has nobody assigned.
     */
    public static function pickOwner(array $candidateUserIds, array $openCounts): ?int
    {
        $candidateUserIds = array_values(array_filter(array_map('intval', $candidateUserIds)));
        if (! $candidateUserIds) return null;
        $best = null; $bestLoad = PHP_INT_MAX;
        foreach ($candidateUserIds as $uid) {
            $load = (int) ($openCounts[$uid] ?? 0);
            if ($load < $bestLoad) { $bestLoad = $load; $best = $uid; }
        }
        return $best;
    }

    /**
     * Effective due time = assigned + SLA + any time the task spent paused.
     * Pausing for an external blocker (portal down) must not count against the owner.
     */
    public static function dueAt(\DateTimeInterface $assignedAt, int $slaMinutes, int $pausedMinutes = 0): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::instance(\Illuminate\Support\Carbon::parse($assignedAt))
            ->addMinutes(max(0, $slaMinutes) + max(0, $pausedMinutes));
    }

    /** Overdue only if actively being worked (not paused/exception) and past due. */
    public static function isOverdue(string $status, \DateTimeInterface $dueAt, ?\DateTimeInterface $now = null): bool
    {
        if (! in_array($status, ['pending', 'in_progress'], true)) return false;
        $now = $now ? \Illuminate\Support\Carbon::parse($now) : \Illuminate\Support\Carbon::now();
        return $now->greaterThan(\Illuminate\Support\Carbon::parse($dueAt));
    }

    public static function roleLabel(string $key): string
    {
        return self::ROLE_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
