<?php
namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Jobs\SendCampaign;
use App\Models\Campaign;
use App\Models\Order;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Runs every minute (see routes/console.php). Two jobs:
 *  1. Walk scheduled orders through the prep workflow, alerting the owner at
 *     2h-before, 30m-before and at delivery time (each fired once).
 *  2. Dispatch any marketing campaigns whose scheduled time has arrived.
 */
class ProcessScheduled extends Command
{
    protected $signature   = 'shopbot:process-scheduled';
    protected $description = 'Advance scheduled orders and dispatch due campaigns.';

    public function handle(TenantContext $ctx): int
    {
        $ctx->asSuperAdmin();   // span all tenants
        $now = now();

        $orders = Order::whereNotNull('scheduled_for')
            ->whereNull('delivered_at')
            ->where('scheduled_for', '>', $now->copy()->subHours(2))
            ->where('scheduled_for', '<', $now->copy()->addHours(3))
            ->get();
        foreach ($orders as $o) {
            $this->tickOrder($o, $now);
        }

        $due = Campaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $now)
            ->get();
        foreach ($due as $c) {
            $c->update(['status' => 'sending']);
            SendCampaign::dispatch($c->tenant_id, $c->id);
        }

        $this->info('Processed ' . $orders->count() . ' scheduled orders, dispatched ' . $due->count() . ' campaigns.');

        // Keep the diagnostics trail lean.
        try {
            \Illuminate\Support\Facades\DB::table('bot_events')
                ->where('created_at', '<', now()->subDays(3))->delete();
        } catch (\Throwable $e) { /* table may not exist yet */ }

        return self::SUCCESS;
    }

    protected function tickOrder(Order $o, $now): void
    {
        $r     = is_array($o->sched_reminders) ? $o->sched_reminders : [];
        $mins  = ($o->scheduled_for->getTimestamp() - $now->getTimestamp()) / 60;   // future = positive
        $label = '#' . $o->order_no
            . ($o->customer_name ? ' · ' . $o->customer_name : '')
            . ($o->location ? ' · ' . $o->location : '');
        $changed = false;

        if ($mins <= 120 && empty($r['h2'])) {
            $r['h2'] = true; $o->sched_stage = 'Preparing'; $changed = true;
            NotifyOwner::dispatch($o->tenant_id,
                "⏰ Order needs preparation\n{$label}\nScheduled for " . $o->scheduled_for->format('D g:i A') . ". Start getting it ready.");
        }
        if ($mins <= 30 && empty($r['m30'])) {
            $r['m30'] = true; $o->sched_stage = 'Ready For Dispatch'; $changed = true;
            NotifyOwner::dispatch($o->tenant_id,
                "🛵 Assign a rider now\n{$label}\nScheduled for " . $o->scheduled_for->format('g:i A') . ". Pack it and assign a rider.");
        }
        if ($mins <= 0 && empty($r['due'])) {
            $r['due'] = true; $o->sched_stage = 'Out For Delivery'; $changed = true;
            NotifyOwner::dispatch($o->tenant_id,
                "🚀 Dispatch now\n{$label}\nDelivery time has arrived — send it out.");
        }

        if ($changed) {
            $o->sched_reminders = $r;
            $o->save();
        }
    }
}
