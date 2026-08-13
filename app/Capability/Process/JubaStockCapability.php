<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Juba Stock — Current = Purchased − Sold, from the purchase and sales DOCUMENTS.
 *
 * ── the popcorn, which decided the design ───────────────────────────────────────────────────────
 *
 *     product #66008  Popcorn Seeds 1kg
 *       products.stock column   150      ← typed, and wrong
 *       purchased (order_costs)  50
 *       sold (order_items)        0      ← my previous version, reporting 50 still on hand
 *       INV-2026-0018  Ciano  order_id=171  qty 50  "Popcorn Seeds 1kg"
 *       order lines mentioning popcorn:  NONE
 *
 * The invoice carries a line the order does not. Across this shop: `order_items` holds 599 units,
 * `invoice_items` holds 1,082. Orders are frequently header-only — 132 orders against 138 item rows
 * — while the invoice is where the goods are actually listed. So the sales record is the INVOICE.
 *
 * ── how an invoice line finds its product ───────────────────────────────────────────────────────
 *
 * `invoice_items` has **no `product_id`** — the column does not exist, and `InvoiceService` writes
 * each line from `name` and `sku` alone, so the identity is dropped at creation. Of 183 lines, `sku`
 * is populated on **0**, and the NAME matches a product exactly on **172**.
 *
 * So the join is on the exact, case-folded, trimmed name and nothing else. **No similarity scoring,
 * ever** — the rule the purchase-linking work settled on in August, for the reason it settled on it:
 * fifty cartons on the wrong product is worse than fifty on nothing, because the second is visible
 * on a report. The lines that do not match are COUNTED on screen, so the shortfall is never silent.
 *
 * ── no double counting, and nothing missed ──────────────────────────────────────────────────────
 *
 * 88 of 89 invoices carry an `order_id`, so counting both tables blindly would count those sales
 * twice. Each order is therefore counted from exactly one document:
 *
 *   order HAS a live invoice   → the invoice's lines (the fuller record)
 *   order has none             → its `items_json` lines, which carry product_id
 *   …and no items_json either  → the `order_items` table
 *   invoice with no order      → its lines
 *
 * The `items_json` step is not a nicety. InvoiceCapability states it plainly: "Orders keep their line
 * detail in items_json … the relational order_items table is often empty." A version of this screen
 * that read only the table silently dropped the sales of every order whose lines were never mirrored
 * into it.
 *
 * "Live" is `status != 'void'`, which is `InvoiceService`'s own test for the same question, used in
 * three places there. It matters: Ciano's order 237 carries INV-0069 (void) and INV-0070 (paid) for
 * the same 33.71 — a re-issue. Counting voids would bill the stock twice.
 *
 * ── what payment status does ────────────────────────────────────────────────────────────────────
 *
 * Nothing, on either side. A credit purchase is goods that arrived; an unpaid invoice is goods that
 * left. `pay_status` and `amount_paid` are never read here. What is owed either way is Finance's
 * question — an unpaid invoice is a receivable, not stock sitting on a shelf.
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

        $bought = $this->purchasedMap($t);
        [$sold, $unmatched, $unmatchedQty] = $this->soldMap($t);

        $names = [];
        if ($bought) {
            $names = Product::where('tenant_id', $t)->whereIn('id', array_keys($bought))->pluck('name', 'id')->all();
        }

        $inStock = 0; $soldOut = 0; $over = 0; $units = 0.0;
        $rows = [];
        foreach ($bought as $pid => $b) {
            $bq  = round((float) $b['qty'], 3);
            $sq  = round((float) ($sold[$pid] ?? 0), 3);
            $cur = round($bq - $sq, 3);

            if ($cur > 0)     { $inStock++; $units += $cur; }
            elseif ($cur < 0) { $over++; }
            else              { $soldOut++; }

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

        $ghosts = 0;
        foreach ($sold as $pid => $q) { if ($q > 0 && ! isset($bought[$pid])) { $ghosts++; } }

        $columns = [
            ['key' => 'product', 'label' => 'Product', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'bought',  'label' => 'Purchased', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'sold',    'label' => 'Sold', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'current', 'label' => 'Current stock', 'align' => 'right', 'type' => 'num', 'sortable' => true],
        ];

        $sub = 'Current stock = Purchased − Sold. Purchased is every supplier purchase line; Sold is every '
             . 'invoice that is not void, plus orders that were never invoiced. Cash, credit and unpaid all '
             . 'count — payment status never changes stock.';
        if ($unmatched > 0) {
            $sub .= ' ' . $unmatched . ' invoice line' . ($unmatched === 1 ? '' : 's')
                 . ' (' . $this->qty($unmatchedQty) . ' units) could not be matched to a product by name and '
                 . 'are NOT deducted — nothing is matched on a guess.';
        }

        return (new WorkspacePayload)
            ->object(['type' => 'process:juba.stock', 'title' => 'Juba Stock', 'subtitle' => $sub])
            ->financials([
                ['key' => 'instock', 'label' => 'In stock', 'value' => $inStock, 'tone' => 'ok', 'foot' => 'purchased items still on hand'],
                ['key' => 'units', 'label' => 'Units on hand', 'value' => $this->qty(round($units, 3)), 'tone' => 'ok', 'foot' => 'total across those items'],
                ['key' => 'soldout', 'label' => 'Sold out', 'value' => $soldOut, 'tone' => $soldOut > 0 ? 'warn' : 'ok', 'foot' => 'purchased, all sold'],
                ['key' => 'over', 'label' => 'Sold more than bought', 'value' => $over + $ghosts,
                 'tone' => ($over + $ghosts) > 0 ? 'danger' : 'ok',
                 'foot' => $ghosts > 0 ? ($over . ' listed · ' . $ghosts . ' with no purchase line') : 'no purchase record for the difference'],
            ])
            ->table($columns, $rows)
            ->meta(['capability' => 'juba.stock', 'ttl' => 30, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $bought = $this->purchasedMap($ctx->tenantId());
        [$sold] = $this->soldMap($ctx->tenantId());
        $units = 0.0;
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

    /** @return array<int,array{qty:float,lines:int,supplier:string}> */
    private function purchasedMap(int $tid): array
    {
        try {
            $rows = DB::table('order_costs')
                ->where('tenant_id', $tid)->where('stage', 'purchase')->whereNull('voided_at')
                ->whereNotNull('product_id')->where('qty', '>', 0)
                ->select('product_id', DB::raw('SUM(qty) as qty'), DB::raw('COUNT(*) as lines'))
                ->groupBy('product_id')->get();
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = ['qty' => (float) $r->qty, 'lines' => (int) $r->lines, 'supplier' => ''];
        }
        if ($map === []) { return $map; }

        try {
            foreach (DB::table('order_costs')
                ->where('tenant_id', $tid)->where('stage', 'purchase')->whereNull('voided_at')
                ->whereIn('product_id', array_keys($map))
                ->orderBy('product_id')->orderByDesc('created_at')->orderByDesc('id')
                ->get(['product_id', 'supplier_name']) as $r) {
                $pid = (int) $r->product_id;
                if (isset($map[$pid]) && $map[$pid]['supplier'] === '') {
                    $map[$pid]['supplier'] = trim((string) ($r->supplier_name ?? ''));
                }
            }
        } catch (\Throwable $e) {
            // a courtesy on the row; the quantities stand without it
        }

        return $map;
    }

    /**
     * Units sold per product, from invoices first and orders only where no invoice exists.
     *
     * @return array{0:array<int,float>,1:int,2:float}  [ pid => qty, unmatched lines, unmatched units ]
     */
    private function soldMap(int $tid): array
    {
        $sold = []; $unmatched = 0; $unmatchedQty = 0.0; $fromJson = [];

        // Exact name -> product id, PLUS the aliases in `products.keywords`.
        //
        // The verification run showed why the aliases are needed. The catalogue says
        // "ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON)"; invoices bill it as
        // "aluminium foil 45cm (carton of 6pc, min 10)" and "…(min 5 carton)" — 65 cartons of foil
        // and cling film under wordings no exact name match can reach. That is the same wall the
        // purchase-linking work hit in August, and it built the way through: a product answers to
        // other names held comma-separated in `keywords`, the field the bot already searches, so an
        // alias improves both. Adding one is a data fix, not a deploy.
        //
        // Still EXACT, never a similarity score — an alias is a decision somebody made and typed,
        // which is exactly what a score is not. A string claimed by two products is AMBIGUOUS and
        // matched to neither: an invoice line landing on the wrong one of a pair is invisible.
        // ONE ALIAS PER LINE when the field has line breaks, otherwise the shared comma parser.
        //
        // My first attempt split on commas alone and a test killed it: the wordings that need an
        // alias here are exactly the ones containing commas —
        // "aluminium foil 45cm (carton of 6pc, min 10)" — and comma-splitting shreds them into
        // "aluminium foil 45cm (carton of 6pc" and "min 10)", neither of which matches anything.
        // A comma-separated list cannot express a value containing a comma; that is not a bug in
        // PurchaseNameMatch::aliases(), it is the format. So a keywords field with NEWLINES is read
        // as one alias per line, verbatim, and a field without them goes to the shared parser
        // unchanged. Nothing about the existing comma behaviour moves, and the bot's search is
        // untouched either way.
        $byName = [];
        $claim = function (string $raw, int $pid) use (&$byName) {
            $k = \App\Services\Inventory\PurchaseNameMatch::key($raw);   // the shared normaliser, not a second one
            if ($k === '') { return; }
            $byName[$k] = (array_key_exists($k, $byName) && $byName[$k] !== $pid) ? null : $pid;
        };
        try {
            foreach (Product::where('tenant_id', $tid)->get(['id', 'name', 'keywords']) as $p) {
                $pid = (int) $p->id;
                $claim((string) $p->name, $pid);

                $kw = (string) ($p->keywords ?? '');
                if (preg_match('/\R/u', $kw)) {
                    foreach (preg_split('/\R/u', $kw) ?: [] as $lineAlias) { $claim($lineAlias, $pid); }
                } else {
                    foreach (\App\Services\Inventory\PurchaseNameMatch::aliases($kw) as $alias) { $claim($alias, $pid); }
                }
            }
        } catch (\Throwable $e) {
            return [[], 0, 0.0];
        }

        // 1. every live invoice line
        $invoiced = [];      // order_id => true, so those orders are not counted a second time
        try {
            foreach (DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoice_items.tenant_id', $tid)
                ->where('invoices.status', '!=', 'void')
                ->get(['invoice_items.name', 'invoice_items.qty', 'invoices.order_id']) as $r) {
                if ($r->order_id) { $invoiced[(int) $r->order_id] = true; }
                $q = (float) $r->qty;
                if ($q <= 0) { continue; }
                $pid = $byName[$this->normName((string) $r->name)] ?? null;
                if ($pid === null) { $unmatched++; $unmatchedQty += $q; continue; }
                $sold[$pid] = ($sold[$pid] ?? 0) + $q;
            }
        } catch (\Throwable $e) {
            return [[], 0, 0.0];
        }

        // 2. orders that were never invoiced — their own lines.
        //
        // `items_json` FIRST, and this is not a preference. InvoiceCapability says it outright:
        // "Orders keep their line detail in items_json … the relational order_items table is often
        // empty." Reading only the table, as the previous version did, silently drops the sales of
        // every order whose lines were never mirrored into it. `items_json` also carries the
        // product_id, so no name matching is needed on this path at all.
        try {
            foreach (DB::table('orders')
                ->where('tenant_id', $tid)
                ->whereRaw("lower(coalesce(status,'')) not in ('cancelled','canceled','rejected','void')")
                ->get(['id', 'items_json']) as $o) {
                $oid = (int) $o->id;
                if (isset($invoiced[$oid])) { continue; }        // already counted on its invoice

                $ij = $o->items_json;
                if (is_string($ij)) { $ij = json_decode($ij, true); }
                if (! is_array($ij) || $ij === []) { continue; }

                foreach ($ij as $it) {
                    if (! is_array($it)) { continue; }
                    $q = (float) ($it['qty'] ?? 0);
                    if ($q <= 0) { continue; }
                    $pid = (int) ($it['product_id'] ?? ($it['id'] ?? 0));
                    if ($pid <= 0) {
                        // a lump-sum or free-text line: match it by name like an invoice line, or
                        // report it. Never guessed.
                        $pid = $byName[$this->normName((string) ($it['name'] ?? ''))] ?? null;
                        if ($pid === null) { $unmatched++; $unmatchedQty += $q; continue; }
                    }
                    $sold[$pid] = ($sold[$pid] ?? 0) + $q;
                    $fromJson[$oid] = true;
                }
            }
        } catch (\Throwable $e) {
            // the invoiced half stands
        }

        // 3. and the relational table, only for orders that had neither an invoice nor items_json
        try {
            foreach (DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.tenant_id', $tid)
                ->whereRaw("lower(coalesce(orders.status,'')) not in ('cancelled','canceled','rejected','void')")
                ->whereNotNull('order_items.product_id')
                ->get(['order_items.product_id', 'order_items.qty', 'order_items.order_id']) as $r) {
                $oid = (int) $r->order_id;
                if (isset($invoiced[$oid]) || isset($fromJson[$oid])) { continue; }
                $q = (float) $r->qty;
                if ($q <= 0) { continue; }
                $sold[(int) $r->product_id] = ($sold[(int) $r->product_id] ?? 0) + $q;
            }
        } catch (\Throwable $e) {
            // the invoiced half stands
        }

        return [$sold, $unmatched, round($unmatchedQty, 3)];
    }

    /** Case-folded, whitespace-collapsed. Nothing else — no stemming, no fuzz. */
    private function normName(string $n): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($n))));
    }

    private function qty($v): string
    {
        $f = (float) $v;

        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.') ?: '0';
    }
}
