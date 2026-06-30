<?php
namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\OrderTask;
use App\Models\OrderTaskEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\TaskEngine;
use App\Support\WorkflowConfig;
use Illuminate\Console\Command;

/**
 * Two responsibilities, run every ~15 min in working hours:
 *  1) OVERDUE active desk/work tasks → notify managers once (paused tasks excluded; clock frozen).
 *  2) UNACCEPTED offers (field stages) → remind the owner at ~15 min, escalate to managers at ~30 min.
 *     Auto-reassign is OFF by default — a driver may already be on the way and just forgot to tap Accept.
 */
class TaskEscalateCommand extends Command
{
    protected $signature = 'tasks:escalate {--dry}';
    protected $description = 'Escalate overdue tasks and chase un-accepted field offers.';

    public function handle(TaskEngine $engine): int
    {
        $dry = (bool) $this->option('dry');
        $sent = 0;

        Tenant::query()->whereNotNull('whatsapp_instance')->orderBy('id')->chunk(50, function ($tenants) use ($dry, $engine, &$sent) {
            foreach ($tenants as $t) {
                if (! WorkflowConfig::enabled($t)) continue;

                $cfg          = (array) ($t->setting('workflow', []) ?: []);
                $remindMin    = (int) ($cfg['offer_reminder_min'] ?? 15);
                $escalateMin  = (int) ($cfg['offer_escalate_min'] ?? 30);
                $autoReassign = (bool) ($cfg['auto_reassign'] ?? false);

                $managers = User::query()->where('tenant_id', $t->id)
                    ->whereIn('role', ['owner', 'admin', 'manager'])->whereNotNull('phone')->get();

                // ---- 1) Overdue active work tasks ----
                $overdue = OrderTask::query()->where('tenant_id', $t->id)
                    ->whereIn('status', ['pending', 'in_progress'])->where('escalated', false)
                    ->whereNotNull('due_at')->where('due_at', '<', now())->get();
                $nos = $this->orderNos($t, $overdue->pluck('order_id')->all());
                foreach ($overdue as $task) {
                    $msg = "\u{26A0} Overdue task\n\nOrder " . ($nos[$task->order_id] ?? ('#' . $task->order_id))
                         . " — {$task->title}\nStage: " . WorkflowConfig::roleLabel($task->role)
                         . "\nDue: " . optional($task->due_at)->format('M j, H:i');
                    if ($dry) { $this->line("[dry] overdue {$t->name}: {$task->title}"); $sent++; continue; }
                    foreach ($managers as $m) { try { NotifyOwner::dispatch($t->id, $msg, $m->phone); } catch (\Throwable $e) {} }
                    $task->escalated = true; $task->save();
                    $this->event($t, $task, 'escalated', 'Overdue — escalated to management');
                    $sent++;
                }

                // ---- 2) Un-accepted field offers ----
                $offered = OrderTask::query()->where('tenant_id', $t->id)->where('status', 'offered')->get();
                $onos = $this->orderNos($t, $offered->pluck('order_id')->all());
                foreach ($offered as $task) {
                    if (! WorkflowConfig::isFieldStage($t, $task->stage)) continue;
                    $age = $task->assigned_at ? $task->assigned_at->diffInMinutes(now()) : 0;
                    $no  = $onos[$task->order_id] ?? ('#' . $task->order_id);

                    // Escalate (and optionally reassign) once past the escalation window.
                    if ($age >= $escalateMin && ! $task->escalated) {
                        if ($dry) { $this->line("[dry] offer-escalate {$t->name}: {$no}"); $sent++; continue; }
                        $msg = "\u{1F6A8} Delivery not accepted\n\nOrder {$no} — {$task->title}\nOffered " . $age . " min ago, still unaccepted."
                             . ($autoReassign ? "\nRe-offering to another driver." : "\nPlease assign a driver.");
                        foreach ($managers as $m) { try { NotifyOwner::dispatch($t->id, $msg, $m->phone); } catch (\Throwable $e) {} }
                        $task->escalated = true; $task->save();
                        $this->event($t, $task, 'escalated', 'Offer unaccepted — escalated to management');
                        if ($autoReassign) {
                            $next = $engine->reoffer($t, $task);
                            if ($next) $this->event($t, $task, 'reassigned', 'Auto re-offered to another driver');
                        }
                        $sent++;
                        continue;
                    }

                    // Gentle reminder to the same owner once, before escalation.
                    if ($age >= $remindMin && ! $task->reminded_at && ! $task->escalated) {
                        if ($dry) { $this->line("[dry] offer-remind {$t->name}: {$no}"); $sent++; continue; }
                        $u = $task->owner_user_id ? User::find($task->owner_user_id) : null;
                        if ($u && $u->phone) {
                            $link = $engine->fieldLink($task);
                            $msg  = "\u{23F0} Reminder — please accept\n\nOrder {$no} — {$task->title}\n\nTap to ACCEPT:\n{$link}";
                            try { NotifyOwner::dispatch($t->id, $msg, $u->phone); } catch (\Throwable $e) {}
                        }
                        $task->reminded_at = now(); $task->save();
                        $this->event($t, $task, 'reminded', 'Reminder sent to owner');
                        $sent++;
                    }
                }
            }
        });

        $this->info("tasks:escalate — {$sent} action(s)" . ($dry ? ' (dry)' : '') . '.');
        return self::SUCCESS;
    }

    private function orderNos(Tenant $t, array $ids): array
    {
        if (! $ids) return [];
        return Order::withoutGlobalScopes()->where('tenant_id', $t->id)->whereIn('id', $ids)->pluck('order_no', 'id')->all();
    }

    private function event(Tenant $t, OrderTask $task, string $event, string $detail): void
    {
        OrderTaskEvent::create([
            'tenant_id' => $t->id, 'order_id' => $task->order_id, 'task_id' => $task->id,
            'event' => $event, 'from_stage' => $task->stage, 'detail' => $detail, 'created_at' => now(),
        ]);
    }
}
