<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tender\TenderScout;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v4.19 TenderBot — daily forum sweep for every tenant that switched tender watching on.
 * Scheduled in routes/console.php; also runnable by hand: php artisan tenders:scan
 *
 * This is a PLATFORM operation (it processes many tenants in one run), so it establishes the
 * tenant context explicitly for each tenant before touching any tenant-scoped service. The
 * whole scan chain — TenderScout::scan() → TenderAnalyzer (Tender dedup + create) →
 * TenderItems::sync() (TenderItem read/delete/create/count) → TenderTasks::assignSourcing()
 * (TenderItem + User reads) — runs synchronously inside one loop iteration, so one set() at the
 * top of the iteration scopes all of it. Without it, now that Tender/TenderItem carry the
 * BelongsToTenant global scope, a console run (which has no request middleware, so no context)
 * would read `AND tenant_id = NULL` → zero rows: dedup always misses (duplicate tenders), sync()
 * reports 0 items even after inserting rows, and sourcing is never assigned. The context is
 * cleared in a finally so no tenant's id leaks into the next iteration on a long-lived worker.
 */
class TendersScanCommand extends Command
{
    protected $signature = 'tenders:scan {--tenant= : only this tenant id}';

    protected $description = 'Scan the configured tender forum(s) for new opportunities and analyse them';

    public function handle(TenantContext $ctx): int
    {
        $q = Tenant::query();
        if ($this->option('tenant')) {
            $q->where('id', (int) $this->option('tenant'));
        }
        $scout = new TenderScout();
        foreach ($q->get() as $tenant) {
            // Scope the entire iteration (dedup read, item sync, sourcing assignment) to this tenant.
            $ctx->set($tenant->id);
            try {
                if (! (bool) $tenant->setting('tender_enabled', false)) {
                    continue;
                }
                $res = $scout->scan($tenant);
                $this->info(sprintf('[%d] %s — fresh:%d new:%d analysed:%d %s',
                    $tenant->id, $tenant->name, $res['scanned'], $res['new'], count($res['analysed']), $res['note']));
            } catch (\Throwable $e) {
                $this->warn("[{$tenant->id}] scan failed: " . $e->getMessage());
            } finally {
                // Never carry this tenant's id into the next iteration (or past the command on a
                // long-lived worker). This path never sets super-admin, so clear() is sufficient.
                $ctx->clear();
            }
        }

        return self::SUCCESS;
    }
}
