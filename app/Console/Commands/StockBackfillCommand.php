<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inventory\StockBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Constitution v1.0 · P0 Inventory — on-demand wrapper around the (idempotent) StockBackfill service.
 *
 * Seeds ONE opening-balance IN movement per stocked product (at the shop's home location) and per
 * consignment line's remaining qty (at Juba, unless Juba IS home — see StockBackfill), so the ledger
 * reproduces today's on-hand before it becomes the source of truth.
 *
 * Idempotent: an opening row is keyed by (ref_type, ref_id) and skipped if already present, so this is
 * safe to re-run — in particular after a bulk CSV import (Product::insert bypasses the stock observer,
 * so imported products need their opening balance seeded here).
 *
 * v4.65.4 — omitting the tenant argument now asks first. It seeds EVERY tenant, which is a reasonable
 * thing to want and a terrible thing to do by accident: a shop that has never been told about stock
 * tracking gets a ledger seeded from whatever its product screen happens to say, and nobody who could
 * spot that the figures are placeholders is watching. Ask for a tenant id and the question is skipped.
 */
class StockBackfillCommand extends Command
{
    protected $signature = 'stock:backfill
        {tenant? : Tenant id to seed (omit for every tenant)}
        {--drop-consignment-openings : Remove backfill_consignment openings on a Juba-home shop (v4.65.4 double-seed)}
        {--force : Skip the confirmation when no tenant is given}';

    protected $description = 'Seed the Stock Ledger opening balances from legacy on-hand (idempotent, re-runnable).';

    public function handle(): int
    {
        $service = new StockBackfill();
        $id = $this->argument('tenant');

        if ($this->option('drop-consignment-openings')) {
            return $this->drop($service, $id);
        }

        if ($id === null && ! $this->option('force')) {
            $n = (int) DB::table('tenants')->count();
            $this->warn("No tenant given — this will seed opening balances for all {$n} tenants.");
            $this->line('<fg=gray>Each one gets a ledger built from whatever its product screen currently says.</>');
            if (! $this->confirm('Seed every tenant?', false)) {
                $this->line('Nothing was written. Run it for one shop:  php artisan stock:backfill <tenant-id>');
                return self::SUCCESS;
            }
        }

        if ($id !== null) {
            $n = $service->runForTenant((int) $id);
            $this->info("Seeded {$n} opening movement(s) for tenant #{$id}.");
        } else {
            $n = $service->run();
            $this->info("Seeded {$n} opening movement(s) across all tenants.");
        }

        $this->line('<fg=gray>Idempotent — already-seeded lines were skipped. Now run:</> php artisan stock:reconcile' . ($id !== null ? ' ' . (int) $id : ''));
        return self::SUCCESS;
    }

    /** Withdraw the consignment openings a pre-v4.65.4 backfill double-seeded on a Juba-home shop. */
    private function drop(StockBackfill $service, $id): int
    {
        if ($id === null) {
            $this->error('A tenant id is required for --drop-consignment-openings. This one is not a sweep.');
            return self::FAILURE;
        }

        $n = $service->dropConsignmentOpenings((int) $id);
        if ($n < 0) {
            $this->error("Tenant #{$id} does not keep its stock in Juba, so its consignment openings are the only "
                . 'record of the away position. Nothing was touched.');
            return self::FAILURE;
        }

        $this->info("Removed {$n} consignment opening movement(s) from tenant #{$id}.");
        $this->line('<fg=gray>Their goods are already counted at Juba from products.stock. Now run:</> '
            . "php artisan stock:reconcile {$id}");
        return self::SUCCESS;
    }
}
