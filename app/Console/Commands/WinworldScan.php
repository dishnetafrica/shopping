<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Winworld\WinworldNotifier;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Win World scheduled sweep — delivery delay-risk alerts.
 * Schedule: Schedule::command('ww:scan')->everyThirtyMinutes(); (see routes/console.php)
 */
class WinworldScan extends Command
{
    protected $signature = 'ww:scan';
    protected $description = 'Win World: scan active indents for delivery delay risk and alert.';

    public function handle(TenantContext $ctx, WinworldNotifier $notifier): int
    {
        $total = 0;
        foreach (Tenant::all() as $t) {
            if (! (bool) $t->setting('module_winworld', false)) continue;
            $ctx->set($t->id);                       // scope queries + alerts to this tenant
            $n = $notifier->scanDelays($t);
            if ($n) $this->info("{$t->name}: {$n} delay alert(s)");
            $total += $n;
        }
        $this->info("ww:scan done — {$total} alert(s).");
        return self::SUCCESS;
    }
}
