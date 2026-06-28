<?php
namespace App\Console\Commands;

use App\Models\DailyMenu;
use App\Apps\DailyMenu\MenuParity;
use Illuminate\Console\Command;

/**
 * Diagnostic: rebuild the Daily Menu projection from the knowledge store and diff it against the
 * live projection, per tenant for a date. Logs mismatches (no auto-repair in Phase 1).
 */
class OkeParityCommand extends Command
{
    protected $signature = 'oke:parity {--date=}';
    protected $description = 'Verify projections match the knowledge store (diagnostic)';

    public function handle(MenuParity $parity): int
    {
        $date = $this->option('date') ?: date('Y-m-d');
        $mismatches = 0; $checked = 0;

        DailyMenu::where('menu_date', $date)->pluck('tenant_id')->unique()->each(function ($tenantId) use ($parity, $date, &$mismatches, &$checked) {
            $checked++;
            $res = $parity->checkDailyMenu((int) $tenantId, $date);
            if (! $res['match']) { $mismatches++; $this->warn("parity mismatch: tenant {$tenantId} on {$date}"); }
        });

        $this->info("oke:parity checked {$checked} tenant(s), {$mismatches} mismatch(es) for {$date}.");
        return self::SUCCESS;
    }
}
