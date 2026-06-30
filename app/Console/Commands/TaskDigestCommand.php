<?php
namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\OrderTask;
use App\Models\Tenant;
use App\Models\User;
use App\Support\WorkflowConfig;
use Illuminate\Console\Command;

/**
 * Role-aware morning digest. One engine, different summaries:
 *  - drivers/field: "3 deliveries today"
 *  - desk staff:    "You have 12 tasks · 2 overdue"
 *  - managers:      personal line + "Team today: Orders / Blocked / Overdue"
 */
class TaskDigestCommand extends Command
{
    protected $signature = 'ops:digest {--tenant=} {--dry}';
    protected $description = 'Send each person their role-aware operations summary.';

    public function handle(): int
    {
        $dry  = (bool) $this->option('dry');
        $only = $this->option('tenant');
        $sent = 0;

        $q = Tenant::query()->whereNotNull('whatsapp_instance');
        if ($only) $q->where('id', (int) $only);

        $q->orderBy('id')->chunk(50, function ($tenants) use ($dry, &$sent) {
            foreach ($tenants as $t) {
                if (! WorkflowConfig::enabled($t)) continue;

                $active = OrderTask::query()->where('tenant_id', $t->id)->whereIn('status', OrderTask::ACTIVE)->get();

                $fieldStages = [];
                foreach (WorkflowConfig::stages($t) as $s) if (WorkflowConfig::isFieldStage($t, (string) $s['key'])) $fieldStages[] = $s['key'];

                $ordersToday = Order::withoutGlobalScopes()->where('tenant_id', $t->id)->whereDate('created_at', now()->toDateString())->count();
                $blocked     = $active->whereIn('status', ['paused', 'exception'])->count();
                $overdueAll  = $active->filter(fn ($x) => $x->isOverdue())->count();

                $managerIds = User::query()->where('tenant_id', $t->id)->whereIn('role', ['owner', 'admin', 'manager'])->pluck('id')->all();
                $ownerIds   = $active->pluck('owner_user_id')->filter()->unique()->all();
                $recipients = User::query()->where('tenant_id', $t->id)
                    ->whereIn('id', array_values(array_unique(array_merge($managerIds, $ownerIds))))
                    ->whereNotNull('phone')->get();

                foreach ($recipients as $u) {
                    $mine       = $active->where('owner_user_id', $u->id);
                    $n          = $mine->count();
                    $od         = $mine->filter(fn ($x) => $x->isOverdue())->count();
                    $deliveries = $mine->filter(fn ($x) => in_array($x->stage, $fieldStages, true))->count();
                    $isManager  = in_array($u->role, ['owner', 'admin', 'manager'], true);

                    $body = null;
                    if ($n > 0) {
                        $body = ($deliveries === $n && $deliveries > 0)
                            ? "{$deliveries} " . ($deliveries === 1 ? 'delivery' : 'deliveries') . " today."
                            : "You have {$n} task" . ($n === 1 ? '' : 's') . ($od ? " \u{00B7} {$od} overdue" : '') . '.';
                    } elseif (! $isManager) {
                        $body = 'No tasks assigned right now.';
                    }

                    $msg = 'Good morning' . ($u->name ? ' ' . $u->name : '');
                    if ($body) $msg .= "\n\n{$body}";
                    if ($isManager) $msg .= "\n\nTeam today:\nOrders {$ordersToday} \u{00B7} Blocked {$blocked} \u{00B7} Overdue {$overdueAll}";

                    if ($dry) { $this->line("[dry] {$t->name} -> " . ($u->name ?: $u->phone) . ': ' . str_replace("\n", ' | ', $msg)); $sent++; continue; }
                    try { NotifyOwner::dispatch($t->id, $msg, $u->phone); } catch (\Throwable $e) {}
                    $sent++;
                }
            }
        });

        $this->info("ops:digest — {$sent} summary(ies)" . ($dry ? ' (dry)' : '') . '.');
        return self::SUCCESS;
    }
}
