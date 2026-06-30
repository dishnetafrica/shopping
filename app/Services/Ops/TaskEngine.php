<?php
namespace App\Services\Ops;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\OrderTask;
use App\Models\OrderTaskEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\WorkflowConfig;
use Illuminate\Support\Facades\DB;

/**
 * The Task Engine (Operations Layer spine).
 *
 * Drives an order through its stages by handing exactly one active task to exactly one
 * person at a time. People complete tasks; the order advances automatically. Ownership
 * resolves through a role (reassign the role -> future tasks reroute). Exceptions either
 * pause the SLA clock (external blocker) or reassign the task sideways (e.g. procurement),
 * so a genuine blocker never reads as a slow employee. Every transition is logged to the
 * immutable timeline.
 *
 * UI (my-tasks, inbox), the escalation cron, and the order-creation hook layer on top of
 * this; the lifecycle below is the part that must be correct first.
 */
class TaskEngine
{
    /** Begin the workflow for an order: open the first stage's task. Idempotent. */
    public function start(Tenant $tenant, Order $order): ?OrderTask
    {
        if (! WorkflowConfig::enabled($tenant)) return null;
        $existing = OrderTask::query()->where('tenant_id', $tenant->id)
            ->where('order_id', $order->id)->whereIn('status', OrderTask::ACTIVE)->first();
        if ($existing) return $existing;             // already in flight
        $first = WorkflowConfig::firstStage($tenant);
        if (! $first) return null;
        return $this->openStage($tenant, $order, $first);
    }

    /** Mark the actor's task done, record it, and open the next stage (or finish). */
    public function complete(Tenant $tenant, OrderTask $task, User $by, array $opts = []): array
    {
        if (! $task->isActive()) return ['ok' => false, 'error' => 'Task is not active.'];
        if ($task->requires_upload && empty($opts['attachment']) && empty($task->attachment)) {
            return ['ok' => false, 'error' => 'This step needs a file (e.g. invoice or proof of delivery).'];
        }

        return DB::transaction(function () use ($tenant, $task, $by, $opts) {
            $task->status       = 'completed';
            $task->completed_at = now();
            $task->completed_by = $by->id;
            if (! empty($opts['attachment'])) $task->attachment = (string) $opts['attachment'];
            if (! empty($opts['note']))       $task->note = (string) $opts['note'];
            $task->save();

            $this->log($tenant, $task->order_id, $task->id, 'completed', $by, $task->stage, null,
                $task->title . ' completed' . (! empty($opts['note']) ? ' — ' . $opts['note'] : ''));

            $next = WorkflowConfig::nextStage($tenant, $task->stage);
            if (! $next) {
                if ($order = Order::withoutGlobalScopes()->find($task->order_id)) {
                    $order->forceFill(['workflow_done_at' => now()])->save();
                }
                $this->log($tenant, $task->order_id, null, 'completed', $by, $task->stage, null, 'Workflow complete — customer received.');
                return ['ok' => true, 'done' => true, 'next' => null];
            }

            $order   = Order::withoutGlobalScopes()->find($task->order_id);
            $newTask = $this->openStage($tenant, $order, $next);
            return ['ok' => true, 'done' => false, 'next' => $newTask];
        });
    }

    /**
     * Raise an exception on a task.
     *   - reassign_role set  -> lateral handoff (e.g. out_of_stock -> procurement); same
     *                           stage, new role/owner, fresh clock.
     *   - otherwise          -> pause; SLA clock freezes until resume().
     */
    public function raiseException(Tenant $tenant, OrderTask $task, string $type, string $reason, User $by, ?string $reassignRole = null): array
    {
        if (! $task->isActive()) return ['ok' => false, 'error' => 'Task is not active.'];

        $task->exception_type   = $type;
        $task->exception_reason = $reason;

        if ($reassignRole) {
            $owner = $this->ownerForRole($tenant, $reassignRole);
            $task->role          = $reassignRole;
            $task->owner_user_id = $owner;
            $task->status        = 'pending';
            $task->assigned_at   = now();
            $task->paused_minutes = 0;
            $task->paused_at     = null;
            $task->due_at        = WorkflowConfig::dueAt(now(), (int) $task->sla_minutes, 0);
            $task->save();
            $this->log($tenant, $task->order_id, $task->id, 'reassigned', $by, $task->stage, $task->stage,
                "Exception ({$type}): {$reason} — reassigned to " . WorkflowConfig::roleLabel($reassignRole));
            $this->notifyOwner($tenant, $task, 'A task needs you (reassigned)');
            return ['ok' => true, 'mode' => 'reassigned'];
        }

        $task->status    = 'paused';
        $task->paused_at = now();
        $task->save();
        $this->log($tenant, $task->order_id, $task->id, 'paused', $by, $task->stage, null, "Paused ({$type}): {$reason}");
        return ['ok' => true, 'mode' => 'paused'];
    }

    /** Resume a paused task: bank the paused time (excluded from SLA) and recompute due. */
    public function resume(Tenant $tenant, OrderTask $task, User $by): array
    {
        if ($task->status !== 'paused') return ['ok' => false, 'error' => 'Task is not paused.'];
        $pausedMin = $task->paused_at ? max(0, $task->paused_at->diffInMinutes(now())) : 0;
        $task->paused_minutes = (int) $task->paused_minutes + $pausedMin;
        $task->paused_at      = null;
        $task->status         = 'in_progress';
        $task->due_at         = WorkflowConfig::dueAt($task->assigned_at ?? now(), (int) $task->sla_minutes, (int) $task->paused_minutes);
        $task->exception_type = null;
        $task->save();
        $this->log($tenant, $task->order_id, $task->id, 'resumed', $by, $task->stage, null, "Resumed (+{$pausedMin}m paused, not counted)");
        return ['ok' => true];
    }

    /** Pending-task counts per role — the Inbox Zero / bottleneck view. */
    public function inboxCounts(Tenant $tenant): array
    {
        return OrderTask::query()->where('tenant_id', $tenant->id)
            ->whereIn('status', OrderTask::ACTIVE)
            ->selectRaw('role, count(*) as c')->groupBy('role')
            ->pluck('c', 'role')->all();
    }

    /** A person's open work, soonest-due first. */
    public function myTasks(Tenant $tenant, User $user)
    {
        return OrderTask::query()->where('tenant_id', $tenant->id)
            ->where('owner_user_id', $user->id)
            ->whereIn('status', OrderTask::ACTIVE)
            ->orderByRaw('due_at is null, due_at asc')->get();
    }

    // ----------------------------------------------------------------- internals

    private function openStage(Tenant $tenant, ?Order $order, array $stage): OrderTask
    {
        $owner = $this->ownerForRole($tenant, (string) $stage['role']);
        $sla   = (int) ($stage['sla'] ?? 0);
        $task  = OrderTask::create([
            'tenant_id'       => $tenant->id,
            'order_id'        => $order?->id ?? 0,
            'seq'             => (int) ($stage['_index'] ?? 0),
            'stage'           => (string) $stage['key'],
            'role'            => (string) $stage['role'],
            'owner_user_id'   => $owner,
            'title'           => (string) ($stage['task'] ?? $stage['title'] ?? 'Task'),
            'status'          => 'pending',
            'sla_minutes'     => $sla,
            'paused_minutes'  => 0,
            'assigned_at'     => now(),
            'due_at'          => WorkflowConfig::dueAt(now(), $sla, 0),
            'requires_upload' => (bool) ($stage['upload'] ?? false),
        ]);
        $this->log($tenant, $task->order_id, $task->id, 'created', null, null, (string) $stage['key'],
            $task->title . ' assigned to ' . WorkflowConfig::roleLabel((string) $stage['role']));
        $this->notifyOwner($tenant, $task, 'You have a new task');
        return $task;
    }

    /** Resolve a role to the least-loaded assigned user (null if nobody set). */
    private function ownerForRole(Tenant $tenant, string $role): ?int
    {
        $candidates = WorkflowConfig::roleMap($tenant)[$role] ?? [];
        if (! $candidates) return null;
        $counts = OrderTask::query()->where('tenant_id', $tenant->id)
            ->whereIn('owner_user_id', $candidates)
            ->whereIn('status', OrderTask::ACTIVE)
            ->selectRaw('owner_user_id, count(*) as c')->groupBy('owner_user_id')
            ->pluck('c', 'owner_user_id')->all();
        return WorkflowConfig::pickOwner($candidates, $counts);
    }

    private function notifyOwner(Tenant $tenant, OrderTask $task, string $lead): void
    {
        if (! $task->owner_user_id || ! $tenant->whatsapp_instance) return;
        try {
            $u = User::find($task->owner_user_id);
            if (! $u || ! $u->phone) return;
            $order = Order::withoutGlobalScopes()->find($task->order_id);
            $no    = $order?->order_no ?: ('#' . $task->order_id);
            $due   = $task->due_at ? $task->due_at->format('M j, H:i') : '';
            $msg   = "{$lead}\n\nOrder {$no} — {$task->title}\nStage: " . WorkflowConfig::roleLabel($task->role)
                   . ($due ? "\nDue: {$due}" : '') . "\n\nOpen your tasks to complete it.";
            NotifyOwner::dispatch($tenant->id, $msg, $u->phone);
        } catch (\Throwable $e) {
            // notification is best-effort; the task still exists in the owner's inbox
        }
    }

    private function log(Tenant $tenant, int $orderId, ?int $taskId, string $event, ?User $actor, ?string $from, ?string $to, string $detail): void
    {
        OrderTaskEvent::create([
            'tenant_id'     => $tenant->id,
            'order_id'      => $orderId,
            'task_id'       => $taskId,
            'event'         => $event,
            'actor_user_id' => $actor?->id,
            'actor_name'    => $actor?->name,
            'from_stage'    => $from,
            'to_stage'      => $to,
            'detail'        => $detail,
            'created_at'    => now(),
        ]);
    }
}
