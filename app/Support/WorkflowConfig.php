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
    /** Default flow (Keshwala Rwanda): ordered stages, each with role + SLA minutes.
     *  `checklist` holds optional sub-actions for the stage (the task-template hedge);
     *  `exceptions` limits which blockers the UI offers (defaults to EXCEPTION_TYPES). */
    public const DEFAULT_STAGES = [
        ['key' => 'sales',    'title' => 'Sales submitted',    'role' => 'salesperson',    'task' => 'Submit order',            'sla' => 30,   'upload' => false,
            'checklist' => ['Capture customer details', 'Add products & quantities', 'Confirm delivery need']],
        ['key' => 'office',   'title' => 'Office review',      'role' => 'office_manager', 'task' => 'Review & approve order',  'sla' => 120,  'upload' => false,
            'checklist' => ['Check order', 'Correct prices', 'Approve or flag missing docs']],
        ['key' => 'gov',      'title' => 'Government invoice',  'role' => 'invoice_officer','task' => 'Create Rwanda invoice',   'sla' => 240,  'upload' => true,
            'checklist' => ['Login to Rwanda portal', 'Generate invoice', 'Verify VAT', 'Upload PDF', 'Enter invoice number'],
            'exceptions' => ['portal_down', 'missing_doc', 'other']],
        ['key' => 'store',    'title' => 'Store preparation',  'role' => 'store_manager',  'task' => 'Reserve & pack goods',    'sla' => 120,  'upload' => false,
            'checklist' => ['Reserve stock', 'Pack', 'Print invoice'],
            'exceptions' => ['out_of_stock', 'other']],
        ['key' => 'delivery', 'title' => 'Delivery',           'role' => 'driver',         'task' => 'Deliver & collect proof', 'sla' => 1440, 'upload' => true,
            'checklist' => ['Load', 'Deliver', 'Collect signature', 'Upload photo']],
    ];

    /** Exception types the UI can raise. reassign-types carry a target role. */
    public const EXCEPTION_TYPES = [
        'out_of_stock' => ['label' => 'Out of stock',        'reassign' => 'procurement'],
        'portal_down'  => ['label' => 'Government portal down','reassign' => null],
        'missing_doc'  => ['label' => 'Missing document',     'reassign' => null],
        'other'        => ['label' => 'Other blocker',        'reassign' => null],
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

    /** Sub-actions for a stage as a fresh checklist snapshot: [['label'=>..,'done'=>false], ...]. */
    public static function checklistFor(Tenant $t, string $stageKey): array
    {
        $stage = self::stage($t, $stageKey);
        $items = is_array($stage['checklist'] ?? null) ? $stage['checklist'] : [];
        return array_values(array_map(fn ($label) => ['label' => (string) $label, 'done' => false], $items));
    }

    /** Allowed exception options for a stage: type => [label, reassign]. */
    public static function exceptionsFor(Tenant $t, string $stageKey): array
    {
        $stage = self::stage($t, $stageKey);
        $keys  = is_array($stage['exceptions'] ?? null) && $stage['exceptions']
            ? $stage['exceptions'] : array_keys(self::EXCEPTION_TYPES);
        $out = [];
        foreach ($keys as $k) if (isset(self::EXCEPTION_TYPES[$k])) $out[$k] = self::EXCEPTION_TYPES[$k];
        return $out;
    }
}
