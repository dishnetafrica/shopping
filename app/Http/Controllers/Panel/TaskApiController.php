<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTask;
use App\Models\OrderTaskEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\TaskEngine;
use App\Support\WorkflowConfig;
use Illuminate\Http\Request;

/**
 * Read/write API for the Operations Layer UI (My Tasks, Work Queue, timeline, roles).
 * Session-authed + tenant-scoped via SetTenantFromUser, like the rest of /papi.
 */
class TaskApiController extends Controller
{
    public function __construct(private TaskEngine $engine) {}

    /** A person's own open work, soonest-due first. */
    public function mine(Request $r)
    {
        $u = $r->user(); $t = $u->tenant;
        $tasks = $this->engine->myTasks($t, $u);
        $orders = $this->orderNos($t, $tasks->pluck('order_id')->all());
        return response()->json([
            'ok'      => true,
            'me'      => $u->name,
            'count'   => $tasks->count(),
            'overdue' => $tasks->filter(fn ($x) => $x->isOverdue())->count(),
            'tasks'   => $tasks->map(fn ($x) => $this->taskDto($t, $x, $orders))->values(),
        ]);
    }

    /** Manager view: where work sits, what's overdue, what's blocked. */
    public function queue(Request $r)
    {
        $u = $r->user(); $t = $u->tenant;
        if (! $u->isOwnerLike() && $u->role !== 'manager') {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $active = OrderTask::query()->where('tenant_id', $t->id)->whereIn('status', OrderTask::ACTIVE)->get();

        $byStage = [];
        foreach (WorkflowConfig::stages($t) as $s) {
            $key = $s['key'];
            $rows = $active->where('stage', $key);
            $oldest = $rows->min('assigned_at');
            $byStage[] = [
                'stage'        => $key,
                'title'        => $s['title'] ?? $key,
                'role'         => WorkflowConfig::roleLabel($s['role'] ?? ''),
                'pending'      => $rows->count(),
                'overdue'      => $rows->filter(fn ($x) => $x->isOverdue())->count(),
                'oldest_min'   => $oldest ? max(0, \Illuminate\Support\Carbon::parse($oldest)->diffInMinutes(now())) : null,
            ];
        }

        $exceptions = [];
        $waiting = [];
        foreach ($active->whereIn('status', ['paused', 'exception']) as $x) {
            $k = $x->exception_type ?: 'other';
            $exceptions[$k] = ($exceptions[$k] ?? 0) + 1;
            $w = $x->waiting_for ?: 'other';
            $waiting[$w] = ($waiting[$w] ?? 0) + 1;
        }

        $completedToday = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereDate('workflow_done_at', now()->toDateString())->count();
        $ordersToday = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereDate('created_at', now()->toDateString())->count();

        return response()->json([
            'ok'             => true,
            'stages'         => $byStage,
            'overdue'        => $active->filter(fn ($x) => $x->isOverdue())->count(),
            'exceptions'     => $exceptions,
            'waiting_for'    => $waiting,
            'completed_today' => $completedToday,
            'orders_today'   => $ordersToday,
            'active_total'   => $active->count(),
        ]);
    }

    /** Operations home snapshot — tailored to who's asking. */
    public function summary(Request $r)
    {
        $u = $r->user(); $t = $u->tenant;
        $mine = $this->engine->myTasks($t, $u);
        $out = [
            'ok'        => true,
            'me'        => $u->name,
            'enabled'   => WorkflowConfig::enabled($t),
            'my_tasks'  => $mine->count(),
            'my_overdue' => $mine->filter(fn ($x) => $x->isOverdue())->count(),
            'my_blocked' => $mine->where('status', 'paused')->count(),
            'can_manage' => $u->isOwnerLike() || $u->role === 'manager',
            'is_owner'   => $u->isOwnerLike(),
        ];
        if ($out['can_manage']) {
            $active = OrderTask::query()->where('tenant_id', $t->id)->whereIn('status', OrderTask::ACTIVE)->get();
            $out['orders_today'] = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->whereDate('created_at', now()->toDateString())->count();
            $out['overdue'] = $active->filter(fn ($x) => $x->isOverdue())->count();
            $out['blocked'] = $active->whereIn('status', ['paused', 'exception'])->count();
            $out['active_total'] = $active->count();
        }
        return response()->json($out);
    }

    /** Complete the actor's task -> auto-advances to the next stage. */
    public function complete(Request $r)
    {
        [$task, $err] = $this->guard($r);
        if ($err) return $err;
        $opts = [];
        if ($r->filled('attachment')) $opts['attachment'] = (string) $r->input('attachment');
        if ($r->filled('note'))       $opts['note'] = (string) $r->input('note');
        if ($r->filled('checklist'))  $this->saveChecklist($task, $r->input('checklist'));
        $res = $this->engine->complete($r->user()->tenant, $task, $r->user(), $opts);
        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    /** Raise an exception: pause (clock freezes) or reassign to another role. */
    public function exception(Request $r)
    {
        [$task, $err] = $this->guard($r);
        if ($err) return $err;
        $t      = $r->user()->tenant;
        $type   = (string) $r->input('type', 'other');
        $reason = trim((string) $r->input('reason', ''));
        if ($reason === '') return response()->json(['ok' => false, 'error' => 'A reason is required.'], 422);
        $allowed  = WorkflowConfig::exceptionsFor($t, $task->stage);
        $reassign = $allowed[$type]['reassign'] ?? null;
        $waiting  = (string) $r->input('waiting_for', '');
        $res = $this->engine->raiseException($t, $task, $type, $reason, $r->user(), $reassign, $waiting ?: null);
        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    /** Resume a paused task — banks paused time, recomputes the deadline. */
    public function resume(Request $r)
    {
        [$task, $err] = $this->guard($r);
        if ($err) return $err;
        $res = $this->engine->resume($r->user()->tenant, $task, $r->user());
        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    /** Persist checklist tick state without completing (tick as you go). */
    public function checklist(Request $r)
    {
        [$task, $err] = $this->guard($r);
        if ($err) return $err;
        $this->saveChecklist($task, $r->input('checklist'));
        return response()->json(['ok' => true]);
    }

    /** Full timeline (audit trail) for one order. */
    public function timeline(Request $r)
    {
        $t  = $r->user()->tenant;
        $id = (int) $r->input('order', 0);
        $events = OrderTaskEvent::query()->where('tenant_id', $t->id)->where('order_id', $id)
            ->orderBy('id')->get()
            ->map(fn ($e) => [
                'event'  => $e->event,
                'actor'  => $e->actor_name,
                'stage'  => $e->to_stage ?: $e->from_stage,
                'detail' => $e->detail,
                'at'     => optional($e->created_at)->toIso8601String(),
            ]);
        return response()->json(['ok' => true, 'events' => $events]);
    }

    /** Manager "what happened today" feed across all orders, from the event log. */
    public function activity(Request $r)
    {
        $u = $r->user(); $t = $u->tenant;
        if (! $u->isOwnerLike() && $u->role !== 'manager') {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $events = OrderTaskEvent::query()->where('tenant_id', $t->id)
            ->whereDate('created_at', now()->toDateString())
            ->orderByDesc('id')->limit(300)->get();
        $orders = $this->orderNos($t, $events->pluck('order_id')->unique()->all());
        $titles = [];
        foreach (WorkflowConfig::stages($t) as $s) $titles[$s['key']] = $s['title'] ?? $s['key'];
        return response()->json([
            'ok'     => true,
            'events' => $events->map(fn ($e) => [
                'at'       => optional($e->created_at)->toIso8601String(),
                'event'    => $e->event,
                'actor'    => $e->actor_name ?: 'System',
                'order_id' => $e->order_id,
                'order_no' => $orders[$e->order_id] ?? ('#' . $e->order_id),
                'stage'    => $titles[$e->to_stage ?? ''] ?? ($titles[$e->from_stage ?? ''] ?? ($e->to_stage ?: $e->from_stage)),
                'detail'   => $e->detail,
            ])->values(),
        ]);
    }

    /** Role -> user mapping + the tenant's people, for the assignment screen. */
    public function roles(Request $r)
    {
        $u = $r->user(); $t = $u->tenant;
        if (! $u->isOwnerLike()) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $stages = WorkflowConfig::stages($t);
        $roleKeys = [];
        foreach ($stages as $s) $roleKeys[$s['role']] = WorkflowConfig::roleLabel($s['role']);
        $roleKeys['procurement'] = WorkflowConfig::roleLabel('procurement');
        $map = WorkflowConfig::roleMap($t);
        $users = User::query()->where('tenant_id', $t->id)
            ->get(['id', 'name', 'phone', 'role'])
            ->map(fn ($x) => ['id' => $x->id, 'name' => $x->name ?: ('User #' . $x->id), 'role' => $x->role]);
        return response()->json([
            'ok'      => true,
            'enabled' => WorkflowConfig::enabled($t),
            'roles'   => $roleKeys,
            'map'     => $map,
            'users'   => $users,
        ]);
    }

    /** Save role -> users and the workflow on/off switch. */
    public function rolesSave(Request $r)
    {
        $u = $r->user(); $t = $u->tenant;
        if (! $u->isOwnerLike()) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        if ($r->has('enabled')) {
            $t->putSetting('workflow_enabled', in_array((string) $r->input('enabled'), ['1', 'true', 'on', 'yes'], true));
        }
        if ($r->has('map')) {
            $raw = $r->input('map');
            $raw = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
            $clean = [];
            $validIds = User::query()->where('tenant_id', $t->id)->pluck('id')->all();
            foreach ($raw as $role => $ids) {
                $ids = array_values(array_intersect(array_map('intval', (array) $ids), $validIds));
                if ($ids) $clean[$role] = $ids;
            }
            $t->putSetting('workflow.roles', $clean);
        }
        return response()->json(['ok' => true, 'enabled' => WorkflowConfig::enabled($t), 'map' => WorkflowConfig::roleMap($t)]);
    }

    // ----------------------------------------------------------------- helpers

    /** Load a task scoped to tenant and check the actor may act on it. */
    private function guard(Request $r): array
    {
        $u = $r->user(); $t = $u->tenant;
        $task = OrderTask::query()->where('tenant_id', $t->id)->find((int) $r->input('id', 0));
        if (! $task) return [null, response()->json(['ok' => false, 'error' => 'Task not found.'], 404)];
        $mine = (int) $task->owner_user_id === (int) $u->id;
        if (! $mine && ! $u->isOwnerLike() && $u->role !== 'manager') {
            return [null, response()->json(['ok' => false, 'error' => 'Not your task.'], 403)];
        }
        return [$task, null];
    }

    private function saveChecklist(OrderTask $task, $items): void
    {
        $items = is_string($items) ? (json_decode($items, true) ?: []) : (array) $items;
        $cur = (array) ($task->checklist ?? []);
        foreach ($cur as $i => $row) {
            if (isset($items[$i])) $cur[$i]['done'] = (bool) ($items[$i]['done'] ?? $items[$i] ?? false);
        }
        $task->checklist = $cur;
        $task->save();
    }

    private function orderNos(Tenant $t, array $ids): array
    {
        if (! $ids) return [];
        return Order::withoutGlobalScopes()->where('tenant_id', $t->id)->whereIn('id', $ids)
            ->pluck('order_no', 'id')->all();
    }

    private function taskDto(Tenant $t, OrderTask $x, array $orders): array
    {
        return [
            'id'        => $x->id,
            'order_id'  => $x->order_id,
            'order_no'  => $orders[$x->order_id] ?? ('#' . $x->order_id),
            'stage'     => $x->stage,
            'stage_label' => WorkflowConfig::roleLabel($x->role),
            'title'     => $x->title,
            'status'    => $x->status,
            'assigned_at' => optional($x->assigned_at)->toIso8601String(),
            'due_at'    => optional($x->due_at)->toIso8601String(),
            'overdue'   => $x->isOverdue(),
            'paused'    => $x->status === 'paused',
            'requires_upload' => (bool) $x->requires_upload,
            'attachment' => $x->attachment,
            'checklist' => array_values((array) ($x->checklist ?? [])),
            'progress'  => $x->progress(),
            'priority'  => (int) $x->priority,
            'priority_label' => WorkflowConfig::priorityLabel((int) $x->priority),
            'exceptions' => WorkflowConfig::exceptionsFor($t, $x->stage),
            'waiting_options' => WorkflowConfig::WAITING_FOR,
            'waiting_for' => $x->waiting_for,
            'exception_type' => $x->exception_type,
            'exception_reason' => $x->exception_reason,
        ];
    }
}
