<?php
namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\OrderTask;
use App\Models\OrderTaskEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\WorkflowConfig;
use Illuminate\Console\Command;

/**
 * Escalate overdue tasks to management. An active task past its SLA (paused tasks are
 * excluded — their clock is frozen) notifies the tenant's owner/manager once. Marked
 * escalated so it never re-nags. Scheduled every 30 min during working hours.
 */
class TaskEscalateCommand extends Command
{
    protected $signature = 'tasks:escalate {--dry}';
    protected $description = 'Notify managers about overdue operational tasks.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $sent = 0;

        Tenant::query()->whereNotNull('whatsapp_instance')->orderBy('id')->chunk(50, function ($tenants) use ($dry, &$sent) {
            foreach ($tenants as $t) {
                if (! WorkflowConfig::enabled($t)) continue;

                $overdue = OrderTask::query()->where('tenant_id', $t->id)
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->where('escalated', false)
                    ->whereNotNull('due_at')->where('due_at', '<', now())
                    ->get();
                if ($overdue->isEmpty()) continue;

                $managers = User::query()->where('tenant_id', $t->id)
                    ->whereIn('role', ['owner', 'admin', 'manager'])
                    ->whereNotNull('phone')->get();

                $orderNos = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                    ->whereIn('id', $overdue->pluck('order_id')->all())->pluck('order_no', 'id')->all();

                foreach ($overdue as $task) {
                    $no   = $orderNos[$task->order_id] ?? ('#' . $task->order_id);
                    $owner = $task->owner_user_id ? (User::find($task->owner_user_id)?->name ?: 'unassigned') : 'unassigned';
                    $msg  = "\u{26A0} Overdue task\n\nOrder {$no} — {$task->title}\nStage: " . WorkflowConfig::roleLabel($task->role)
                          . "\nOwner: {$owner}\nDue: " . optional($task->due_at)->format('M j, H:i');

                    if ($dry) { $this->line("[dry] {$t->name}: {$msg}"); $sent++; continue; }

                    foreach ($managers as $m) {
                        try { NotifyOwner::dispatch($t->id, $msg, $m->phone); } catch (\Throwable $e) {}
                    }
                    $task->escalated = true; $task->save();
                    OrderTaskEvent::create([
                        'tenant_id' => $t->id, 'order_id' => $task->order_id, 'task_id' => $task->id,
                        'event' => 'escalated', 'from_stage' => $task->stage,
                        'detail' => 'Overdue — escalated to management', 'created_at' => now(),
                    ]);
                    $sent++;
                }
            }
        });

        $this->info("tasks:escalate — {$sent} escalated" . ($dry ? ' (dry)' : '') . '.');
        return self::SUCCESS;
    }
}
