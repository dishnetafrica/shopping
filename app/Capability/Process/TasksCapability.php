<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Order;
use App\Models\OrderTask;
use App\Models\OrderTaskEvent;
use App\Models\User;
use App\Services\Ops\TaskEngine;
use App\Support\WorkflowConfig;

/**
 * Tasks (V3) — CRM-style job list: filterable (status / assignee / priority / due range),
 * with client + duration columns, bulk mark-done, PDF export and an Agenda view (the
 * Agenda toggle itself lives in the shell and works for any dated list).
 *
 * Managers see every task and can assign; staff see and close their own. All writes go
 * through the existing TaskEngine; order-workflow tasks advance stages on completion.
 */
final class TasksCapability implements ProcessCapability
{
    public function __construct(private TaskEngine $engine) {}

    public function key(): string { return 'tasks'; }

    // Everyone can see their own tasks; the Assign action is gated inside command().
    public function permission(): string { return 'menu:tasks'; }

    /** pending/offered → Open · in_progress → In progress · paused → Paused · exception → Blocked · completed → Closed */
    private function statusCell(OrderTask $x): array
    {
        return match ($x->status) {
            'completed'   => ['value' => 'Closed',      'badge' => 'ok'],
            'in_progress' => ['value' => 'In progress', 'badge' => 'info'],
            'paused'      => ['value' => 'Paused',      'badge' => 'default'],
            'exception'   => ['value' => 'Blocked',     'badge' => 'danger'],
            default       => ['value' => 'Open',        'badge' => 'warn'],
        };
    }

    private function durationText(OrderTask $x): string
    {
        $m = (int) $x->sla_minutes;
        if ($m <= 0) return '—';
        $h = intdiv($m, 60); $r = $m % 60;
        return trim(($h ? $h . ' hr' : '') . ($r ? ' ' . $r . ' min' : ''));
    }

    private function dueCell(OrderTask $x): array
    {
        if (! $x->due_at) return ['value' => '—', 'sort' => 0];
        $over = $x->isOverdue() && $x->status !== 'completed';
        return ['value' => $x->due_at->format('M j, H:i'), 'sort' => $x->due_at->timestamp, 'tone' => $over ? 'danger' : null];
    }

    private function rowButtons(OrderTask $x): array
    {
        $buttons = [];
        if ($x->status !== 'in_progress' && $x->status !== 'completed') {
            $buttons[] = ['title' => 'Start', 'icon' => 'bolt', 'post' => '/api/v1/workspace/tasks/' . $x->id . '/start'];
        }
        if ($x->status !== 'completed') {
            $buttons[] = ['title' => 'Done', 'icon' => 'check', 'tone' => 'ok', 'post' => '/api/v1/workspace/tasks/' . $x->id . '/done'];
        }
        return $buttons;
    }

    /** task_id → number of comment events, for the shown tasks (one grouped query). */
    private function commentCounts($tasks, int $tenantId): array
    {
        $ids = $tasks->pluck('id')->filter()->unique()->values()->all();
        if (! $ids) return [];
        return OrderTaskEvent::query()->where('tenant_id', $tenantId)
            ->whereIn('task_id', $ids)->where('event', 'comment')
            ->selectRaw('task_id, COUNT(*) as n')->groupBy('task_id')
            ->pluck('n', 'task_id')->toArray();
    }

    /**
     * The "Updates" cell: a 💬 comment count and, for closed tasks, who completed it. Lets a
     * manager scan activity without opening each task. Completion via the no-login link leaves
     * completed_by null, so we fall back to the assignee (whose personal link it was).
     */
    private function activityCell(OrderTask $x, array $names, array $comments): array
    {
        $n = (int) ($comments[$x->id] ?? 0);
        $chat = $n > 0 ? ('💬 ' . $n) : '';

        if ($x->status === 'completed') {
            $who = $names[$x->completed_by] ?? ($names[$x->owner_user_id] ?? '');
            $done = '✓ ' . ($who !== '' ? $who : 'done');
            $val = $done . ($chat !== '' ? ' · ' . $chat : '');
            return ['value' => $val, 'sort' => $n, 'tone' => 'ok'];
        }

        return ['value' => $chat !== '' ? $chat : '—', 'sort' => $n];
    }

    /** order_id → order_no map for the Client column (one query, tenant-scoped). */
    private function clientMap($tasks, int $tenantId): array
    {
        $ids = $tasks->pluck('order_id')->filter(fn ($v) => (int) $v > 0)->unique()->values()->all();
        if (! $ids) return [];
        return Order::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)->pluck('order_no', 'id')->toArray();
    }

    /** customer_id → customer name, for tasks created off a customer page (one query). */
    private function customerMap($tasks, int $tenantId): array
    {
        $ids = $tasks->pluck('customer_id')->filter(fn ($v) => (int) $v > 0)->unique()->values()->all();
        if (! $ids) return [];
        return \App\Models\CustomerProfile::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)->pluck('name', 'id')->toArray();
    }

    /** Client column: a linked customer wins, else the order number, else a dash. */
    private function clientCell(OrderTask $x, array $clients, array $customers): string
    {
        if ((int) ($x->customer_id ?? 0) > 0 && ! empty($customers[$x->customer_id])) {
            return (string) $customers[$x->customer_id];
        }
        return (string) ($clients[$x->order_id] ?? '—');
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenant; $u = $ctx->user;
        $canManage = $u->isOwnerLike() || $u->role === 'manager';

        // Managers: the "All Jobs" register — every open task plus the recent closed ones.
        // Fetched separately so completed rows can never crowd open ones out of the
        // 500-row window, and the headline count comes straight from the DB.
        if ($canManage) {
            $active = $this->engine->allTasks($t, ['status' => 'active']);
            $closedRecent = $this->engine->allTasks($t, ['status' => 'completed'])->take(150);
            $all = $active->concat($closedRecent)->values();
            $openCount = OrderTask::where('tenant_id', $t->id)->whereIn('status', OrderTask::ACTIVE)->count();
            $overdueN = $active->filter(fn ($x) => $x->isOverdue())->count();
            $names = $this->engine->assigneeNames($t);
            $clients = $this->clientMap($all, $t->id);
            $customers = $this->customerMap($all, $t->id);
            $comments = $this->commentCounts($all, $t->id);

            $rows = $all->map(function (OrderTask $x) use ($names, $clients, $customers, $comments) {
                return [
                    'id'       => $x->id,
                    'ref'      => ['value' => '#' . ($x->ref_no ?: $x->id), 'sort' => (int) ($x->ref_no ?: $x->id)],
                    'task'     => $x->title,
                    'client'   => $this->clientCell($x, $clients, $customers),
                    'assignee' => $names[$x->owner_user_id] ?? ($x->owner_user_id ? ('User ' . $x->owner_user_id) : '—'),
                    'due'      => $this->dueCell($x),
                    'duration' => $this->durationText($x),
                    'priority' => ucfirst(WorkflowConfig::priorityLabel((int) $x->priority)),
                    'status'   => $this->statusCell($x),
                    'activity' => $this->activityCell($x, $names, $comments),
                    'act'      => ['buttons' => $this->rowButtons($x)],
                ];
            })->values()->all();

            $cols = [
                ['key' => 'ref',      'label' => '#'],
                ['key' => 'task',     'label' => 'Task', 'type' => 'strong'],
                ['key' => 'client',   'label' => 'Client'],
                ['key' => 'assignee', 'label' => 'Assignee'],
                ['key' => 'due',      'label' => 'Due'],
                ['key' => 'duration', 'label' => 'Duration'],
                ['key' => 'priority', 'label' => 'Priority'],
                ['key' => 'status',   'label' => 'Status', 'type' => 'badge'],
                ['key' => 'activity', 'label' => 'Updates'],
                ['key' => 'act',      'label' => '', 'type' => 'action', 'sortable' => false],
            ];
            $filters = [
                ['key' => 'status',   'label' => 'Status'],
                ['key' => 'assignee', 'label' => 'Assignee'],
                ['key' => 'priority', 'label' => 'Priority'],
            ];

            $staffOpts = User::where('tenant_id', $t->id)->orderBy('name')
                ->get(['id', 'name'])
                // Commas would corrupt the enum-encoded dropdown in the SPA form.
                ->map(fn ($x) => '#' . $x->id . ' ' . str_replace(',', ' ', $x->name ?: ('User ' . $x->id)))
                ->all();

            // Export follows the on-screen filters (same mechanism the purchases export uses).
            $exportScope = [
                'dateFrom' => 'from', 'dateTo' => 'to',
                'filters' => [
                    'status'   => ['param' => 'status', 'map' => [
                        'Closed' => 'closed', 'Open' => 'open', 'In progress' => 'in_progress', 'Paused' => 'paused', 'Blocked' => 'blocked',
                    ]],
                    'assignee' => ['param' => 'assignee_id', 'map' => collect($names)
                        ->filter(fn ($n) => is_string($n) && $n !== '')->flip()->toArray()],
                ],
            ];

            return (new WorkspacePayload)
                ->object(['type' => 'process:tasks', 'title' => 'Tasks', 'subtitle' => 'All team tasks — assign, track, and close'])
                ->headline((string) $openCount, 'Open tasks', $overdueN ? 'warn' : 'default')
                ->actions([
                    [
                        'key' => 'assign', 'label' => 'New task', 'tone' => 'primary', 'actor' => 'human',
                        'endpoint' => '/api/v1/workspace/tasks/0/assign', 'method' => 'POST', 'submitLabel' => 'Assign task',
                        'schema' => [
                            'assignee' => 'enum:' . implode(',', $staffOpts),
                            'title'    => 'text',
                            'due_at'   => 'datetime',
                            'duration' => 'enum:—,30 min,1 hr,1 hr 30 min,2 hr,3 hr,Half day (4 hr),Full day (8 hr)',
                            'priority' => 'enum:Normal,High,Low',
                            'attachment' => 'file:image/*,application/pdf',
                        ],
                        'labels' => ['assignee' => 'Assign to', 'title' => 'Task', 'due_at' => 'Due (date & time, optional)', 'duration' => 'Duration', 'priority' => 'Priority', 'attachment' => 'Photo or document (optional)'],
                    ],
                    ['key' => 'export-pdf', 'label' => 'Export PDF', 'href' => '/api/v1/doc/tasks-export?format=pdf', 'exportScope' => $exportScope],
                ])
                ->table($cols, $rows, $filters, [
                    'empty'    => 'No tasks yet — assign the first one.',
                    'dateKey'  => 'due', 'dateLabel' => 'Due',
                    'pageSize' => 15,
                    'selectable' => true,
                    'bulk' => [[
                        'label' => 'Mark done', 'tone' => 'primary', 'method' => 'POST',
                        'endpoint' => '/api/v1/workspace/tasks/0/bulk-done',
                        'confirm' => 'Mark {n} task(s) as done?',
                    ]],
                ])
                ->meta(['capability' => 'tasks', 'listKey' => 'tasks', 'canManage' => true]);
        }

        // Staff: personal list (same shape, no assignee column, no bulk).
        $mine = $this->engine->myTasks($t, $u);
        $clients = $this->clientMap($mine, $t->id);
        $customers = $this->customerMap($mine, $t->id);
        $names = $this->engine->assigneeNames($t);
        $comments = $this->commentCounts($mine, $t->id);
        $rows = $mine->map(function (OrderTask $x) use ($clients, $customers, $names, $comments) {
            return [
                'id'       => $x->id,
                'ref'      => ['value' => '#' . ($x->ref_no ?: $x->id), 'sort' => (int) ($x->ref_no ?: $x->id)],
                'task'     => $x->title,
                'client'   => $this->clientCell($x, $clients, $customers),
                'due'      => $this->dueCell($x),
                'duration' => $this->durationText($x),
                'priority' => ucfirst(WorkflowConfig::priorityLabel((int) $x->priority)),
                'status'   => $this->statusCell($x),
                'activity' => $this->activityCell($x, $names, $comments),
                'act'      => ['buttons' => $this->rowButtons($x)],
            ];
        })->values()->all();
        $cols = [
            ['key' => 'ref',      'label' => '#'],
            ['key' => 'task',     'label' => 'Task', 'type' => 'strong'],
            ['key' => 'client',   'label' => 'Client'],
            ['key' => 'due',      'label' => 'Due'],
            ['key' => 'duration', 'label' => 'Duration'],
            ['key' => 'priority', 'label' => 'Priority'],
            ['key' => 'status',   'label' => 'Status', 'type' => 'badge'],
            ['key' => 'activity', 'label' => 'Updates'],
            ['key' => 'act',      'label' => '', 'type' => 'action', 'sortable' => false],
        ];

        return (new WorkspacePayload)
            ->object(['type' => 'process:tasks', 'title' => 'My Tasks', 'subtitle' => 'Your work'])
            ->headline((string) $mine->count(), 'My open tasks', $mine->filter(fn ($x) => $x->isOverdue())->count() ? 'warn' : 'default')
            ->table($cols, $rows, [['key' => 'status', 'label' => 'Status']], [
                'empty' => 'No open tasks. Nice and clear.',
                'dateKey' => 'due', 'dateLabel' => 'Due',
            ])
            ->meta(['capability' => 'tasks', 'listKey' => 'tasks', 'canManage' => false]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $mine = $this->engine->myTasks($ctx->tenant, $ctx->user);
        return ['open' => $mine->count(), 'overdue' => $mine->filter(fn ($x) => $x->isOverdue())->count()];
    }

    /** "1 hr 30 min" / "Half day (4 hr)" → minutes. 0 when blank/—. */
    private function durationToMinutes(string $label): int
    {
        $label = strtolower(trim($label));
        if ($label === '' || $label === '—') return 0;
        if (preg_match('/\((\d+)\s*hr\)/', $label, $m)) return ((int) $m[1]) * 60; // Half/Full day
        $min = 0;
        if (preg_match('/(\d+)\s*hr/', $label, $m))  $min += ((int) $m[1]) * 60;
        if (preg_match('/(\d+)\s*min/', $label, $m)) $min += (int) $m[1];
        return $min;
    }

    /** Close one task the safe way: workflow tasks advance stages; ad-hoc just complete. */
    private function closeTask(OrderTask $task, $t, User $u): array
    {
        if ($task->order_id > 0 && $task->stage !== 'adhoc') {
            if ($task->status === 'offered') {
                $acc = $this->engine->accept($t, $task, $u);
                if (empty($acc['ok'])) return $acc;
                $task->refresh();
            }
            return $this->engine->complete($t, $task, $u);
        }
        return $this->engine->setStatusById($t, (int) $task->id, 'completed', $u);
    }

    /**
     * Actions:
     *   assignable → list of staff a manager can assign to
     *   assign     → create a one-off task (manager only); due datetime + duration supported
     *   start      → mark a task in progress (manager only)
     *   done       → close a task; order-workflow tasks advance via the engine
     *   bulk-done  → close many at once (from the list's selection bar)
     *   complete   → mark one of the caller's tasks done
     */
    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        $t = $ctx->tenant; $u = $ctx->user;
        $canManage = $u->isOwnerLike() || $u->role === 'manager';

        if ($action === 'assignable') {
            if (! $canManage) return ['ok' => false, 'error' => 'forbidden'];
            $users = User::where('tenant_id', $t->id)->orderBy('name')
                ->get(['id', 'name', 'role'])
                ->map(fn ($x) => ['id' => $x->id, 'name' => $x->name ?: ('User ' . $x->id), 'role' => $x->role])
                ->values()->all();
            return ['ok' => true, 'users' => $users];
        }

        if ($action === 'assign') {
            if (! $canManage) return ['ok' => false, 'error' => 'forbidden'];
            // The SPA form sends assignee as "#id Name" (from the enum); accept a raw id too.
            $rawAssignee = (string) ($input['assignee'] ?? $input['assignee_id'] ?? '');
            if (preg_match('/#?(\d+)/', $rawAssignee, $m)) {
                $assigneeId = (int) $m[1];
            } else {
                $assigneeId = (int) $rawAssignee;
            }
            $assignee = User::where('tenant_id', $t->id)->find($assigneeId);
            if (! $assignee) return ['ok' => false, 'error' => 'Pick a valid staff member.'];

            // datetime-local ("2026-07-21T17:00") or plain date both parse.
            $due = ! empty($input['due_at']) ? \Illuminate\Support\Carbon::parse($input['due_at']) : null;

            // Priority label → WorkflowConfig::PRIORITY rank (low 0 / normal 1 / high 2).
            $prioMap = ['low' => 0, 'normal' => 1, 'high' => 2];
            $rawPrio = strtolower(trim((string) ($input['priority'] ?? 'normal')));
            $priority = $prioMap[$rawPrio] ?? (is_numeric($rawPrio) ? (int) $rawPrio : 1);

            $sla = $this->durationToMinutes((string) ($input['duration'] ?? ''));
            // Optional photo/PDF attached in the form (inline base64) → stored, its URL rides on the task.
            $attach = \App\Support\Base64Upload::store($t->id, (string) ($input['attachment'] ?? ''));

            $res = $this->engine->assignAdHoc($t, $assignee, (string) ($input['title'] ?? ''), $due, $priority, $u, $sla, null, $attach);
            if (! empty($res['ok'])) $res['message'] = 'Task assigned to ' . ($assignee->name ?: 'staff');
            return $res;
        }

        if ($action === 'start') {
            // Managers can start any task; staff can start their own (mirrors accept).
            $taskId = (int) ($input['id'] ?? 0);
            $task = OrderTask::where('tenant_id', $t->id)->find($taskId);
            if (! $task) return ['ok' => false, 'error' => 'Task not found.'];
            if (! $canManage && (int) $task->owner_user_id !== (int) $u->id) {
                return ['ok' => false, 'error' => 'Task not found.'];
            }
            return $this->engine->setStatusById($t, $taskId, 'in_progress', $u);
        }

        if ($action === 'done') {
            $taskId = (int) ($input['id'] ?? 0);
            $task = OrderTask::where('tenant_id', $t->id)->find($taskId);
            if (! $task) return ['ok' => false, 'error' => 'Task not found.'];
            if (! $canManage && (int) $task->owner_user_id !== (int) $u->id) {
                return ['ok' => false, 'error' => 'Task not found.'];
            }
            return $this->closeTask($task, $t, $u);
        }

        if ($action === 'bulk-done') {
            $ids = array_values(array_filter(array_map('intval', (array) ($input['ids'] ?? []))));
            if (! $ids) return ['ok' => false, 'error' => 'Nothing selected.'];
            $doneN = 0; $skipN = 0;
            foreach (OrderTask::where('tenant_id', $t->id)->whereIn('id', $ids)->get() as $task) {
                if ($task->status === 'completed') { $skipN++; continue; }
                if (! $canManage && (int) $task->owner_user_id !== (int) $u->id) { $skipN++; continue; }
                $r = $this->closeTask($task, $t, $u);
                ! empty($r['ok']) ? $doneN++ : $skipN++;
            }
            return ['ok' => true, 'message' => $doneN . ' task(s) closed' . ($skipN ? (', ' . $skipN . ' skipped') : '')];
        }

        if ($action === 'complete') {
            $task = OrderTask::where('tenant_id', $t->id)->where('owner_user_id', $u->id)
                ->find((int) ($input['task_id'] ?? 0));
            if (! $task) return ['ok' => false, 'error' => 'Task not found.'];
            return $this->engine->complete($t, $task, $u);
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
