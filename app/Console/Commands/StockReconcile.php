<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Inventory\LegacySource;
use App\Services\Inventory\MisfiledStock;
use App\Services\Inventory\StockBackfill;
use App\Services\Inventory\StockLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Constitution v1.0 · P0 Inventory — the safety gate before the Stock Ledger becomes the one truth.
 *
 * READ-ONLY. Compares, per product per location, the legacy on-hand against the Stock Ledger
 * projection (Σ stock_movements.qty). It writes NOTHING. Run it before flipping `stock_v2`, and on a
 * schedule after, to prove parity (or surface drift) — the ledger may only become the source of
 * on-hand once this reports clean.
 *
 * Exit code: 0 = every product within tolerance, 1 = drift found (so cron/CI can alarm).
 *
 * v4.65.4 — WHICH legacy figure a location is compared against now follows `stock_home_location`
 * (see LegacySource). It used to be hard-coded kampala←products.stock, juba←consignment_stock, which
 * on a Juba shop compared both locations against the wrong table and reported an entire catalogue as
 * drifting in both directions at once. A parity check that cries wolf is worse than no parity check.
 *
 * Known drift causes it will surface (documented in DEPLOY-STOCK-LEDGER-ENABLE.txt):
 *   • Over/under-count at home if `stock_ledger` is on but `stock_v2` is off (receipts recorded,
 *     sales not yet issued) — flip both flags together after a fresh backfill.
 *   • Juba double-count where a demand-plan `procurement_receive` movement and the
 *     `backfill_consignment` opening balance both count the same received quantity.
 *   • Products created via bulk CSV import (Product::insert) that bypassed the observer — re-run
 *     `php artisan stock:backfill` to seed their opening balance.
 */
class StockReconcile extends Command
{
    /**
     * Stamped into the output.
     *
     * Not decoration. This command's whole job is to be believed, and twice now a run has been read
     * as a finding about the shop when it was a finding about which copy of the file was on disk —
     * output identical to the previous release, discussed at length, from code that had already
     * been replaced. One line at the top settles it before anybody reads a number.
     */
    private const VERSION = 'v4.65.6';

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
        $want  = [
            StockLedger::LOC_KAMPALA => in_array($loc, ['all', 'kampala'], true),
            StockLedger::LOC_JUBA    => in_array($loc, ['all', 'juba', 'juba-consignment'], true),
        ];

        $tenants = $this->tenants();
        if ($tenants->isEmpty()) {
            $this->warn('No matching tenant with stock data.');
            return self::SUCCESS;
        }

        $report = [];
        $homeDrift = false;      // the ledger disagrees with the shop's own on-hand — reads are unsafe
        $misfiled  = 0.0;        // units at a location this shop does not use — real, but read by nothing

        foreach ($tenants as $t) {
            $home = StockLedger::home($t);

            $tenantOut = [
                'tenant_id' => (int) $t->id,
                'tenant'    => (string) ($t->name ?? ('#' . $t->id)),
                'home'      => $home,
                'flags'     => [
                    'stock_ledger' => (bool) $t->setting('stock_ledger', false),
                    'stock_v2'     => (bool) $t->setting('stock_v2', false),
                    'issue_on_delivery' => (bool) $t->setting('stock_issue_on_delivery', true),
                ],
                'locations' => [],
                'notes'     => $this->notes((int) $t->id, $home),
            ];

            // Home first — it is the location that decides whether reads can be switched at all.
            foreach (LegacySource::locations($home) as $location) {
                if (! ($want[$location] ?? false)) {
                    continue;
                }
                $source = LegacySource::table($home, $location);
                $s = $this->compare(
                    $location,
                    $source,
                    $this->legacy($source, (int) $t->id),
                    $this->ledgerMap((int) $t->id, $location),
                    $tol,
                    $source === LegacySource::NONE ? $this->productNames((int) $t->id) : []
                );
                $s['is_home'] = $location === $home;

                // A location with no legacy figure cannot "drift" — there is nothing to drift FROM.
                // Whatever is there was written by something, and the only useful question is what.
                $s['wrote'] = $source === LegacySource::NONE && $s['drift_products'] > 0
                    ? (new MisfiledStock())->wroteThese((int) $t->id, $location)
                    : [];

                $tenantOut['locations'][$location] = $s;

                if ($source === LegacySource::NONE) {
                    $misfiled += $s['total_abs_drift'];
                } elseif ($s['drift_products'] > 0) {
                    $homeDrift = true;
                }
            }

            $report[] = $tenantOut;
        }

        $ok = ! $homeDrift && $misfiled <= 0.0;

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'version' => self::VERSION,
                'ok' => $ok, 'home_drift' => $homeDrift, 'misfiled_units' => round($misfiled, 3),
                'tolerance' => $tol, 'tenants' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line('<fg=gray>stock:reconcile · ' . self::VERSION . ' · read-only</>');

        foreach ($report as $r) {
            $this->renderTenant($r, $limit);
        }

        // v4.65.5 — these two are not the same finding and must not share a verdict. Telling a shop
        // whose on-hand is provably correct not to switch its reads on is how a check gets ignored.
        $this->newLine();
        if ($homeDrift) {
            $this->error('DRIFT AT HOME — the ledger disagrees with the shop\'s own on-hand. Do not switch reads (stock_v2) until this is clean. See DEPLOY-STOCK-LEDGER-ENABLE.txt for the known causes and fixes.');
            return self::FAILURE;
        }
        if ($misfiled > 0.0) {
            $this->info('HOME PARITY OK — every product matches at the location this shop actually uses, so on-hand reads are correct.');
            $this->warn(sprintf('MISFILED STOCK — %s unit(s) sit at a location this shop does not use. Nothing reads them and no '
                . 'screen is affected, but they are a second copy of goods already counted at home.', $this->num($misfiled)));
            $this->line('<fg=gray>See what would be removed (writes nothing):</> php artisan stock:clear-misfiled '
                . (count($report) === 1 ? (int) $report[0]['tenant_id'] : '<tenant-id>'));
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

    /** Dispatch to the legacy table this location's on-hand actually lives in. */
    private function legacy(string $source, int $tenantId): array
    {
        return match ($source) {
            LegacySource::PRODUCTS    => $this->legacyProducts($tenantId),
            LegacySource::CONSIGNMENT => $this->legacyConsignment($tenantId),
            default                   => [],
        };
    }

    /**
     * The shop's own warehouse: products.stock.
     *
     * Not "Kampala". This is the column the product screen shows, a sale decrements and the backfill
     * seeds, and since v4.64 all three follow `stock_home_location` together.
     *
     * @return array<int,array{qty:float,name:string,sku:string}>
     */
    private function legacyProducts(int $tenantId): array
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

    /** Goods out on consignment and not yet sold: Σ consignment_stock remaining, by product_id. */
    private function legacyConsignment(int $tenantId): array
    {
        $out = [];
        DB::table('consignment_stock')->where('tenant_id', $tenantId)
            // v4.65.3 — `id` must be SELECTED, not merely ordered by: chunkById paginates on the
            // value it reads back from each row, and Laravel throws rather than silently looping
            // when it is absent. Latent since this command was written, and only reachable on a
            // tenant that actually has consignment rows — which is why it survived to here.
            ->select('id', 'product_id', 'name', 'qty_sent', 'qty_received', 'qty_sold')
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

    /**
     * Product names, for a location that has no legacy map to take them from.
     *
     * v4.65.5 — the misfiled rows printed as `#67394` because names had only ever been read off the
     * legacy side, and a location with no legacy source has no legacy side. A list of bare ids is
     * not something anybody can act on: the whole decision is "are these the same goods I already
     * count at home", and that is a question about names.
     *
     * @return array<int,string>
     */
    private function productNames(int $tenantId): array
    {
        $out = [];
        DB::table('products')->where('tenant_id', $tenantId)->select('id', 'name')
            ->orderBy('id')->chunkById(1000, function ($rows) use (&$out) {
                foreach ($rows as $p) { $out[(int) $p->id] = (string) ($p->name ?? ''); }
            });
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

    /**
     * Facts about the openings themselves, which the per-product comparison cannot show.
     *
     * Both of these describe a ledger that is internally consistent and still wrong, which is the
     * only kind of wrong a parity check is blind to by construction.
     *
     * @return array<int,string>
     */
    private function notes(int $tenantId, string $home): array
    {
        $out = [];

        // Openings written by a backfill that ran before v4.65.4, when a Juba-home shop had its
        // consignment lines seeded into Juba ON TOP of products.stock — the same cartons twice.
        if (LegacySource::doubleSeeds($home)) {
            $n = (int) DB::table('stock_movements')->where('tenant_id', $tenantId)
                ->where('ref_type', StockBackfill::REF_CONSIGNMENT)->count();
            if ($n > 0) {
                $out[] = "{$n} consignment opening balance(s) sit at juba-consignment on a shop whose home IS juba — "
                    . "the same goods are counted twice, once from products.stock and once from the consignment line. "
                    . "Remove them with:  php artisan stock:backfill {$tenantId} --drop-consignment-openings";
            }
        }

        // An opening balance is whatever products.stock happened to say. If one large value repeats
        // across most of the catalogue it was typed once and copied, and every total built on it is
        // fiction that looks exactly like data.
        $openings = DB::table('stock_movements')->where('tenant_id', $tenantId)
            ->where('ref_type', StockBackfill::REF_PRODUCT)
            ->pluck('qty', 'ref_id')->map(fn ($v) => (float) $v)->all();

        if ($p = LegacySource::placeholder($openings)) {
            $out[] = sprintf(
                '%d of %d opening balances are exactly %s (%d%%). That is a default someone typed, not %d stock counts '
                . 'that agree — the ledger cannot tell the difference and neither can this check. Count the few products '
                . 'that matter by value and correct them before trusting any total.',
                $p['count'], count($openings), $this->num($p['value']),
                (int) round($p['share'] * 100), $p['count']
            );
        }

        return $out;
    }

    /** Compare two on-hand maps; returns summary + the flagged drift rows. */
    private function compare(string $location, string $source, array $legacy, array $ledger, float $tol, array $names = []): array
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
                'name'       => (string) ($legacy[$pid]['name'] ?? $names[$pid] ?? ('#' . $pid)),
                'legacy'     => $l,
                'ledger'     => $g,
                'drift'      => $drift,
            ];
        }
        usort($rows, fn ($a, $b) => abs($b['drift']) <=> abs($a['drift']));

        return [
            'location'        => $location,
            'source'          => $source,
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
        $flagStr = sprintf('home=%s  stock_ledger=%s  stock_v2=%s  issue_on_delivery=%s',
            $r['home'],
            $f['stock_ledger'] ? 'ON' : 'off',
            $f['stock_v2'] ? 'ON' : 'off',
            $f['issue_on_delivery'] ? 'ON' : 'off');
        $this->newLine();
        $this->line("<options=bold>Tenant #{$r['tenant_id']} — {$r['tenant']}</>");
        $this->line("  <fg=gray>{$flagStr}</>");

        foreach ($r['locations'] as $loc => $s) {
            // Naming the source is the whole point of this release: a reader has to be able to see
            // WHICH two numbers were compared before deciding whether their difference means anything.
            $against = $s['source'] === LegacySource::NONE
                ? 'no legacy figure — this shop does not keep stock here'
                : 'vs ' . $s['source'];
            $tag = $s['drift_products'] === 0
                ? '<fg=green>parity</>'
                : ($s['source'] === LegacySource::NONE
                    ? "<fg=yellow>{$s['drift_products']} misfiled</>"
                    : "<fg=red>{$s['drift_products']} drifting</>");
            $this->line(sprintf('  %s%s <fg=gray>(%s)</> — %d products · %d in parity · %s · total drift %s · max %s',
                $loc, $s['is_home'] ? ' <fg=cyan>[home]</>' : '', $against,
                $s['products'], $s['in_parity'], $tag, $s['total_abs_drift'], $s['max_abs_drift']));

            // ── a location the shop does not use ───────────────────────────────────────────────
            // Three hundred rows of "legacy 0, ledger 1000" is the same sentence three hundred
            // times. What decides the outcome is who wrote them, so that is what gets the table.
            if ($s['source'] === LegacySource::NONE && $s['drift_products'] > 0) {
                $this->line('  <fg=yellow>  ↑ these are movements at a location this shop does not use. Not drift — misfiled stock.</>');

                if (! empty($s['wrote'])) {
                    $this->line('  <fg=gray>what wrote them:</>');
                    $this->table(
                        ['Written by', 'Movement', 'Hand', 'Rows', 'Units', 'First', 'Last'],
                        array_map(fn ($w) => [$w['ref_type'], $w['type'], $w['created_by'],
                            $w['n'], $w['units'], $w['first_at'], $w['last_at']], $s['wrote'])
                    );
                }

                $peek = min(5, count($s['rows']));
                $this->line(sprintf('  <fg=gray>%d product(s), e.g.:</>', count($s['rows'])));
                foreach (array_slice($s['rows'], 0, $peek) as $row) {
                    $this->line(sprintf('    <fg=gray>%s — %s at %s</>',
                        $row['name'], $this->num($row['ledger']), $s['location']));
                }
                if (count($s['rows']) > $peek) {
                    $this->line(sprintf('    <fg=gray>…and %d more (--json for all).</>', count($s['rows']) - $peek));
                }
                continue;
            }

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

        foreach ($r['notes'] as $note) {
            $this->newLine();
            $this->line('  <fg=yellow>! ' . wordwrap($note, 96, "\n    ") . '</>');
        }
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }
}
