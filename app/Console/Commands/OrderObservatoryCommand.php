<?php
namespace App\Console\Commands;

use App\Apps\Orders\Observatory\OrderObservatoryReport;
use App\Models\OrderFlowMetric;
use Illuminate\Console\Command;

/** Daily Order Engine KPI line per tenant (so "is the bot improving?" is a measured number). */
class OrderObservatoryCommand extends Command
{
    protected $signature = 'orders:observatory {--date=} {--tenant=}';
    protected $description = 'Daily Order Engine KPI report (shadow telemetry)';

    public function handle(OrderObservatoryReport $report): int
    {
        $date = $this->option('date') ?: date('Y-m-d', strtotime('yesterday'));
        $tenants = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : OrderFlowMetric::whereDate('created_at', $date)->distinct()->pluck('tenant_id')->all();

        if (! $tenants) { $this->info("orders:observatory — no telemetry for {$date}."); return self::SUCCESS; }
        foreach ($tenants as $tid) {
            $this->line($report->render((int) $tid, $date));
            foreach ($report->aliasCost((int) $tid) as $row) {
                $this->line(sprintf('     %-20s n=%-4d unresolved=%d', mb_substr($row['query'], 0, 20), $row['count'], $row['unresolved']));
            }
        }
        return self::SUCCESS;
    }
}
