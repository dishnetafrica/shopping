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

        $mine = $this->engine->myTasks($t, $u);
        $rows = $mine->map(function (OrderTask $x) {
            $due = $x->due_at ? $x->due_at->format('M j, H:i') : '—';
            $badge = $x->isOverdue() ? '🔴 overdue' : ($x->status === 'in_progress' ? '🔵 in progress' : '🟡 to do');
            return ['id' => $x->id, 'cells' => [$x->title, $due, $badge]];
        })->values()->all();
        $cols = [['label' => 'Task'], ['label' => 'Due'], ['label' => 'Status']];

        $payload = (new WorkspacePayload)
            ->object(['type' => 'process:tasks', 'title' => 'Tasks', 'subtitle' => 'Your work — and assign to your team'])
            ->headline((string) $mine->count(), 'My open tasks', $mine->filter(fn ($x) => $x->isOverdue())->count() ? 'warn' : 'default');

        // Manager-only: a primary "New task" action rendered as a schema form the SPA can open.
        if ($canManage) {
            $staff = User::where('tenant_id', $t->id)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($x) => $x->id . ' — ' . ($x->name ?: ('User ' . $x->id)))
                ->all();
            $payload->actions([[
                'key'    => 'assign',
                'label'  => 'New task',
                'tone'   => 'primary',
                'actor'  => 'human',
                'endpoint' => '/api/v1/workspace/tasks/0/assign',
                'method' => 'POST',
                'submitLabel' => 'Assign task',
                'schema' => [
                    'assignee' => 'enum:' . implode(',', $staff),
                    'title'    => 'text',
                    'due_at'   => 'date',
                    'priority' => 'enum:Normal,High,Low',
                ],
                'labels' => [
                    'assignee' => 'Assign to',
                    'title'    => 'Task',
                    'due_at'   => 'Due (optional)',
                    'priority' => 'Priority',
                ],
            ]]);
        }

        return $payload
            ->table($cols, $rows, [], ['empty' => 'No open tasks. Nice and clear.'])
            ->meta(['capability' => 'tasks', 'listKey' => 'tasks', 'canManage' => $canManage]);
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
            // The SPA form sends assignee as "id — Name" (from the enum); accept a raw id too.
            $rawAssignee = (string) ($input['assignee'] ?? $input['assignee_id'] ?? '');
            $assigneeId = (int) trim(explode('—', $rawAssignee)[0]);
            $assignee = User::where('tenant_id', $t->id)->find($assigneeId);
            if (! $assignee) return ['ok' => false, 'error' => 'Pick a valid staff member.'];

            $due = ! empty($input['due_at']) ? \Illuminate\Support\Carbon::parse($input['due_at']) : null;

            // Priority may arrive as a label (Normal/High/Low) or a number.
            $prioMap = ['low' => 1, 'normal' => 2, 'high' => 3];
            $rawPrio = strtolower(trim((string) ($input['priority'] ?? 'normal')));
            $priority = $prioMap[$rawPrio] ?? (is_numeric($rawPrio) ? (int) $rawPrio : 2);

            $res = $this->engine->assignAdHoc($t, $assignee, (string) ($input['title'] ?? ''), $due, $priority, $u);
            if (! empty($res['ok'])) $res['message'] = 'Task assigned to ' . ($assignee->name ?: 'staff');
            return $res;
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
