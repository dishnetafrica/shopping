<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Product;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockProvenance;
use Illuminate\Support\Facades\DB;

/**
 * Juba Stock — one question, three columns.
 *
 *      Current stock = Purchased − Sold
 *
 * v4.99 rewrite, at the owner's instruction. What this screen showed before, and why it changed:
 *
 * ── WHAT IT USED TO SHOW ───────────────────────────────────────────────────────────────────────
 *
 * `StockLedger::onHandMap()` sums EVERY movement at the warehouse, whatever wrote it. At this shop
 * that is three different kinds of number added together:
 *
 *     purchases   (ref_type `purchase`, `procurement_receive`, …)   — a supplier bill says so
 *     sales       (ref_type `order_delivery`, `pos_return`, …)      — a customer took them
 *     typed       (`backfill_product`, `product_create`, …)         — somebody typed a figure once
 *
 * So "available" was `typed openings + purchases − sales`, and the typed part is the largest and the
 * least supported: 256 of 464 opening balances on this shop are exactly `100` — a default entered
 * once and copied down a catalogue, not 256 stock counts that happen to agree.
 *
 * ── WHAT IT SHOWS NOW ──────────────────────────────────────────────────────────────────────────
 *
 * Only the two kinds of movement with a counterparty behind them. Purchased is what a supplier
 * billed; Sold is what a customer took. Current is the subtraction, and it is on screen next to its
 * own two operands so it can be checked rather than trusted. A typed opening is reported on the
 * product line as context and is NOT added in — which is the whole point of the change.
 *
 * ── PAYMENT STATUS ─────────────────────────────────────────────────────────────────────────────
 *
 * Never consulted, here or anywhere upstream. `OrderCostController` receives stock whenever a
 * purchase line carries a product and a quantity; `pay_status` only decides `paid_native` and who
 * holds the cash. A credit purchase has always moved stock exactly like a cash one. Verified, not
 * assumed — and the same on the way out: a delivery reduces stock whether or not the customer has
 * paid. Money is Finance's question, not this screen's.
 *
 * ── NEGATIVES ARE SHOWN, NOT CLAMPED ───────────────────────────────────────────────────────────
 *
 * A product can sell more than the purchase records account for — goods were on the shelf that this
 * system never saw arrive, usually because they predate it. That is the most useful row on the
 * screen and floor()ing it to zero would hide exactly the products worth investigating.
 *
 * Read-only projection: it derives, never writes. Accurate only while the stock ledger is being
 * maintained (Settings → Stock tracking), because sales draw it down only when it is on.
 */
final class JubaStockCapability implements ProcessCapability
{
    public function key(): string { return 'juba.stock'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t    = $ctx->tenantId();
        $home = StockLedger::home($ctx->tenant);
        $acc  = $this->buckets($t, $home);

        $names = [];
        if ($acc) {
            $names = Product::where('tenant_id', $t)->whereIn('id', array_keys($acc))->pluck('name', 'id')->all();
        }

        $inStock = 0; $outOfStock = 0; $negative = 0; $units = 0.0;
        $rows = [];
        foreach ($acc as $pid => $a) {
            $bought  = round($a['in'], 3);            // supplier receipts, always positive
            $sold    = round(-$a['out'], 3);          // sales are negative in the ledger; returns net off
            $current = round($bought - $sold, 3);

            // Never purchased and never sold — the catalogue knows it, the warehouse does not.
            if (abs($bought) < 0.0005 && abs($sold) < 0.0005) { continue; }

            if ($current > 0)      { $inStock++; $units += $current; }
            elseif ($current < 0)  { $negative++; }
            else                   { $outOfStock++; }

            $typed = round($a['typed'], 3);

            $rows[] = [
                'product' => [
                    'value'  => (string) ($names[$pid] ?? ('#' . $pid)),
                    // The figure that used to be added in and no longer is — so a number that dropped
                    // today explains itself on the row rather than becoming a question.
                    'sub'    => abs($typed) >= 0.0005 ? ('opening figure typed: ' . $this->qty($typed) . ' — not counted here') : '',
                    'avatar' => false,
                ],
                'bought'  => ['value' => $this->qty($bought), 'sort' => $bought],
                'sold'    => ['value' => $this->qty($sold), 'sort' => $sold],
                'current' => [
                    'value' => $this->qty($current),
                    'sort'  => $current,
                    'tone'  => $current > 0 ? 'ok' : ($current < 0 ? 'danger' : 'default'),
                ],
            ];
        }

        // Most stock first. The counts that need attention are on the tiles, and every column sorts.
        usort($rows, fn ($a, $b) => $b['current']['sort'] <=> $a['current']['sort']);

        $columns = [
            ['key' => 'product', 'label' => 'Product', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'bought',  'label' => 'Purchased', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'sold',    'label' => 'Sold', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'current', 'label' => 'Current stock', 'align' => 'right', 'type' => 'num', 'sortable' => true],
        ];

        $ledgerOn = (bool) $ctx->tenant->setting('stock_ledger', false);
        $note = $ledgerOn
            ? 'Current stock = Purchased − Sold. Cash and credit purchases both count; payment status never affects stock.'
            : 'Turn on Settings → Stock tracking first — until then sales do not draw stock down and these figures will read high.';

        return (new WorkspacePayload)
            ->object(['type' => 'process:juba.stock', 'title' => 'Juba Stock', 'subtitle' => $note])
            ->financials([
                ['key' => 'instock', 'label' => 'In stock', 'value' => $inStock, 'tone' => 'ok', 'foot' => 'products with stock on hand'],
                ['key' => 'units', 'label' => 'Units on hand', 'value' => $this->qty(round($units, 3)), 'tone' => 'ok', 'foot' => 'total across those products'],
                ['key' => 'outof', 'label' => 'Sold out', 'value' => $outOfStock, 'tone' => $outOfStock > 0 ? 'warn' : 'ok', 'foot' => 'purchased, all sold'],
                ['key' => 'neg', 'label' => 'Sold more than bought', 'value' => $negative, 'tone' => $negative > 0 ? 'danger' : 'ok', 'foot' => 'no purchase record for the difference'],
            ])
            ->table($columns, $rows)
            ->meta(['capability' => 'juba.stock', 'ttl' => 30, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $acc = $this->buckets($ctx->tenantId(), StockLedger::home($ctx->tenant));
        $units = 0.0;
        foreach ($acc as $a) {
            $c = $a['in'] + $a['out'];
            if ($c > 0) { $units += $c; }
        }

        return ['unitsOnHand' => round($units, 3)];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    /**
     * Per product at the shop's own warehouse: supplier in, sales out, and the typed remainder.
     *
     * ONE grouped query, as the old projection was — 464 products must not become 464 queries.
     * Bucketing is StockProvenance's, unchanged and shared with `stock:proof`, so a movement source
     * added later is classified in exactly one place. Anything unrecognised lands in `typed`, which
     * means a new source is excluded from Current until somebody decides it is evidence and says so.
     *
     * @return array<int,array{in:float,out:float,typed:float}>
     */
    private function buckets(int $tid, string $home): array
    {
        try {
            $rows = DB::table('stock_movements')
                ->where('tenant_id', $tid)
                ->where('location', $home)
                ->whereNotNull('product_id')
                ->select('product_id', 'ref_type', DB::raw('SUM(qty) as qty'))
                ->groupBy('product_id', 'ref_type')
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $acc = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            $q   = (float) $r->qty;
            if (! isset($acc[$pid])) { $acc[$pid] = ['in' => 0.0, 'out' => 0.0, 'typed' => 0.0]; }

            switch (StockProvenance::bucket($r->ref_type)) {
                case StockProvenance::SUPPLIER: $acc[$pid]['in']  += $q; break;
                case StockProvenance::SALES:    $acc[$pid]['out'] += $q; break;
                // A physical count is stronger evidence than a bill, but folding it in would break
                // the arithmetic printed on the row — Purchased − Sold would no longer equal Current.
                // It is reported as context instead, with the openings.
                default:                        $acc[$pid]['typed'] += $q; break;
            }
        }

        return $acc;
    }

    private function qty($v): string
    {
        $f = (float) $v;

        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.') ?: '0';
    }
}
