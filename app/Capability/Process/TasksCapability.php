<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\OrderTask;
use App\Models\User;
use App\Services\Ops\TaskEngine;

/**
 * Tasks (V3) — a person's own task list, plus (for owner/manager) the ability to assign
 * a one-off task to a staff member. Reads/writes through the existing TaskEngine + the
 * same task data the classic panel and WhatsApp reminders use. Assigning here sends the
 * assignee a WhatsApp with a tap-through link (no login) to accept/complete.
 *
 * FIXED (v3 tasks bugfix):
 *  - Table payload now uses KEYED columns + keyed rows (the renderList/renderTable
 *    contract in shell.blade.php), instead of the positional `cells` shape that only
 *    the embedded card-table renderer understands. Before this fix the /v3 Tasks page
 *    rendered every cell blank and never showed the Start/Done buttons.
 *  - The action column carries type:'action' so the per-row buttons actually render.
 *  - 'done' on an ORDER-WORKFLOW task now flows through TaskEngine::complete() so the
 *    next stage opens (and the requires_upload gate applies); plain status flips are
 *    reserved for ad-hoc tasks. Previously "Done" silently stalled the order pipeline.
 *  - Priority map aligned to WorkflowConfig::PRIORITY (low 0 / normal 1 / high 2) so
 *    "Normal" no longer displays as "High". (See also the TaskEngine clamp note in
 *    FIX-V3-TASKS.md.)
 */
final class TasksCapability implements ProcessCapability
{
    public function __construct(private TaskEngine $engine) {}

    public function key(): string { return 'tasks'; }

    // Everyone can see their own tasks; the Assign action is gated inside command().
    public function permission(): string { return 'menu:tasks'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenant; $u = $ctx->user;
        $canManage = $u->isOwnerLike() || $u->role === 'manager';

        // Managers default to the "All Tasks" overview; staff see only their own.
        if ($canManage) {
            $all = $this->engine->allTasks($t, ['status' => 'active']);
            $names = $this->engine->assigneeNames($t);
            $rows = $all->map(function (OrderTask $x) use ($names) {
                $due = $x->due_at ? $x->due_at->format('M j, H:i') : '—';
                $status = $x->status === 'completed' ? '✅ Done'
                    : ($x->status === 'in_progress' ? '🔵 In progress'
                    : ($x->isOverdue() ? '🔴 Overdue' : '🟡 To do'));
                $buttons = [];
                if ($x->status !== 'in_progress' && $x->status !== 'completed') {
                    $buttons[] = ['title' => 'Start', 'icon' => 'bolt', 'post' => '/api/v1/workspace/tasks/' . $x->id . '/start'];
                }
                if ($x->status !== 'completed') {
                    $buttons[] = ['title' => 'Done', 'icon' => 'check', 'tone' => 'ok', 'post' => '/api/v1/workspace/tasks/' . $x->id . '/done'];
                }
                // KEYED row — matches the renderTable contract (r[col.key]).
                return [
                    'id'       => $x->id,
                    'task'     => $x->title,
                    'assignee' => $names[$x->owner_user_id] ?? ('User ' . $x->owner_user_id),
                    'due'      => $due,
                    'status'   => ['value' => $status, 'tone' => $x->isOverdue() && $x->status !== 'completed' ? 'danger' : null],
                    'act'      => ['buttons' => $buttons],
                ];
            })->values()->all();

            // KEYED columns — 'act' is type:'action' so the row buttons render.
            $cols = [
                ['key' => 'task',     'label' => 'Task'],
                ['key' => 'assignee', 'label' => 'Assignee'],
                ['key' => 'due',      'label' => 'Due'],
                ['key' => 'status',   'label' => 'Status'],
                ['key' => 'act',      'label' => '', 'type' => 'action', 'sortable' => false],
            ];

            $staffOpts = User::where('tenant_id', $t->id)->orderBy('name')
                ->get(['id', 'name'])
                // Commas would corrupt the enum-encoded dropdown in the SPA form.
                ->map(fn ($x) => '#' . $x->id . ' ' . str_replace(',', ' ', $x->name ?: ('User ' . $x->id)))
                ->all();

            return (new WorkspacePayload)
                ->object(['type' => 'process:tasks', 'title' => 'Tasks', 'subtitle' => 'All team tasks — assign, track, and close'])
                ->headline((string) $all->count(), 'Open tasks', $all->filter(fn ($x) => $x->isOverdue())->count() ? 'warn' : 'default')
                ->actions([[
                    'key' => 'assign', 'label' => 'New task', 'tone' => 'primary', 'actor' => 'human',
                    'endpoint' => '/api/v1/workspace/tasks/0/assign', 'method' => 'POST', 'submitLabel' => 'Assign task',
                    'schema' => [
                        'assignee' => 'enum:' . implode(',', $staffOpts),
                        'title'    => 'text',
                        'due_at'   => 'date',
                        'priority' => 'enum:Normal,High,Low',
                    ],
                    'labels' => ['assignee' => 'Assign to', 'title' => 'Task', 'due_at' => 'Due (optional)', 'priority' => 'Priority'],
                ]])
                ->table($cols, $rows, [], ['empty' => 'No open tasks across the team.'])
                ->meta(['capability' => 'tasks', 'listKey' => 'tasks', 'canManage' => true]);
        }

        // Staff: personal list only (keyed shape, same contract).
        $mine = $this->engine->myTasks($t, $u);
        $rows = $mine->map(function (OrderTask $x) {
            $due = $x->due_at ? $x->due_at->format('M j, H:i') : '—';
            $badge = $x->isOverdue() ? '🔴 overdue' : ($x->status === 'in_progress' ? '🔵 in progress' : '🟡 to do');
            $buttons = [];
            if ($x->status !== 'completed') {
                $buttons[] = ['title' => 'Done', 'icon' => 'check', 'tone' => 'ok', 'post' => '/api/v1/workspace/tasks/' . $x->id . '/done'];
            }
            return [
                'id'     => $x->id,
                'task'   => $x->title,
                'due'    => $due,
                'status' => ['value' => $badge, 'tone' => $x->isOverdue() ? 'danger' : null],
                'act'    => ['buttons' => $buttons],
            ];
        })->values()->all();
        $cols = [
            ['key' => 'task',   'label' => 'Task'],
            ['key' => 'due',    'label' => 'Due'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'act',    'label' => '', 'type' => 'action', 'sortable' => false],
        ];

        return (new WorkspacePayload)
            ->object(['type' => 'process:tasks', 'title' => 'My Tasks', 'subtitle' => 'Your work'])
            ->headline((string) $mine->count(), 'My open tasks', $mine->filter(fn ($x) => $x->isOverdue())->count() ? 'warn' : 'default')
            ->table($cols, $rows, [], ['empty' => 'No open tasks. Nice and clear.'])
            ->meta(['capability' => 'tasks', 'listKey' => 'tasks', 'canManage' => false]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $mine = $this->engine->myTasks($ctx->tenant, $ctx->user);
        return ['open' => $mine->count(), 'overdue' => $mine->filter(fn ($x) => $x->isOverdue())->count()];
    }

    /**
     * Actions:
     *   assignable → list of staff a manager can assign to
     *   assign     → create a one-off task for a person (manager only)
     *   start      → mark a task in progress (manager only)
     *   done       → close a task; order-workflow tasks advance via the engine
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

            $due = ! empty($input['due_at']) ? \Illuminate\Support\Carbon::parse($input['due_at']) : null;

            // Priority may arrive as a label (Normal/High/Low) or a number.
            // Aligned to WorkflowConfig::PRIORITY: low 0 / normal 1 / high 2 / critical 3.
            $prioMap = ['low' => 0, 'normal' => 1, 'high' => 2];
            $rawPrio = strtolower(trim((string) ($input['priority'] ?? 'normal')));
            $priority = $prioMap[$rawPrio] ?? (is_numeric($rawPrio) ? (int) $rawPrio : 1);

            $res = $this->engine->assignAdHoc($t, $assignee, (string) ($input['title'] ?? ''), $due, $priority, $u);
            if (! empty($res['ok'])) $res['message'] = 'Task assigned to ' . ($assignee->name ?: 'staff');
            return $res;
        }

        if ($action === 'start') {
            if (! $canManage) return ['ok' => false, 'error' => 'forbidden'];
            return $this->engine->setStatusById($t, (int) ($input['id'] ?? 0), 'in_progress', $u);
        }

        if ($action === 'done') {
            $taskId = (int) ($input['id'] ?? 0);
            $task = OrderTask::where('tenant_id', $t->id)->find($taskId);
            if (! $task) return ['ok' => false, 'error' => 'Task not found.'];
            // Staff may only close their own tasks; managers may close any.
            if (! $canManage && (int) $task->owner_user_id !== (int) $u->id) {
                return ['ok' => false, 'error' => 'Task not found.'];
            }
            // ORDER-WORKFLOW tasks must advance the pipeline (and respect the upload
            // gate) — route them through the engine. A bare status flip would close
            // the stage without opening the next one and silently stall the order.
            if ($task->order_id > 0 && $task->stage !== 'adhoc') {
                if ($task->status === 'offered') {
                    $acc = $this->engine->accept($t, $task, $u);
                    if (empty($acc['ok'])) return $acc;
                    $task->refresh();
                }
                return $this->engine->complete($t, $task, $u);
            }
            return $this->engine->setStatusById($t, $taskId, 'completed', $u);
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
