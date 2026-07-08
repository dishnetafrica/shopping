<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\LedgerService;
use Illuminate\Console\Command;

class LedgerBackfill extends Command
{
    protected $signature = 'ledger:backfill {tenant? : tenant id (all if omitted)}';
    protected $description = 'Derive the double-entry general ledger from order_costs (Phase 1, safe to re-run).';

    public function handle(LedgerService $svc): int
    {
        $ids = $this->argument('tenant')
            ? [(int) $this->argument('tenant')]
            : Tenant::pluck('id')->all();

        foreach ($ids as $tid) {
            $r = $svc->backfillOrderCosts((int) $tid);
            $this->info("tenant {$tid}: posted {$r['posted']}, skipped {$r['skipped']}, of {$r['rows']} rows");
            foreach (array_slice($r['errors'], 0, 20) as $e) $this->warn('  ! ' . $e);
        }
        return self::SUCCESS;
    }
}
