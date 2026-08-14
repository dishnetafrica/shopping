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
    /** Purchase lines since the count that carry no product_id, so they moved no stock. */
    private array $unlinkedBuys = ['lines' => 0, 'units' => 0.0, 'names' => []];

    /** Units purchased BEFORE the cutoff, per product — history that makes a count necessary. */
    private array $preBuys = [];

    /** Units sold BEFORE the cutoff for products with no count of their own. */
    private array $preSold = [];

    public function key(): string { return 'juba.stock'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        // v5.08 — THE OPENING IS THE ANCHOR.
        //
        //     Current = counted on date D  +  purchases after D  -  sales after D
        //
        // The old rule was Purchased - Sold across all time, and it demanded that every document
        // since the shop opened be present and correctly spelled. It is not: 303 units of sales
        // carry invoice wordings matching no product, and 60 products read as sold more than
        // bought. Anchoring on a physical count closes all of that off — the history is inside the
        // count — and leaves a short, checkable tail.
        $open = $this->openingsMap($t);
        // The earliest count on the shop. Everything before it — unmatched wordings, unlinked
        // purchases, negative history — belongs to the era the counts closed off.
        $firstOpen = null;
        foreach ($open as $o) { if ($firstOpen === null || $o['at'] < $firstOpen) { $firstOpen = $o['at']; } }
        $bought = $this->purchasedMap($t, $open, $firstOpen);
        [$sold, $unmatched, $unmatchedQty, $detail] = $this->soldMap($t, $open, $firstOpen);

        // v5.11 — BOUGHT SINCE THE COUNT, NEVER TRADED BEFORE IT.
        //
        // "If I buy something new today does it appear?" It should. A product with no purchase and
        // no sale before the cutoff had nothing on the shelf to count, so its opening is zero and
        // its first delivery is the whole of its stock. Nothing is being assumed about a shelf
        // nobody looked at — there was no shelf.
        //
        // A product that WAS trading before the cutoff is left out, because that is precisely the
        // history the counts exist to close off.
        if ($firstOpen !== null) {
            foreach ($bought as $pid => $b) {
                if (isset($open[$pid])) { continue; }
                if (($this->preBuys[$pid] ?? 0) > 0) { continue; }   // bought before: needs counting
                if (($this->preSold[$pid] ?? 0) > 0) { continue; }   // sold before: needs counting
                $open[$pid] = ['qty' => 0.0, 'at' => $firstOpen, 'by' => '', 'new' => true];
            }
        }

        $names = [];
        if ($open) {
            $names = Product::where('tenant_id', $t)->whereIn('id', array_keys($open))->pluck('name', 'id')->all();
        }
        $counted = 0;
        try { $counted = (int) Product::where('tenant_id', $t)->where('active', true)->count(); }
        catch (\Throwable $e) { $counted = 0; }

        $inStock = 0; $soldOut = 0; $over = 0; $units = 0.0;
        $rows = [];
        foreach ($open as $pid => $o) {
            $oq  = round((float) $o['qty'], 3);
            $bq  = round((float) ($bought[$pid]['qty'] ?? 0), 3);
            $sq  = round((float) ($sold[$pid] ?? 0), 3);
            $cur = round($oq + $bq - $sq, 3);

            if ($cur > 0)     { $inStock++; $units += $cur; }
            elseif ($cur < 0) { $over++; }
            else              { $soldOut++; }

            $when = substr((string) $o['at'], 0, 10);
            $sub  = ! empty($o['new'])
                ? 'new line — nothing before the count, so every carton bought is stock'
                : ('counted ' . $when . ($o['by'] !== '' ? (' by ' . $o['by']) : ''));
            $lines = (int) ($bought[$pid]['lines'] ?? 0);
            if ($lines > 0) {
                $sub .= ' · ' . $lines . ' purchase' . ($lines === 1 ? '' : 's') . ' since';
                if (($bought[$pid]['supplier'] ?? '') !== '') { $sub .= ' · last from ' . $bought[$pid]['supplier']; }
            }

            $rows[] = [
                'product' => ['value' => (string) ($names[$pid] ?? ('#' . $pid)), 'sub' => $sub, 'avatar' => false],
                'opening' => ['value' => $this->qty($oq), 'sort' => $oq],
                'bought'  => ['value' => $this->qty($bq), 'sort' => $bq],
                'sold'    => ['value' => $this->qty($sq), 'sort' => $sq],
                'current' => [
                    'value' => $this->qty($cur), 'sort' => $cur,
                    'tone'  => $cur > 0 ? 'ok' : ($cur < 0 ? 'danger' : 'default'),
                ],
            ];
        }

        usort($rows, fn ($a, $b) => $b['current']['sort'] <=> $a['current']['sort']);

        // Sales since the count against a product nobody has counted. Not shown as a row — there is
        // no shelf figure to show — but counted, because it is the size of the job still to do.
        $ghosts = 0;
        foreach ($sold as $pid => $q) { if ($q > 0 && ! isset($open[$pid])) { $ghosts++; } }

        $columns = [
            ['key' => 'product', 'label' => 'Product', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'opening', 'label' => 'Counted', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'bought',  'label' => 'Bought since', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'sold',    'label' => 'Sold since', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'current', 'label' => 'Current stock', 'align' => 'right', 'type' => 'num', 'sortable' => true],
        ];

        $notCounted = max(0, $counted - count($open));

        $sub = 'Current stock = what Juba counted, plus everything bought since, less everything sold since. '
             . 'A purchase adds the moment it is saved against a product; an invoice deducts the moment it '
             . 'is raised. Cash, credit and unpaid all count — payment status never changes stock.';

        // v5.10 — the two ways goods move without the screen noticing, stated where the number is.
        // Both are silent by nature: a purchase typed as free text and an invoice worded differently
        // than the catalogue both look completely normal on their own screens.
        $ub = $this->unlinkedBuys;
        if (! empty($ub['error'])) {
            $sub .= ' ⚠ The check for unlinked purchases could not run, so purchases may be missing from this screen.';
        } elseif (($ub['lines'] ?? 0) > 0) {
            $sub .= ' ⚠ ' . $ub['lines'] . ' purchase line' . ($ub['lines'] === 1 ? '' : 's')
                 . ' since the count (' . $this->qty($ub['units']) . ' units) name no product, so they added '
                 . 'NOTHING here: ' . implode('; ', array_slice($ub['names'], 0, 4))
                 . (count($ub['names']) > 4 ? ' …' : '')
                 . '. Open the purchase and pick the product to fix it.';
        }
        if ($notCounted > 0) {
            $sub .= ' ' . number_format($notCounted) . ' active product'
                 . ($notCounted === 1 ? ' has' : 's have') . ' never been counted and '
                 . ($notCounted === 1 ? 'is' : 'are') . ' NOT listed — a number nobody has stood in '
                 . 'front of is not stock. Count them with: php artisan stock:count';
        }
        if (($detail['ordUnits'] ?? 0) > 0) {
            $sub .= ' Of what has sold since, ' . $this->qty($detail['invUnits']) . ' units came off invoices and '
                 . $this->qty($detail['ordUnits']) . ' off orders that were never invoiced.';
        }
        if ($unmatched > 0) {
            $mi = $this->missTotals($detail['miss'], 'invoice');
            $mo = $this->missTotals($detail['miss'], 'order');
            $where = [];
            if ($mi['lines'] > 0) { $where[] = $mi['lines'] . ' on invoices (' . $this->qty($mi['units']) . ' units)'; }
            if ($mo['lines'] > 0) { $where[] = $mo['lines'] . ' on orders (' . $this->qty($mo['units']) . ' units)'; }
            $sub .= ' ⚠ Since the count, ' . $unmatched . ' sales line' . ($unmatched === 1 ? '' : 's')
                 . ' could not be matched to a product by name and ' . ($unmatched === 1 ? 'is' : 'are')
                 . ' NOT deducted — ' . implode(', ', $where) . '. Add the wording to that product\'s '
                 . 'Keywords, one per line. Nothing is matched on a guess.';
        }

        return (new WorkspacePayload)
            ->object(['type' => 'process:juba.stock', 'title' => 'Juba Stock', 'subtitle' => $sub])
            ->financials([
                ['key' => 'instock', 'label' => 'In stock', 'value' => $inStock, 'tone' => 'ok', 'foot' => 'counted items still on hand'],
                ['key' => 'units', 'label' => 'Units on hand', 'value' => $this->qty(round($units, 3)), 'tone' => 'ok', 'foot' => 'total across those items'],
                ['key' => 'soldout', 'label' => 'Sold out', 'value' => $soldOut, 'tone' => $soldOut > 0 ? 'warn' : 'ok', 'foot' => 'counted, all sold since'],
                ['key' => 'over', 'label' => 'Sold more than we had', 'value' => $over,
                 'tone' => $over > 0 ? 'danger' : 'ok',
                 'foot' => 'sold more since the count than the count plus purchases allows'],
                ['key' => 'uncounted', 'label' => 'Never counted', 'value' => number_format($notCounted),
                 'tone' => $notCounted > 0 ? 'warn' : 'ok',
                 'foot' => $ghosts > 0 ? ($ghosts . ' of them have sold since') : 'not listed until somebody counts them'],
            ])
            ->table($columns, $rows)
            ->meta([
                'capability' => 'juba.stock', 'ttl' => 30, 'permissions' => ['menu:products'],
                // Not rendered anywhere. It exists so the screen can be CHECKED from outside itself.
                // A browser can walk every invoice document and add the quantities up independently —
                // it cannot see an order's items_json, which no read endpoint exposes. So the screen
                // states which half of every Sold figure came from which document, and the checker
                // verifies the half it can reach instead of guessing at the whole and crying wolf.
                'reconcile' => $this->reconcile($detail, $names, count($rows)),
            ]);
    }

    /**
     * The provenance block: how Sold was arrived at, per product, in a form something outside this
     * class can test. Names, not ids, because that is what the table shows.
     *
     * @param array<int,mixed>   $detail  the fourth return of soldMap()
     * @param array<int,string>  $names   product id => name
     */
    private function reconcile(array $detail, array $names, int $onScreen): array
    {
        $byName = []; $invTot = 0.0; $ordTot = 0.0;
        $pids = array_values(array_unique(array_merge(array_keys($detail['inv']), array_keys($detail['ord']))));
        foreach ($pids as $pid) {
            $iv = round((float) ($detail['inv'][$pid] ?? 0), 3);
            $ov = round((float) ($detail['ord'][$pid] ?? 0), 3);
            if ($iv === 0.0 && $ov === 0.0) { continue; }
            $invTot += $iv; $ordTot += $ov;

            $name = (string) ($names[$pid] ?? ('#' . $pid));
            $k = $this->normName($name);
            if ($k === '') { continue; }
            if (isset($byName[$k])) {
                // Two products with the same name. Adding them is the only honest thing to do here —
                // the table shows one line per id and the checker matches on text, so it would see
                // the sum. Flagged, because it also means one of them is unreachable by name.
                $byName[$k]['inv'] = round($byName[$k]['inv'] + $iv, 3);
                $byName[$k]['ord'] = round($byName[$k]['ord'] + $ov, 3);
                $byName[$k]['dup'] = true;
                continue;
            }
            $byName[$k] = ['pid' => $pid, 'name' => $name, 'inv' => $iv, 'ord' => $ov];
        }

        return [
            'rowsOnScreen'   => $onScreen,
            'invUnits'       => round($invTot, 3),
            'ordUnits'       => round($ordTot, 3),
            'invLines'       => (int) $detail['invLines'],
            'ordLines'       => (int) $detail['ordLines'],
            'ordersCounted'  => array_values($detail['ordNos']),
            'unmatchedLines' => count($detail['miss']),
            'unmatchedUnits' => round(array_sum(array_column($detail['miss'], 'qty')), 3),
            'unmatched'      => array_slice($detail['miss'], 0, 120),
            // …and split by which kind of document, because a checker walking invoices can only ever
            // find the invoice ones. Comparing its total against the combined figure reports a fault
            // that is not there — which is exactly what happened, twice, before this line existed.
            'unmatchedInv'   => $this->missTotals($detail['miss'], 'invoice'),
            'unmatchedOrd'   => $this->missTotals($detail['miss'], 'order'),
            // every invoice wording seen, and the product it resolved to (null = matched nothing).
            // The checker reads a line off an invoice document and needs the same answer this class
            // reached; handing it the map is the difference between checking the arithmetic and
            // re-implementing the decision, and only the arithmetic is worth checking.
            'lineMap'        => $detail['lineMap'],
            'byName'         => $byName,
        ];
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $open = $this->openingsMap($ctx->tenantId());
        $first = null;
        foreach ($open as $o) { if ($first === null || $o['at'] < $first) { $first = $o['at']; } }
        $bought = $this->purchasedMap($ctx->tenantId(), $open, $first);
        [$sold] = $this->soldMap($ctx->tenantId(), $open, $first);   // list() takes the first
        $units = 0.0;
        foreach ($open as $pid => $o) {
            $c = (float) $o['qty'] + (float) ($bought[$pid]['qty'] ?? 0) - (float) ($sold[$pid] ?? 0);
            if ($c > 0) { $units += $c; }
        }

        return ['unitsOnHand' => round($units, 3)];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    /**
     * Units PURCHASED SINCE each product's opening count.
     *
     * v5.08 — this used to sum every purchase line ever recorded. It no longer does, and that is
     * the whole point of the opening: goods bought before the count are already standing on the
     * shelf that was counted, so adding them again would double them.
     *
     * @param  array<int,array{qty:float,at:string}> $since  opening per product id
     * @return array<int,array{qty:float,lines:int,supplier:string}>
     */
    private function purchasedMap(int $tid, array $since, ?string $firstOpen = null): array
    {
        try {
            $rows = DB::table('order_costs')
                ->where('tenant_id', $tid)->where('stage', 'purchase')->whereNull('voided_at')
                ->whereNotNull('product_id')->where('qty', '>', 0)
                ->orderBy('product_id')->orderByDesc('created_at')->orderByDesc('id')
                ->get(['product_id', 'qty', 'supplier_name', 'created_at']);
        } catch (\Throwable $e) {
            return [];
        }

        // v5.10 — purchase lines since the count that carry NO product_id. A purchase is only
        // stock if it says WHICH product arrived; typed as free text it moves nothing, silently.
        // That silence is the problem: goods arrive, the shelf fills, and the screen does not move.
        $this->unlinkedBuys = ['lines' => 0, 'units' => 0.0, 'names' => []];
        if ($firstOpen !== null) {
            try {
                foreach (DB::table('order_costs')
                    ->where('tenant_id', $tid)->where('stage', 'purchase')->whereNull('voided_at')
                    ->whereNull('product_id')->where('qty', '>', 0)
                    ->where('created_at', '>', $firstOpen)
                    ->get(['note', 'qty']) as $u) {
                    $this->unlinkedBuys['lines']++;
                    $this->unlinkedBuys['units'] += (float) $u->qty;
                    $nm = trim((string) ($u->note ?? ''));
                    if ($nm !== '' && count($this->unlinkedBuys['names']) < 20) { $this->unlinkedBuys['names'][] = $nm; }
                }
            } catch (\Throwable $e) {
                // Say so rather than report a comforting zero. A silent catch here would repeat the
                // exact failure this screen exists to expose: a check that cannot run is not a
                // check that passed.
                $this->unlinkedBuys['error'] = true;
            }
        }

        // v5.11 — a product with NO count is not automatically unknowable. If it has no history at
        // all before the cutoff, it had nothing, and its first purchase IS its opening. That is the
        // difference between "we cannot trust the paperwork" (a product trading since before the
        // system) and "there is no paperwork to distrust" (something bought for the first time last
        // Tuesday). preBuys records which products fall on the wrong side of that line.
        $this->preBuys = [];
        $map = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            $cut = $since[$pid]['at'] ?? $firstOpen;
            if ($cut === null) { continue; }                 // no counts on this shop at all
            if ((string) $r->created_at <= $cut) {
                $this->preBuys[$pid] = ($this->preBuys[$pid] ?? 0) + (float) $r->qty;
                continue;
            }

            if (! isset($map[$pid])) { $map[$pid] = ['qty' => 0.0, 'lines' => 0, 'supplier' => '']; }
            $map[$pid]['qty'] += (float) $r->qty;
            $map[$pid]['lines']++;
            if ($map[$pid]['supplier'] === '') {
                $map[$pid]['supplier'] = trim((string) ($r->supplier_name ?? ''));
            }
        }

        return $map;
    }

    /**
     * The anchor: the most recent physical count per product, at the shop's stock home.
     *
     * Written by `php artisan stock:count --write`. A product with no row here is NOT GUESSED AT —
     * it does not appear on the screen at all, because nobody has established what is on its shelf
     * and a derived figure has already been shown, on this shop, to be wrong on 445 products out
     * of 449.
     *
     * @return array<int,array{qty:float,at:string,by:string}>
     */
    private function openingsMap(int $tid): array
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('stock_openings')) { return []; }
            $rows = DB::table('stock_openings')->where('tenant_id', $tid)
                ->orderBy('product_id')->orderBy('counted_at')->orderBy('id')
                ->get(['product_id', 'qty', 'counted_at', 'counted_by']);
        } catch (\Throwable $e) {
            return [];
        }

        // Ordered oldest-first, so the last write per product wins: a re-count REPLACES, it does
        // not adjust. Earlier rows stay in the table because how the number moved is worth keeping.
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->product_id] = [
                'qty' => (float) $r->qty,
                'at'  => (string) $r->counted_at,
                'by'  => (string) ($r->counted_by ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Units sold per product, from invoices first and orders only where no invoice exists.
     *
     * The fourth return is the audit trail — which document each unit came off. Nothing on screen
     * depends on it; it is what makes the screen checkable from outside. See reconcile().
     *
     * @return array{0:array<int,float>,1:int,2:float,3:array<string,mixed>}
     */
    private function soldMap(int $tid, array $since = [], ?string $firstOpen = null): array
    {
        $sold = []; $unmatched = 0; $unmatchedQty = 0.0; $fromJson = [];
        $d = ['inv' => [], 'ord' => [], 'miss' => [], 'lineMap' => [], 'ordNos' => [],
              'invLines' => 0, 'ordLines' => 0, 'invUnits' => 0.0, 'ordUnits' => 0.0];
        $empty = [[], 0, 0.0, $d];

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
                    // …and the WHOLE field as one alias, which is the fix for the obvious mistake:
                    // typing a single comma-bearing wording on one line. The comma parser shreds
                    // "aluminium foil 45cm (carton of 6pc, min 10)" into two fragments that match
                    // nothing, and the person who typed it gets no signal at all. Claiming the whole
                    // string as well costs one map entry and cannot mis-link anything: for a field
                    // with no comma it is the same string the parser already produced, and a field
                    // WITH commas can only collide with a product literally named that entire
                    // comma-bearing string — and a collision is refused, never guessed.
                    $claim($kw, $pid);
                }
            }
        } catch (\Throwable $e) {
            return $empty;
        }

        // 1. every live invoice line
        $invoiced = [];      // order_id => true, so those orders are not counted a second time
        try {
            foreach (DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoice_items.tenant_id', $tid)
                ->where('invoices.status', '!=', 'void')
                ->get(['invoice_items.name', 'invoice_items.qty', 'invoices.order_id', 'invoices.invoice_no',
                       'invoices.issued_at', 'invoices.created_at']) as $r) {
                if ($r->order_id) { $invoiced[(int) $r->order_id] = true; }
                $q = (float) $r->qty;
                if ($q <= 0) { continue; }
                // v5.08 — the document's own date, so a sale made before the shelf was counted is
                // not deducted from a count that already reflects it.
                $when = (string) ($r->issued_at ?: $r->created_at);
                $raw = (string) $r->name;
                $k   = $this->normName($raw);
                $pid = $byName[$k] ?? null;
                $d['lineMap'][$k] = $pid;                 // null is an answer too: "this matched nothing"
                if ($pid !== null && isset($since[$pid]) && $when !== '' && $when <= $since[$pid]['at']) {
                    continue;                             // already inside the opening count
                }
                if ($pid === null) {
                    // v5.10 — an unmatched wording on a document that PREDATES the count is already
                    // inside the count and is not a problem to solve. Only what has failed to match
                    // since the shelf was counted still costs you stock. This is what turned 303
                    // units of archaeology into a short, current list.
                    if ($firstOpen !== null && $when !== '' && $when <= $firstOpen) { continue; }
                    $unmatched++; $unmatchedQty += $q;
                    $d['miss'][] = ['doc' => (string) ($r->invoice_no ?: 'invoice'), 'src' => 'invoice',
                                    'name' => trim($raw), 'qty' => $q];
                    continue;
                }
                if (! isset($since[$pid]) && $firstOpen !== null && $when !== '' && $when <= $firstOpen) {
                    $this->preSold[$pid] = ($this->preSold[$pid] ?? 0) + $q;   // history, not current
                    continue;
                }
                $d['invLines']++; $d['invUnits'] += $q;
                $d['inv'][$pid] = ($d['inv'][$pid] ?? 0) + $q;
                $sold[$pid] = ($sold[$pid] ?? 0) + $q;
            }
        } catch (\Throwable $e) {
            return $empty;
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
                ->get(['id', 'order_no', 'items_json', 'created_at']) as $o) {
                $oid = (int) $o->id;
                if (isset($invoiced[$oid])) { continue; }        // already counted on its invoice
                $owhen = (string) $o->created_at;

                $ij = $o->items_json;
                if (is_string($ij)) { $ij = json_decode($ij, true); }
                if (! is_array($ij) || $ij === []) { continue; }

                $ono = (string) ($o->order_no ?: ('#' . $oid));
                foreach ($ij as $it) {
                    if (! is_array($it)) { continue; }
                    $q = (float) ($it['qty'] ?? 0);
                    if ($q <= 0) { continue; }
                    $pid = (int) ($it['product_id'] ?? ($it['id'] ?? 0));
                    if ($pid > 0 && isset($since[$pid]) && $owhen !== '' && $owhen <= $since[$pid]['at']) {
                        continue;                          // predates the count
                    }
                    if ($pid <= 0) {
                        // a lump-sum or free-text line: match it by name like an invoice line, or
                        // report it. Never guessed.
                        $pid = $byName[$this->normName((string) ($it['name'] ?? ''))] ?? null;
                        if ($pid === null) {
                            if ($firstOpen !== null && $owhen !== '' && $owhen <= $firstOpen) { continue; }
                            $unmatched++; $unmatchedQty += $q;
                            $d['miss'][] = ['doc' => $ono, 'src' => 'order',
                                            'name' => trim((string) ($it['name'] ?? '(no name)')), 'qty' => $q];
                            continue;
                        }
                    }
                    $sold[$pid] = ($sold[$pid] ?? 0) + $q;
                    $d['ord'][$pid] = ($d['ord'][$pid] ?? 0) + $q;
                    $d['ordLines']++; $d['ordUnits'] += $q;
                    $d['ordNos'][$oid] = $ono;
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
                ->get(['order_items.product_id', 'order_items.qty', 'order_items.order_id',
                       'orders.order_no', 'orders.created_at']) as $r) {
                $oid = (int) $r->order_id;
                if (isset($invoiced[$oid]) || isset($fromJson[$oid])) { continue; }
                $q = (float) $r->qty;
                if ($q <= 0) { continue; }
                $pid = (int) $r->product_id;
                if (isset($since[$pid]) && (string) $r->created_at <= $since[$pid]['at']) { continue; }
                $sold[$pid] = ($sold[$pid] ?? 0) + $q;
                $d['ord'][$pid] = ($d['ord'][$pid] ?? 0) + $q;
                $d['ordLines']++; $d['ordUnits'] += $q;
                $d['ordNos'][$oid] = (string) ($r->order_no ?: ('#' . $oid));
            }
        } catch (\Throwable $e) {
            // the invoiced half stands
        }

        $d['invUnits'] = round($d['invUnits'], 3);
        $d['ordUnits'] = round($d['ordUnits'], 3);

        return [$sold, $unmatched, round($unmatchedQty, 3), $d];
    }

    /** @param array<int,array{src:string,qty:float}> $miss */
    private function missTotals(array $miss, string $src): array
    {
        $lines = 0; $units = 0.0;
        foreach ($miss as $m) {
            if (($m['src'] ?? '') !== $src) { continue; }
            $lines++; $units += (float) $m['qty'];
        }

        return ['lines' => $lines, 'units' => round($units, 3)];
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
