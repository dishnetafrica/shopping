<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inventory\StockBackfill;
use Illuminate\Console\Command;

/**
 * Constitution v1.0 · P0 Inventory — on-demand wrapper around the (idempotent) StockBackfill service.
 *
 * Seeds ONE opening-balance IN movement per stocked product (Kampala) and per consignment line's
 * remaining qty (Juba), so the ledger reproduces today's on-hand before it becomes the source of truth.
 *
 * Idempotent: an opening row is keyed by (ref_type, ref_id) and skipped if already present, so this is
 * safe to re-run — in particular after a bulk CSV import (Product::insert bypasses the stock observer,
 * so imported products need their opening balance seeded here).
 *
 * Until now this only ran inside a migration; this exposes it as `php artisan stock:backfill`.
 */
class StockBackfillCommand extends Command
{
    protected $signature = 'stock:backfill
        {tenant? : Tenant id to seed (omit for every tenant)}';

    protected $description = 'Seed the Stock Ledger opening balances from legacy on-hand (idempotent, re-runnable).';

    public function handle(): int
    {
        $service = new StockBackfill();
        $id = $this->argument('tenant');

        if ($id !== null) {
            $n = $service->runForTenant((int) $id);
            $this->info("Seeded {$n} opening movement(s) for tenant #{$id}.");
        } else {
            $n = $service->run();
            $this->info("Seeded {$n} opening movement(s) across all tenants.");
        }

        $this->line('<fg=gray>Idempotent — already-seeded lines were skipped. Now run:</> php artisan stock:reconcile');
        return self::SUCCESS;
    }
}
