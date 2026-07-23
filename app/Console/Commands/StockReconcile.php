<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Inventory\StockLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Constitution v1.0 · P0 Inventory — the safety gate before the Stock Ledger becomes the one truth.
 *
 * READ-ONLY. Compares, per product per location, the legacy on-hand (Kampala = products.stock,
 * Juba = Σ consignment_stock remaining) against the Stock Ledger projection (Σ stock_movements.qty).
 * It writes NOTHING. Run it before flipping `stock_v2`, and on a schedule after, to prove parity
 * (or surface drift) — the ledger may only become the source of on-hand once this reports clean.
 *
 * Exit code: 0 = every product within tolerance, 1 = drift found (so cron/CI can alarm).
 *
 * Known drift causes it will surface (documented in DEPLOY-STOCK-LEDGER-ENABLE.txt):
 *   • Kampala over/under-count if `stock_ledger` is on but `stock_v2` is off (receipts recorded,
 *     sales not yet issued) — flip both flags together after a fresh backfill.
 *   • Juba double-count where a demand-plan `procurement_receive` movement and the
 *     `backfill_consignment` opening balance both count the same received quantity.
 *   • Products created via bulk CSV import (Product::insert) that bypassed the observer — re-run
 *     `php artisan stock:backfill` to seed their opening balance.
 */
class StockReconcile extends Command
{
    protected $signature = 'stock:reconcile
        {tenant? : Tenant id to check (omit for all tenants with stock data)}
        {--location=all : kampala | juba | all}
        {--tolerance=0 : Absolute qty drift tolerated per product before it is flagged}
        {--limit=40 : Max drift rows to list per tenant per location}
        {--json : Emit a machine-readable JSON summary instead of tables}';

    protected $description = 'Read-only parity check: legacy on-hand vs Stock Ledger on-hand, per product/location.';

    public function handle(): int
    {
        $tol   = max(0.0, (float) $this->option('tolerance'));
        $limit = max(1, (int) $this->option('limit'));
        $loc   = strtolower((string) $this->option('location'));
        $doKampala = in_array($loc, ['all', 'kampala'], true);
        $doJuba    = in_array($loc, ['all', 'juba', 'juba-consignment'], true);

        $tenants = $this->tenants();
        if ($tenants->isEmpty()) {
            $this->warn('No matching tenant with stock data.');
            return self::SUCCESS;
        }

        $report = [];
        $anyDrift = false;

        foreach ($tenants as $t) {
            $tenantOut = [
                'tenant_id' => (int) $t->id,
                'tenant'    => (string) ($t->name ?? ('#' . $t->id)),
                'flags'     => [
                    'stock_ledger' => (bool) $t->setting('stock_ledger', false),
                    'stock_v2'     => (bool) $t->setting('stock_v2', false),
                    'issue_on_delivery' => (bool) $t->setting('stock_issue_on_delivery', true),
                ],
                'locations' => [],
            ];

            if ($doKampala) {
                $k = $this->compare(
                    (int) $t->id,
                    StockLedger::LOC_KAMPALA,
                    $this->legacyKampala((int) $t->id),
                    $this->ledgerMap((int) $t->id, StockLedger::LOC_KAMPALA),
                    $tol
                );
                $tenantOut['locations']['kampala'] = $k;
                $anyDrift = $anyDrift || $k['drift_products'] > 0;
            }
            if ($doJuba) {
                $j = $this->compare(
                    (int) $t->id,
                    StockLedger::LOC_JUBA,
                    $this->legacyJuba((int) $t->id),
                    $this->ledgerMap((int) $t->id, StockLedger::LOC_JUBA),
                    $tol
                );
                $tenantOut['locations']['juba-consignment'] = $j;
                $anyDrift = $anyDrift || $j['drift_products'] > 0;
            }

            $report[] = $tenantOut;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => ! $anyDrift, 'tolerance' => $tol, 'tenants' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $anyDrift ? self::FAILURE : self::SUCCESS;
        }

        foreach ($report as $r) {
            $this->renderTenant($r, $limit);
        }

        $this->newLine();
        if ($anyDrift) {
            $this->error('DRIFT FOUND — do not switch reads (stock_v2) until this is clean. See DEPLOY-STOCK-LEDGER-ENABLE.txt for the known causes and fixes.');
            return self::FAILURE;
        }
        $this->info('PARITY OK — ledger on-hand matches legacy within tolerance for every product checked.');
        return self::SUCCESS;
    }

    /** Tenants to check: a specific id, else every tenant that has products or movements. */
    private function tenants()
    {
        $id = $this->argument('tenant');
        if ($id !== null) {
            return Tenant::where('id', (int) $id)->get();
        }
        $withProducts = DB::table('products')->distinct()->pluck('tenant_id');
        $withMoves    = DB::table('stock_movements')->distinct()->pluck('tenant_id');
        $ids = $withProducts->merge($withMoves)->map(fn ($v) => (int) $v)->unique()->values();
        return Tenant::whereIn('id', $ids)->orderBy('id')->get();
    }

    /** Legacy Kampala on-hand: products.stock. Returns [product_id => ['qty'=>float,'name'=>..,'sku'=>..]]. */
    private function legacyKampala(int $tenantId): array
    {
        $out = [];
        DB::table('products')->where('tenant_id', $tenantId)
            ->select('id', 'name', 'sku', 'stock')
            ->orderBy('id')->chunkById(1000, function ($rows) use (&$out) {
                foreach ($rows as $p) {
                    $out[(int) $p->id] = [
                        'qty'  => (float) ($p->stock ?? 0),
                        'name' => (string) ($p->name ?? ''),
                        'sku'  => (string) ($p->sku ?? ''),
                    ];
                }
            });
        return $out;
    }

    /** Legacy Juba on-hand: Σ consignment_stock remaining, grouped by product_id. */
    private function legacyJuba(int $tenantId): array
    {
        $out = [];
        DB::table('consignment_stock')->where('tenant_id', $tenantId)
            ->select('product_id', 'name', 'qty_sent', 'qty_received', 'qty_sold')
            ->orderBy('id')->chunkById(1000, function ($rows) use (&$out) {
                foreach ($rows as $k) {
                    $pid = $k->product_id !== null ? (int) $k->product_id : 0; // 0 = unlinked line bucket
                    $base = $k->qty_received !== null ? (float) $k->qty_received : (float) $k->qty_sent;
                    $remaining = max(0.0, $base - (float) $k->qty_sold);
                    if (! isset($out[$pid])) {
                        $out[$pid] = ['qty' => 0.0, 'name' => (string) ($k->name ?? ''), 'sku' => ''];
                    }
                    $out[$pid]['qty'] += $remaining;
                }
            });
        foreach ($out as $pid => &$row) { $row['qty'] = round($row['qty'], 3); }
        return $out;
    }

    /** Ledger on-hand at a location: Σ stock_movements.qty grouped by product_id (raw, tenant-scoped). */
    private function ledgerMap(int $tenantId, string $location): array
    {
        $out = [];
        DB::table('stock_movements')
            ->where('tenant_id', $tenantId)->where('location', $location)
            ->whereNotNull('product_id')
            ->select('product_id', DB::raw('SUM(qty) as qty'))
            ->groupBy('product_id')->get()
            ->each(function ($r) use (&$out) {
                $out[(int) $r->product_id] = round((float) $r->qty, 3);
            });
        return $out;
    }

    /** Compare two on-hand maps; returns summary + the flagged drift rows. */
    private function compare(int $tenantId, string $location, array $legacy, array $ledger, float $tol): array
    {
        $ids = array_unique(array_merge(array_keys($legacy), array_keys($ledger)));
        $rows = [];
        $inParity = 0; $totalAbs = 0.0; $maxAbs = 0.0;

        foreach ($ids as $pid) {
            $l = isset($legacy[$pid]) ? (float) $legacy[$pid]['qty'] : 0.0;
            $g = $ledger[$pid] ?? 0.0;
            $drift = round($g - $l, 3);
            $abs = abs($drift);
            if ($abs <= $tol) { $inParity++; continue; }
            $totalAbs += $abs;
            $maxAbs = max($maxAbs, $abs);
            $rows[] = [
                'product_id' => (int) $pid,
                'name'       => (string) ($legacy[$pid]['name'] ?? ('#' . $pid)),
                'legacy'     => $l,
                'ledger'     => $g,
                'drift'      => $drift,
            ];
        }
        usort($rows, fn ($a, $b) => abs($b['drift']) <=> abs($a['drift']));

        return [
            'location'        => $location,
            'products'        => count($ids),
            'in_parity'       => $inParity,
            'drift_products'  => count($rows),
            'total_abs_drift' => round($totalAbs, 3),
            'max_abs_drift'   => round($maxAbs, 3),
            'rows'            => $rows,
        ];
    }

    private function renderTenant(array $r, int $limit): void
    {
        $f = $r['flags'];
        $flagStr = sprintf('stock_ledger=%s  stock_v2=%s  issue_on_delivery=%s',
            $f['stock_ledger'] ? 'ON' : 'off',
            $f['stock_v2'] ? 'ON' : 'off',
            $f['issue_on_delivery'] ? 'ON' : 'off');
        $this->newLine();
        $this->line("<options=bold>Tenant #{$r['tenant_id']} — {$r['tenant']}</>");
        $this->line("  <fg=gray>{$flagStr}</>");

        foreach ($r['locations'] as $loc => $s) {
            $tag = $s['drift_products'] === 0
                ? "<fg=green>parity</>"
                : "<fg=red>{$s['drift_products']} drifting</>";
            $this->line(sprintf('  %s — %d products · %d in parity · %s · total drift %s · max %s',
                $loc, $s['products'], $s['in_parity'], $tag, $s['total_abs_drift'], $s['max_abs_drift']));

            if (! empty($s['rows'])) {
                $head = ['Product', 'ID', 'Legacy', 'Ledger', 'Drift'];
                $body = [];
                foreach (array_slice($s['rows'], 0, $limit) as $row) {
                    $name = mb_strlen($row['name']) > 34 ? mb_substr($row['name'], 0, 33) . '…' : $row['name'];
                    $body[] = [$name, $row['product_id'],
                        $this->num($row['legacy']), $this->num($row['ledger']),
                        ($row['drift'] > 0 ? '+' : '') . $this->num($row['drift'])];
                }
                $this->table($head, $body);
                if (count($s['rows']) > $limit) {
                    $this->line(sprintf('  <fg=gray>…and %d more drifting products (raise --limit to see all).</>',
                        count($s['rows']) - $limit));
                }
            }
        }
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }
}
