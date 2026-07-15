<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Services\Money\SalesBaseCurrency;
use Illuminate\Console\Command;

/**
 * Backfill the base-currency ledger fields (rate_to_usd + usd_amount) on EXISTING sales documents
 * (orders, invoices, quotations). Purely additive — never changes the transaction amount or currency.
 *
 * SAFE BY DEFAULT: runs as a DRY RUN (no writes) and prints a per-tenant reconciliation. Re-run with
 * --commit to actually write. Reversible: the columns can be dropped via the migration's down().
 *
 *   php artisan sales:backfill-base-currency                 # dry run, all tenants
 *   php artisan sales:backfill-base-currency --tenant=5      # dry run, one tenant
 *   php artisan sales:backfill-base-currency --commit        # write
 *   php artisan sales:backfill-base-currency --force --commit # re-stamp even rows that already have usd_amount
 */
final class BackfillSalesBaseCurrencyCommand extends Command
{
    protected $signature = 'sales:backfill-base-currency
        {--tenant= : only this tenant id (default: all)}
        {--commit : actually write (default is a dry run that only reports)}
        {--force : recompute even rows that already have usd_amount}';

    protected $description = 'Store the exchange rate + USD base amount on existing sales documents (dry run unless --commit).';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $force = (bool) $this->option('force');
        $tenants = $this->option('tenant')
            ? Tenant::where('id', (int) $this->option('tenant'))->get()
            : Tenant::all();

        $this->info(($commit ? 'COMMIT' : 'DRY RUN') . ' — base-currency backfill for ' . $tenants->count() . ' tenant(s)');
        $this->newLine();

        $models = ['orders' => Order::class, 'invoices' => Invoice::class, 'quotations' => Quotation::class];
        $grand = 0;

        foreach ($tenants as $t) {
            $base = SalesBaseCurrency::baseCurrency($t);
            $native = SalesBaseCurrency::nativeCurrency($t);
            $rows = [];
            $tenantTouched = 0;

            foreach ($models as $label => $class) {
                $q = $class::where('tenant_id', $t->id);
                if (! $force) { $q->whereNull('usd_amount'); }
                $count = 0; $sumUsd = 0.0; $sample = null;

                $q->orderBy('id')->chunkById(500, function ($chunk) use ($t, $commit, $force, &$count, &$sumUsd, &$sample) {
                    foreach ($chunk as $doc) {
                        $c = SalesBaseCurrency::compute($doc, $t);
                        if ((float) ($doc->total ?? 0) <= 0) { continue; }
                        $count++;
                        $sumUsd += (float) $c['usd_amount'];
                        if ($sample === null) {
                            $sample = number_format((float) $doc->total, 0) . ' ' . $c['currency'] . ' → $' . number_format((float) $c['usd_amount'], 2);
                        }
                        if ($commit) {
                            SalesBaseCurrency::stamp($doc, $t, $force);
                            $doc->saveQuietly();   // don't fire observers/events during a bulk backfill
                        }
                    }
                });

                $rows[] = [$label, $count, '$' . number_format($sumUsd, 2), (string) $sample];
                $tenantTouched += $count;
            }

            if ($tenantTouched === 0) { continue; }
            $grand += $tenantTouched;
            $this->line("<info>Tenant {$t->id}</info> — {$t->name}  (base {$base}, local {$native})");
            $this->table(['Document', 'Rows', 'Σ base (USD)', 'Sample'], $rows);
            $this->newLine();
        }

        $this->info(($commit ? 'Wrote' : 'Would write') . " base-currency fields on {$grand} document(s).");
        if (! $commit && $grand > 0) {
            $this->warn('This was a DRY RUN. Review the numbers above, then re-run with --commit to write.');
        }

        return self::SUCCESS;
    }
}
