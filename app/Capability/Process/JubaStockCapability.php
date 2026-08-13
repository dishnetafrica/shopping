<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Juba Stock — Current = Purchased − Sold, read from the purchase and sales RECORDS.
 *
 * ── why not the stock ledger ────────────────────────────────────────────────────────────────────
 *
 * The first version of this rewrite read `stock_movements`, and the owner's own data killed it:
 *
 *     juba-consignment   backfill_product   432 rows   224,181 units    typed openings
 *     juba-consignment   purchase            44 rows       128 units    ← every purchase the ledger knows
 *     juba-consignment   order_delivery      35 rows       -75 units
 *     kampala            purchase             3 rows         3 units
 *
 * Two things are wrong with using that as the source.
 *
 * **It is nearly empty.** A purchase only posts a stock movement if it was SAVED after v4.64 went in
 * (Aug 2026) — `StockReceiver` runs on save, and relinking an old line to a product does not go back
 * and write the receipt it never wrote. So the ledger holds 128 units of buying, while `order_costs`
 * holds the whole history. A screen built on the ledger answers "what did we buy since August", which
 * is not the question.
 *
 * **And it depends on a setting nobody set.** `stock_home_location` is NULL on this shop, so
 * `StockLedger::home()` returns its default of `kampala` — where 3 purchase rows live. The previous
 * screen dodged that by hard-coding `LOC_JUBA`; my first rewrite used `home()` and read the wrong
 * warehouse, which is how a foil business came to show a MikroTik router as its stock. Reading the
 * records directly removes the setting from the question entirely.
 *
 * ── the two sources ─────────────────────────────────────────────────────────────────────────────
 *
 * PURCHASED  `order_costs`, the line every Buy stock entry writes against a supplier, carrying
 *            `product_id`, `qty` and `unit_cost`. `stage = purchase`, not voided. **`pay_status` is
 *            not consulted** — cash, credit, partly paid and unpaid are all goods that arrived, and
 *            what is owed to the supplier is Finance's question, not this screen's.
 *
 * SOLD       `order_items` joined to `orders`, excluding cancelled / rejected / void — the same
 *            shape `DemandSuggestion::salesMap()` uses, with its sales window removed because this
 *            is a lifetime balance rather than a velocity. Covers POS, web and WhatsApp alike:
 *            `PosController` writes an `Order` with `OrderItem`s like every other channel. Whether
 *            the customer has paid does not matter either — the goods left.
 *
 * Neither source needs the ledger to be switched on, neither depends on a location, and neither
 * counts a figure somebody typed into a stock box.
 *
 * ── what appears ────────────────────────────────────────────────────────────────────────────────
 *
 * Only products with at least one supplier purchase line — "track that item only", as instructed. A
 * product sold with no purchase record behind it therefore has no row; those are COUNTED on a tile
 * rather than dropped in silence, because a sale the buying records cannot account for is worth
 * knowing about even when it is not what you asked to see.
 *
 * Read-only projection. It derives, never writes.
 */
final class JubaStockCapability implements ProcessCapability
{
    public function key(): string { return 'juba.stock'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        $bought = $this->purchasedMap($t);      // pid => ['qty','lines','supplier']
        $sold   = $this->soldMap($t);           // pid => qty

        $names = [];
        if ($bought) {
            $names = Product::where('tenant_id', $t)->whereIn('id', array_keys($bought))->pluck('name', 'id')->all();
        }

        $inStock = 0; $soldOut = 0; $over = 0; $units = 0.0;
        $totalBought = 0.0; $totalSold = 0.0;
        $rows = [];
        foreach ($bought as $pid => $b) {
            $bq = round((float) $b['qty'], 3);
            $sq = round((float) ($sold[$pid] ?? 0), 3);
            $cur = round($bq - $sq, 3);

            $totalBought += $bq; $totalSold += $sq;
            if ($cur > 0)      { $inStock++; $units += $cur; }
            elseif ($cur < 0)  { $over++; }
            else               { $soldOut++; }

            $sub = $b['lines'] . ' purchase' . ($b['lines'] === 1 ? '' : 's');
            if ($b['supplier'] !== '') { $sub .= ' · last from ' . $b['supplier']; }

            $rows[] = [
                'product' => ['value' => (string) ($names[$pid] ?? ('#' . $pid)), 'sub' => $sub, 'avatar' => false],
                'bought'  => ['value' => $this->qty($bq), 'sort' => $bq],
                'sold'    => ['value' => $this->qty($sq), 'sort' => $sq],
                'current' => [
                    'value' => $this->qty($cur), 'sort' => $cur,
                    'tone'  => $cur > 0 ? 'ok' : ($cur < 0 ? 'danger' : 'default'),
                ],
            ];
        }

        usort($rows, fn ($a, $b) => $b['current']['sort'] <=> $a['current']['sort']);

        // Sold with nothing bought behind it — not listed, because the instruction was to track the
        // purchased items only. Counted, because silence about it would be a different kind of wrong.
        $ghosts = 0;
        foreach ($sold as $pid => $q) { if ($q > 0 && ! isset($bought[$pid])) { $ghosts++; } }

        $columns = [
            ['key' => 'product', 'label' => 'Product', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'bought',  'label' => 'Purchased', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'sold',    'label' => 'Sold', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'current', 'label' => 'Current stock', 'align' => 'right', 'type' => 'num', 'sortable' => true],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:juba.stock', 'title' => 'Juba Stock',
                'subtitle' => 'Current stock = Purchased − Sold. Purchased is every supplier purchase line you have '
                            . 'entered against a product; Sold is every order that was not cancelled. Cash, credit and '
                            . 'unpaid all count — payment status never changes stock.',
            ])
            ->financials([
                ['key' => 'instock', 'label' => 'In stock', 'value' => $inStock, 'tone' => 'ok', 'foot' => 'purchased items still on hand'],
                ['key' => 'units', 'label' => 'Units on hand', 'value' => $this->qty(round($units, 3)), 'tone' => 'ok', 'foot' => 'total across those items'],
                ['key' => 'soldout', 'label' => 'Sold out', 'value' => $soldOut, 'tone' => $soldOut > 0 ? 'warn' : 'ok', 'foot' => 'purchased, all sold'],
                ['key' => 'over', 'label' => 'Sold more than bought', 'value' => $over + $ghosts,
                 'tone' => ($over + $ghosts) > 0 ? 'danger' : 'ok',
                 'foot' => $ghosts > 0 ? ($over . ' listed · ' . $ghosts . ' with no purchase line at all') : 'no purchase record for the difference'],
            ])
            ->table($columns, $rows)
            ->meta(['capability' => 'juba.stock', 'ttl' => 30, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $bought = $this->purchasedMap($ctx->tenantId());
        $sold   = $this->soldMap($ctx->tenantId());
        $units  = 0.0;
        foreach ($bought as $pid => $b) {
            $c = (float) $b['qty'] - (float) ($sold[$pid] ?? 0);
            if ($c > 0) { $units += $c; }
        }

        return ['unitsOnHand' => round($units, 3)];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    /**
     * Everything bought against a product, from the supplier purchase lines.
     *
     * One grouped query for the totals, one small query for the most recent supplier per product —
     * not one per product, because 400+ products would turn a glance into a page load.
     *
     * @return array<int,array{qty:float,lines:int,supplier:string}>
     */
    private function purchasedMap(int $tid): array
    {
        try {
            $rows = DB::table('order_costs')
                ->where('tenant_id', $tid)
                ->where('stage', 'purchase')
                ->whereNull('voided_at')
                ->whereNotNull('product_id')
                ->where('qty', '>', 0)
                ->select('product_id', DB::raw('SUM(qty) as qty'), DB::raw('COUNT(*) as lines'))
                ->groupBy('product_id')
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = [
                'qty' => (float) $r->qty, 'lines' => (int) $r->lines, 'supplier' => '',
            ];
        }
        if ($map === []) { return $map; }

        try {
            $latest = DB::table('order_costs')
                ->where('tenant_id', $tid)->where('stage', 'purchase')->whereNull('voided_at')
                ->whereIn('product_id', array_keys($map))
                ->orderBy('product_id')->orderByDesc('created_at')->orderByDesc('id')
                ->get(['product_id', 'supplier_name']);
            foreach ($latest as $r) {
                $pid = (int) $r->product_id;
                if (isset($map[$pid]) && $map[$pid]['supplier'] === '') {
                    $map[$pid]['supplier'] = trim((string) ($r->supplier_name ?? ''));
                }
            }
        } catch (\Throwable $e) {
            // the supplier name is a courtesy on the row; the quantities stand without it
        }

        return $map;
    }

    /**
     * Everything sold, all time, per product.
     *
     * The shape DemandSuggestion::salesMap() already uses — minus its sales window, because that one
     * measures velocity and this one is a lifetime balance. POS, web and WhatsApp all write an
     * `Order` with `OrderItem`s, so all three are counted here without naming any of them.
     *
     * @return array<int,float>
     */
    private function soldMap(int $tid): array
    {
        try {
            $rows = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.tenant_id', $tid)
                ->whereRaw("lower(coalesce(orders.status,'')) not in ('cancelled','canceled','rejected','void')")
                ->whereNotNull('order_items.product_id')
                ->select('order_items.product_id', DB::raw('SUM(order_items.qty) as qty'))
                ->groupBy('order_items.product_id')
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($rows as $r) { $map[(int) $r->product_id] = (float) $r->qty; }

        return $map;
    }

    private function qty($v): string
    {
        $f = (float) $v;

        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.') ?: '0';
    }
}
