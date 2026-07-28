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

    /** Accept an offered (dispatched) task — the accountability gate before work starts. */
    public function accept(Tenant $tenant, OrderTask $task, User $by): array
    {
        if ($task->status !== 'offered') return ['ok' => false, 'error' => 'Nothing to accept.'];
        // Offer expiry: a stale offer can't be accepted (it may already be reassigned).
        if (WorkflowConfig::isFieldStage($tenant, $task->stage) && $task->assigned_at) {
            $ttl = WorkflowConfig::offerTtl($tenant);
            if ($task->assigned_at->copy()->addMinutes($ttl)->isPast()) {
                return ['ok' => false, 'error' => 'This offer has expired — ask your manager to resend it.'];
            }
        }
        $task->status      = 'in_progress';
        $task->accepted_at = now();
        $task->reminded_at = null;
        $task->save();
        $this->log($tenant, $task->order_id, $task->id, 'accepted', $by, $task->stage, null, 'Accepted by ' . ($by->name ?: 'owner'));
        return ['ok' => true];
    }

    /** A quick note that becomes an event — no completion required ("road closed", "called customer"). */
    public function comment(Tenant $tenant, OrderTask $task, ?User $by, string $text): array
    {
        $text = trim($text);
        if ($text === '') return ['ok' => false, 'error' => 'Empty note.'];
        $this->log($tenant, $task->order_id, $task->id, 'comment', $by, $task->stage, null, mb_substr($text, 0, 500));
        return ['ok' => true];
    }

    /** Mark the actor's task done, record it, and open the next stage (or finish). */
    public function complete(Tenant $tenant, OrderTask $task, User $by, array $opts = []): array
    {
        if (! $task->isActive()) return ['ok' => false, 'error' => 'Task is not active.'];
        if ($task->status === 'offered') return ['ok' => false, 'error' => 'Accept the task before completing it.'];
        if ($task->requires_upload && empty($opts['attachment']) && empty($task->attachment)) {
            return ['ok' => false, 'error' => 'This step needs a file (e.g. invoice or proof of delivery).'];
        }

        $res = DB::transaction(function () use ($tenant, $task, $by, $opts) {
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
                // Ad-hoc tasks (order_id 0) are not part of an order workflow — closing one
                // must not log a bogus "Workflow complete" event on order #0.
                if ($task->order_id > 0) {
                    if ($order = Order::withoutGlobalScopes()->find($task->order_id)) {
                        $order->forceFill(['workflow_done_at' => now()])->save();
                    }
                    $this->log($tenant, $task->order_id, null, 'completed', $by, $task->stage, null, 'Workflow complete — customer received.');
                }
                return ['ok' => true, 'done' => true, 'next' => null];
            }

            $order   = Order::withoutGlobalScopes()->find($task->order_id);
            $newTask = $this->openStage($tenant, $order, $next);
            return ['ok' => true, 'done' => false, 'next' => $newTask];
        });

        // Confirmation WhatsApps fire AFTER commit so a queued/sync job never reads an uncommitted row.
        if (! empty($res['ok'])) {
            $this->notifyCompleted($tenant, $task, $by, $opts['note'] ?? null);
        }
        return $res;
    }

    /**
     * Raise an exception on a task.
     *   - reassign_role set  -> lateral handoff (e.g. out_of_stock -> procurement); same
     *                           stage, new role/owner, fresh clock.
     *   - otherwise          -> pause; SLA clock freezes until resume().
     */
    public function raiseException(Tenant $tenant, OrderTask $task, string $type, string $reason, User $by, ?string $reassignRole = null, ?string $waitingFor = null): array
    {
        if (! $task->isActive()) return ['ok' => false, 'error' => 'Task is not active.'];

        $task->exception_type   = $type;
        $task->exception_reason = $reason;
        $task->waiting_for      = $waitingFor ?: (WorkflowConfig::EXCEPTION_WAITS[$type] ?? 'other');

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
        $task->waiting_for    = null;
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

    /** A person's open work: critical-overdue first, then priority, then oldest. */
    /**
     * All tasks for the tenant (every staff member) — the manager "All Tasks" view.
     * Optional filters: status ('active'|'completed'|'all'), owner_user_id.
     * Returns a flat, sorted collection. Assignee names are resolved by the caller via
     * $this->assigneeNames($tenant) to avoid mutating models.
     */
    public function allTasks(Tenant $tenant, array $filters = [])
    {
        $q = OrderTask::query()->where('tenant_id', $tenant->id);

        $status = $filters['status'] ?? 'active';
        if ($status === 'active')        $q->whereIn('status', OrderTask::ACTIVE);
        elseif ($status === 'completed') $q->where('status', 'completed');
        // 'all' → no status filter

        if (! empty($filters['owner_user_id'])) $q->where('owner_user_id', (int) $filters['owner_user_id']);

        $tasks = $q->orderByDesc('id')->limit(500)->get();

        return $tasks->sortBy(function (OrderTask $t) {
            $done     = $t->status === 'completed' ? 1 : 0;
            $over     = $t->isOverdue() ? 0 : 1;
            $assigned = $t->assigned_at ? $t->assigned_at->timestamp : PHP_INT_MAX;
            return sprintf('%d|%d|%020d', $done, $over, $assigned);
        })->values();
    }

    /** Map of user id → display name for a tenant (for the All Tasks assignee column). */
    public function assigneeNames(Tenant $tenant): array
    {
        return User::where('tenant_id', $tenant->id)->pluck('name', 'id')->toArray();
    }

    /** Change a task's status by id, scoped to the tenant (manager action from the list). */
    public function setStatusById(Tenant $tenant, int $taskId, string $status, ?User $by = null): array
    {
        $allowed = ['pending', 'in_progress', 'completed'];
        if (! in_array($status, $allowed, true)) return ['ok' => false, 'error' => 'Invalid status.'];

        $task = OrderTask::where('tenant_id', $tenant->id)->find($taskId);
        if (! $task) return ['ok' => false, 'error' => 'Task not found.'];

        $from = $task->status;
        $task->status = $status;
        if ($status === 'in_progress' && ! $task->started_at)  $task->started_at  = now();
        if ($status === 'completed'   && ! $task->completed_at) $task->completed_at = now();
        $task->save();

        $this->log($tenant, $task->order_id, $task->id, 'status', $by, $from, $status,
            $task->title . ' → ' . $status);

        // Panel "Done" on an ad-hoc task lands here (not complete()). Fire the confirmation on the
        // real open→completed transition only, so re-saving a completed task never re-notifies.
        if ($status === 'completed' && $from !== 'completed') {
            $this->notifyCompleted($tenant, $task, $by, null);
        }

        return ['ok' => true, 'status' => $status];
    }

    public function myTasks(Tenant $tenant, User $user)
    {
        $tasks = OrderTask::query()->where('tenant_id', $tenant->id)
            ->where('owner_user_id', $user->id)
            ->whereIn('status', OrderTask::ACTIVE)->get();

        return $tasks->sortBy(function (OrderTask $t) {
            $p          = (int) $t->priority;
            $critOver   = ($p >= 3 && $t->isOverdue()) ? 0 : 1;       // critical + overdue floats to top
            $assigned   = $t->assigned_at ? $t->assigned_at->timestamp : PHP_INT_MAX;
            return sprintf('%d|%d|%020d', $critOver, 9 - $p, $assigned); // higher priority first, then oldest
        })->values();
    }

    /**
     * Assign a one-off (ad-hoc) task to a specific staff member. Not tied to any order
     * (order_id = 0, stage = 'adhoc'). Flows through the existing My Tasks list, ops:digest,
     * and tasks:escalate automatically because those key on owner_user_id + due_at.
     */
    public function assignAdHoc(
        Tenant $tenant,
        User $assignee,
        string $title,
        ?\Illuminate\Support\Carbon $due = null,
        int $priority = 2,
        ?User $by = null,
        int $slaMinutes = 0
    ): array {
        $title = trim($title);
        if ($title === '') return ['ok' => false, 'error' => 'Task title is required.'];
        if ($assignee->tenant_id !== $tenant->id) return ['ok' => false, 'error' => 'Invalid assignee.'];

        // Default due: end of next business day, so escalation behaves sensibly.
        if (! $due) {
            $due = now()->addDay()->setTime(17, 0);
            if ($due->isSaturday()) $due->addDays(2);
            elseif ($due->isSunday()) $due->addDay();
        }

        // Base columns present since the table was created. Optional columns (priority,
        // checklist, requires_upload) are set only if the migration that adds them has run
        // on this database — keeps ad-hoc assignment working regardless of migration state.
        $attrs = [
            'tenant_id'      => $tenant->id,
            'order_id'       => 0,               // ad-hoc: no order
            'seq'            => 0,
            'stage'          => 'adhoc',
            'role'           => 'adhoc',
            'owner_user_id'  => $assignee->id,
            'title'          => mb_substr($title, 0, 180),
            'status'         => 'pending',
            'sla_minutes'    => max(0, $slaMinutes),   // used as the job's expected duration for ad-hoc tasks
            'paused_minutes' => 0,
            'assigned_at'    => now(),
            'due_at'         => $due,
        ];
        $table = (new OrderTask)->getTable();
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'requires_upload')) $attrs['requires_upload'] = false;
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'checklist'))       $attrs['checklist']       = [];
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'priority'))        $attrs['priority']        = max(0, min(3, $priority));
        // Tokenized link so the assignee can open/accept/complete from the WhatsApp nudge (no login).
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'link_nonce'))      $attrs['link_nonce']      = \Illuminate\Support\Str::random(16);

        $task = OrderTask::create($attrs);

        $this->log($tenant, 0, $task->id, 'created', $by, null, 'adhoc',
            $task->title . ' assigned to ' . ($assignee->name ?: ('user ' . $assignee->id)));

        $this->notifyAdHoc($tenant, $task, $assignee);

        return ['ok' => true, 'task_id' => $task->id];
    }

    /** WhatsApp nudge for a freshly-assigned ad-hoc task (order-free variant of notifyOwner). */
    private function notifyAdHoc(Tenant $tenant, OrderTask $task, User $assignee): void
    {
        if (! $assignee->phone || ! $tenant->whatsapp_instance) return;
        try {
            $due = $task->due_at ? $task->due_at->format('M j, H:i') : '';
            $link = $task->link_nonce ? url('/task/' . $task->link_nonce) : '';
            $msg = "📋 New task assigned to you:\n\n*" . $task->title . "*"
                . ($due ? "\nDue: " . $due : "")
                . ($link ? "\n\nOpen your task:\n" . $link : "\n\nOpen your task list to see details and mark it done when finished.");
            NotifyOwner::dispatch($tenant->id, $msg, $assignee->phone);
        } catch (\Throwable $e) {}
    }

    // ---------------------------------------------------------- tokenized (no-login) access

    /** Resolve a task by its public link nonce. Null if not found. */
    public function findByNonce(string $nonce): ?OrderTask
    {
        $nonce = trim($nonce);
        if ($nonce === '' || strlen($nonce) > 32) return null;
        // Public route has no tenant context — bypass the global tenant scope so the
        // nonce (itself the credential) resolves the task regardless of who's asking.
        return OrderTask::withoutGlobalScopes()->where('link_nonce', $nonce)->first();
    }

    /** Accept a task via its public link (no login). Idempotent-ish. */
    public function acceptByNonce(OrderTask $task): array
    {
        if (! $task->isActive()) return ['ok' => false, 'error' => 'This task is no longer active.'];
        if ($task->status === 'in_progress') return ['ok' => true, 'already' => true];
        $task->status     = 'in_progress';
        $task->started_at = $task->started_at ?: now();
        $task->accepted_at = now();
        $task->save();
        $tenant = Tenant::withoutGlobalScopes()->find($task->tenant_id);
        if ($tenant) $this->log($tenant, $task->order_id, $task->id, 'accepted', null, null, $task->stage,
            $task->title . ' accepted via link');
        return ['ok' => true];
    }

    /** Complete a task via its public link (no login). */
    public function completeByNonce(OrderTask $task, ?string $note = null, ?string $attachment = null): array
    {
        if (! $task->isActive()) return ['ok' => false, 'error' => 'This task is already closed.'];
        if ($task->requires_upload && empty($attachment) && empty($task->attachment)) {
            return ['ok' => false, 'error' => 'This task needs a photo/file uploaded before it can be closed.'];
        }
        $task->status       = 'completed';
        $task->completed_at = now();
        if ($note) $task->note = mb_substr($note, 0, 500);
        if ($attachment) $task->attachment = (string) $attachment;   // optional proof photo
        $task->save();
        $tenant = Tenant::withoutGlobalScopes()->find($task->tenant_id);
        if ($tenant) {
            $this->log($tenant, $task->order_id, $task->id, 'completed', null, $task->stage, null,
                $task->title . ' completed via link' . ($note ? ' — ' . $note : ''));
            // No logged-in user on the link path → completedBy null; notifyCompleted falls back to the assignee.
            $this->notifyCompleted($tenant, $task, null, $note);
        }
        return ['ok' => true];
    }

    /** Add a comment to a task via its public link. */
    public function commentByNonce(OrderTask $task, string $text): array
    {
        $text = trim($text);
        if ($text === '') return ['ok' => false, 'error' => 'Empty comment.'];
        $tenant = Tenant::withoutGlobalScopes()->find($task->tenant_id);
        if ($tenant) $this->log($tenant, $task->order_id, $task->id, 'comment', null, null, null,
            mb_substr($text, 0, 500));
        return ['ok' => true];
    }

    /** Attach a proof photo to a task via its public link — WITHOUT changing status (optional, any time). */
    public function attachByNonce(OrderTask $task, string $url): array
    {
        if (! $task->isActive()) return ['ok' => false, 'error' => 'This task is already closed.'];
        $task->attachment = $url;
        $task->save();
        $tenant = Tenant::withoutGlobalScopes()->find($task->tenant_id);
        if ($tenant) $this->log($tenant, $task->order_id, $task->id, 'comment', null, null, null, 'Photo attached via link');
        return ['ok' => true, 'attachment' => $url];
    }

    // ----------------------------------------------------------------- internals

    private function openStage(Tenant $tenant, ?Order $order, array $stage): OrderTask
    {
        $owner  = $this->ownerForRole($tenant, (string) $stage['role']);
        $sla    = (int) ($stage['sla'] ?? 0);
        $policy = WorkflowConfig::acceptPolicy($tenant, (string) $stage['key']);
        $status = $policy === 'required' ? 'offered' : 'pending';
        $task  = OrderTask::create([
            'tenant_id'       => $tenant->id,
            'order_id'        => $order?->id ?? 0,
            'seq'             => (int) ($stage['_index'] ?? 0),
            'stage'           => (string) $stage['key'],
            'role'            => (string) $stage['role'],
            'owner_user_id'   => $owner,
            'title'           => (string) ($stage['task'] ?? $stage['title'] ?? 'Task'),
            'status'          => $status,
            'sla_minutes'     => $sla,
            'paused_minutes'  => 0,
            'assigned_at'     => now(),
            'due_at'          => WorkflowConfig::dueAt(now(), $sla, 0),
            'requires_upload' => (bool) ($stage['upload'] ?? false),
            'checklist'       => WorkflowConfig::checklistFor($tenant, (string) $stage['key']),
            'priority'        => WorkflowConfig::priorityFor($tenant, (string) $stage['key']),
            'link_nonce'      => WorkflowConfig::isFieldStage($tenant, (string) $stage['key']) ? \Illuminate\Support\Str::random(16) : null,
        ]);
        $this->log($tenant, $task->order_id, $task->id, 'created', null, null, (string) $stage['key'],
            $task->title . ' assigned to ' . WorkflowConfig::roleLabel((string) $stage['role']));
        $this->notifyOwner($tenant, $task, $status === 'offered' ? 'New task — please accept' : 'You have a new task');
        return $task;
    }

    /** Resolve a role to the least-loaded assigned user (null if nobody set). */
    private function ownerForRole(Tenant $tenant, string $role): ?int
    {
        $candidates = WorkflowConfig::roleMap($tenant)[$role] ?? [];
        if (! $candidates) return null;
        return $this->leastLoaded($tenant, $candidates);
    }

    private function leastLoaded(Tenant $tenant, array $candidates): ?int
    {
        if (! $candidates) return null;
        $counts = OrderTask::query()->where('tenant_id', $tenant->id)
            ->whereIn('owner_user_id', $candidates)
            ->whereIn('status', OrderTask::ACTIVE)
            ->selectRaw('owner_user_id, count(*) as c')->groupBy('owner_user_id')
            ->pluck('c', 'owner_user_id')->all();
        return WorkflowConfig::pickOwner($candidates, $counts);
    }

    /** Re-offer an un-accepted field task to a *different* owner (used only when auto-reassign is on). */
    public function reoffer(Tenant $tenant, OrderTask $task): ?int
    {
        $candidates = WorkflowConfig::roleMap($tenant)[$task->role] ?? [];
        $candidates = array_values(array_filter($candidates, fn ($id) => (int) $id !== (int) $task->owner_user_id));
        if (! $candidates) return null;
        $new = $this->leastLoaded($tenant, $candidates);
        if (! $new) return null;
        $task->owner_user_id = $new;
        $task->status        = 'offered';
        $task->accepted_at   = null;
        $task->reminded_at   = null;
        $task->escalated     = false;
        $task->link_nonce    = \Illuminate\Support\Str::random(16);
        $task->assigned_at   = now();
        $task->due_at        = WorkflowConfig::dueAt(now(), (int) $task->sla_minutes, (int) $task->paused_minutes);
        $task->save();
        $this->notifyOwner($tenant, $task, 'New task — please accept');
        return $new;
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

            if (WorkflowConfig::isFieldStage($tenant, $task->stage)) {
                // Field roles (drivers) work entirely from a signed, no-login link.
                $link = $this->fieldLink($task);
                $verb = $task->status === 'offered' ? 'Tap to ACCEPT' : 'Open your task';
                $msg  = "{$lead}\n\nOrder {$no} — {$task->title}" . ($due ? "\nDue: {$due}" : '')
                      . "\n\n{$verb}:\n{$link}";
            } else {
                $msg = "{$lead}\n\nOrder {$no} — {$task->title}\nStage: " . WorkflowConfig::roleLabel($task->role)
                     . ($due ? "\nDue: {$due}" : '') . "\n\nOpen your tasks to complete it.";
            }
            NotifyOwner::dispatch($tenant->id, $msg, $u->phone);
        } catch (\Throwable $e) {
            // notification is best-effort; the task still exists in the owner's inbox
        }
    }

    /**
     * Best-effort WhatsApp confirmations when a MANUALLY-ASSIGNED (ad-hoc) task is completed, from
     * any path (the app, the no-login link, or the panel's Done button):
     *   1) a receipt to the person who did it — proof the system received it and logged it, echoing
     *      whatever note they left ("Got it! … saved in <Shop>");
     *   2) an alert to the shop's owner-alert number(s) — who finished what, plus their note.
     *
     * Scoped to ad-hoc tasks (order_id 0) so routine order-workflow stage completions don't spam.
     * Gated by the `task_done_alerts` setting (default ON). Never throws — the completion + timeline
     * log have already succeeded by the time this runs.
     */
    private function notifyCompleted(Tenant $tenant, OrderTask $task, ?User $completedBy, ?string $note): void
    {
        try {
            if ((int) $task->order_id !== 0) return;                       // only ad-hoc/assigned tasks
            if (! $tenant->whatsapp_instance) return;
            if (! (bool) $tenant->setting('task_done_alerts', true)) return;

            $assignee = $task->owner_user_id
                ? User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($task->owner_user_id)
                : null;
            $doerName = ($completedBy?->name) ?: ($assignee?->name ?: 'Someone');
            // Inline (not refLabel()) so this file is safe to deploy even without the task-ref change;
            // ref_no is null when that migration hasn't run, and we fall back to the system id.
            $ref      = '#' . ($task->ref_no ?: $task->id);
            $title    = (string) $task->title;
            $noteTxt  = $note !== null ? trim($note) : '';
            $when     = now()->format('M j, H:i');

            // 1) Receipt to the person who completed it.
            $assigneePhone = ($assignee && $assignee->phone) ? preg_replace('/[^0-9]/', '', (string) $assignee->phone) : '';
            if ($assigneePhone !== '') {
                $receipt = "✅ Got it! *{$title}* ({$ref}) is marked done and saved in " . $tenant->name . '.'
                         . ($noteTxt !== '' ? "\nYour note: " . $noteTxt : '')
                         . "\n\nThank you — nothing more needed.";
                NotifyOwner::dispatch($tenant->id, $receipt, $assigneePhone);
            }

            // 2) Alert to owner/managers — skip the assignee's own number so they don't get two messages.
            $alert = "✅ *{$doerName}* completed *{$title}* ({$ref})."
                   . ($noteTxt !== '' ? "\nNote: " . $noteTxt : '')
                   . "\n{$when} · logged in the portal.";
            foreach ($tenant->ownerAlertNumbers() as $n) {
                if ($n === $assigneePhone) continue;
                NotifyOwner::dispatch($tenant->id, $alert, $n);
            }
        } catch (\Throwable $e) {
            // best-effort notification; the completion itself already succeeded
        }
    }

    /** A signed, expiring, no-login link to a single task's field page (carries the offer nonce). */
    public function fieldLink(OrderTask $task): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'ops.field.show', now()->addHours(24), ['task' => $task->id, 'k' => $task->link_nonce]
        );
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
