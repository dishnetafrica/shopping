<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\LedgerService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class LedgerBackfill extends Command
{
    protected $signature = 'ledger:backfill {tenant? : tenant id (all if omitted)}';
    protected $description = 'Derive the double-entry general ledger from order_costs (Phase 1, safe to re-run).';

    public function handle(LedgerService $svc, TenantContext $ctx): int
    {
        $ids = $this->argument('tenant')
            ? [(int) $this->argument('tenant')]
            : Tenant::pluck('id')->all();

        foreach ($ids as $tid) {
            // OrderCost + the ledger models are tenant-scoped: activate this tenant for the whole pass so
            // the service's reads/writes resolve to it (no context ⇒ the scope filters to tenant_id IS NULL).
            $ctx->set((int) $tid);
            $r = $svc->backfillOrderCosts((int) $tid);
            $this->info("tenant {$tid}: posted {$r['posted']}, skipped {$r['skipped']}, of {$r['rows']} rows");
            foreach (array_slice($r['errors'], 0, 20) as $e) $this->warn('  ! ' . $e);
        }
        return self::SUCCESS;
    }
}
