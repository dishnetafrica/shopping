<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Money\Fx;
use App\Services\Money\SalesBaseCurrency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY preview of a "move the ledger to USD" migration. Writes NOTHING.
 *
 * It reports, per money table, the current native (UGX) totals and what they would become in USD:
 *   - Sales documents (orders/invoices/quotations/payments/costs) use their OWN stored usd_amount
 *     (computed at each transaction's date) — accurate, no single-rate guesswork.
 *   - Products convert at the current USD rate (equals the USD price customers already see).
 * It also flags any records missing a usd_amount / rate so nothing is silently mis-converted.
 *
 *   php artisan sales:preview-usd-migration --tenant=ID     # your tenant id
 */
final class PreviewUsdMigrationCommand extends Command
{
    protected $signature = 'sales:preview-usd-migration {--tenant= : tenant id (required)}';
    protected $description = 'READ-ONLY: preview converting the ledger from the local currency to USD (no writes).';

    public function handle(): int
    {
        $id = (int) $this->option('tenant');
        if (! $id) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $tt) {
                $this->line('  --tenant=' . $tt->id . '   ' . $tt->name . '   (currency: ' . (string) $tt->setting('currency', 'UGX') . ')');
            }
            return self::FAILURE;
        }
        $t = Tenant::find($id);
        if (! $t) { $this->error("Tenant {$id} not found."); return self::FAILURE; }

        $base   = SalesBaseCurrency::baseCurrency($t);            // e.g. USD (reporting/base)
        $native = SalesBaseCurrency::nativeCurrency($t);          // e.g. UGX (current operating)
        $rate   = (float) (Fx::forTenant($t)->ratePerUsd($native) ?: 0);

        $this->newLine();
        $this->info("USD MIGRATION PREVIEW — {$t->name} (tenant {$t->id})   [READ-ONLY, no changes made]");
        $this->line("  Base/reporting currency : {$base}");
        $this->line("  Current ledger currency : {$native}");
        $this->line("  Current rate            : " . ($rate > 0 ? number_format($rate, 2) . " {$native} per 1 USD" : 'UNAVAILABLE'));
        $this->newLine();

        $rows = [];
        $anomalies = [];

        // ---- documents that already carry an accurate stored USD value ----
        $docTables = [
            'orders'            => ['native' => 'total'],
            'invoices'          => ['native' => 'total'],
            'quotations'        => ['native' => 'total'],
            'customer_payments' => ['native' => 'amount'],
            'order_costs'       => ['native' => 'amount'],
        ];
        foreach ($docTables as $table => $cfg) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'usd_amount')) {
                if (Schema::hasTable($table)) $anomalies[] = "{$table}: no usd_amount column — would convert by rate instead";
                continue;
            }
            $q = DB::table($table)->where('tenant_id', $t->id);
            $count   = (clone $q)->count();
            $sumNat  = (float) (clone $q)->sum($cfg['native']);
            $sumUsd  = (float) (clone $q)->sum('usd_amount');
            $missing = (clone $q)->where(function ($w) { $w->whereNull('usd_amount')->orWhere('usd_amount', 0); })
                                 ->where($cfg['native'], '>', 0)->count();
            $rows[] = [$table, $count, number_format($sumNat, 0) . " {$native}", '$' . number_format($sumUsd, 2), 'stored usd_amount'];
            if ($missing > 0) $anomalies[] = "{$table}: {$missing} record(s) have a {$native} amount but no USD value — run `php artisan sales:backfill-base-currency --tenant={$t->id} --commit` FIRST.";
        }

        // ---- products: convert by the current rate (no per-row usd stored) ----
        if (Schema::hasTable('products')) {
            $q = DB::table('products')->where('tenant_id', $t->id);
            $count  = (clone $q)->count();
            $sumNat = (float) (clone $q)->sum('price');
            $sumUsd = $rate > 0 ? $sumNat / $rate : 0;
            $rows[] = ['products (price)', $count, number_format($sumNat, 0) . " {$native}", '$' . number_format($sumUsd, 2), $rate > 0 ? "÷ {$rate}" : 'NO RATE'];
            if ($rate <= 0) $anomalies[] = "products: no USD rate available — set the tenant FX rate before migrating.";
            // show a few sample products so the owner can eyeball the new prices
            $samples = (clone $q)->where('price', '>', 0)->orderByDesc('price')->limit(5)->get(['name', 'price']);
            $this->line('  Sample product prices after conversion:');
            foreach ($samples as $s) {
                $usd = $rate > 0 ? $s->price / $rate : 0;
                $this->line('    ' . str_pad(mb_substr((string) $s->name, 0, 34), 36) . number_format((float) $s->price, 0) . " {$native}  →  \$" . number_format($usd, 2));
            }
            $this->newLine();
        }

        $this->table(['Table', 'Records', 'Now (' . $native . ')', 'After (USD)', 'Method'], $rows);

        // ---- headline reconciliation the owner already knows from the UI ----
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'usd_amount')) {
            $invUsd = (float) DB::table('invoices')->where('tenant_id', $t->id)->sum('usd_amount');
            $this->line('  Invoices total after migration: $' . number_format($invUsd, 2) . '  (should match the USD figure you see on the Invoices page today)');
        }

        $this->newLine();
        if ($anomalies) {
            $this->warn('  ⚠ Before committing, resolve these:');
            foreach ($anomalies as $a) $this->line('    • ' . $a);
        } else {
            $this->info('  ✓ No blockers found. Every financial record has an accurate USD value ready.');
        }
        $this->newLine();
        $this->line('  This was READ-ONLY. Nothing changed. Review the USD figures above with your books;');
        $this->line('  once you confirm they look right, take a full DB backup and I will build the commit step');
        $this->line('  (converts in a transaction, keeps the original ' . $native . ' values for rollback, flips config to native USD).');
        $this->newLine();

        return self::SUCCESS;
    }
}
