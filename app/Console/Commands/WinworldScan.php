<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Winworld\WinworldNotifier;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Win World scheduled sweep — delivery delay-risk + sales SLA-breach alerts.
 * Schedule: Schedule::command('ww:scan')->everyThirtyMinutes(); (routes/console.php)
 */
class WinworldScan extends Command
{
    protected $signature = 'ww:scan';
    protected $description = 'Win World: scan for delivery delay risk and sales SLA breaches, then alert.';

    public function handle(TenantContext $ctx, WinworldNotifier $notifier): int
    {
        $total = 0;
        foreach (Tenant::all() as $t) {
            if (! (bool) $t->setting('module_winworld', false)) continue;
            $ctx->set($t->id);
            $d = $notifier->scanDelays($t);
            $s = $notifier->scanSalesSla($t);
            if ($d || $s) $this->info("{$t->name}: {$d} delay, {$s} SLA alert(s)");
            $total += $d + $s;
        }
        $this->info("ww:scan done — {$total} alert(s).");
        return self::SUCCESS;
    }
}
