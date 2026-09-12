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
    /**
     * The currency `products.cost_price` is held in, when there is no purchase document to ask.
     *
     * Not a guess and not the tenant base. OrderCostController::purchaseStore is the only thing that
     * writes that column automatically, and line ~400 writes `round($toUgx($unit), 2)` — the unit cost
     * converted INTO UGX from whatever currency the bill was in. Its own comment says so: "Rate to
     * convert a unit cost in the purchase currency to base UGX for the product's cost_price."
     *
     * This is deliberately NOT CapabilityContext::nativeCurrency(). That helper answers a different
     * question — what untagged SALES amounts are stored in — and it returns 'USD' whenever the tenant
     * base is USD. Tagging a 22,500 UGX carton as $22,500 and adding it to a total is precisely the
     * mislabelling this constant exists to prevent.
     */
    private const COST_CURRENCY = \App\Services\Sales\OrderCosting::COST_CURRENCY;

    /**
     * Order states where the goods are SOLD but have NOT left the building.
     *
     * This is the reason a physical count and this screen disagree and always will. Ashish walks the
     * Juba shelf and counts 53 cartons of foil. Twenty of them are packed against a confirmed order
     * that has not gone out yet. Both numbers are true; they answer different questions, and one
     * column cannot hold both. So the screen now shows what is ON THE SHELF, what is RESERVED, and
     * what is genuinely free to sell.
     *
     * The list is the app's own vocabulary, taken from SendOrderStatusNotification's status map:
     * New → Accepted → Confirmed → Preparing → Packed → Ready are all pre-dispatch; Dispatched,
     * In transit, Out for delivery and Delivered are gone. Cancelled and Rejected never counted.
     *
     * The pre-shipment set is written out and everything else counts as gone, not the other way
     * round: this vocabulary is closed and defined by the app, while terminal words multiply
     * (completed, closed, fulfilled, collected, paid…) and a terminal state nobody listed would
     * otherwise sit on the shelf for ever.
     *
     * Misfiling a status here CANNOT move Current stock — that still deducts every live order, so
     * the number the sales team already works from is untouched. It can only move the split between
     * On shelf and Reserved.
     */
    private const ON_SHELF_STATUSES = [
        'new', 'accepted', 'confirmed', 'preparing', 'packed', 'ready',
        'pending', 'processing', 'draft', 'quote', 'quotation', 'on hold', 'awaiting payment',
    ];

    /** Sold-but-still-here units per product id, filled by soldMap(). */
    private array $reserved = [];

    /** Purchase lines since the count that carry no product_id, so they moved no stock. */
    private array $unlinkedBuys = ['lines' => 0, 'units' => 0.0, 'names' => []];

    /** True when the cost lookup itself failed — reported, never silently valued at zero. */
    private bool $costError = false;

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

        $names = []; $costs = []; $reorder = [];
        // reorder_point ships in a later migration; guard the select so this screen still renders on a
        // database that has not run it yet (nothing is judged against a floor that does not exist).
        $hasReorder = \Illuminate\Support\Facades\Schema::hasColumn('products', 'reorder_point');
        if ($open) {
            $cols = ['id', 'name', 'cost_price'];
            if ($hasReorder) { $cols[] = 'reorder_point'; }
            $prods = Product::where('tenant_id', $t)->whereIn('id', array_keys($open))->get($cols);
            foreach ($prods as $pr) {
                $names[$pr->id] = (string) $pr->name;
                $costs[$pr->id] = $pr->cost_price === null ? null : (float) $pr->cost_price;
                $reorder[$pr->id] = ($hasReorder && $pr->reorder_point !== null) ? (int) $pr->reorder_point : null;
            }
        }

        // v5.13 — what the shelf is WORTH, at cost, IN THE CURRENCY IT WAS BOUGHT IN.
        //
        // The cost of a carton is asked of the purchase document first, not the catalogue. order_costs
        // carries `currency` and `rate_to_usd` on every line, so the price paid is currency-tagged
        // evidence and ReportingCurrency converts it on the row's own date — history is never
        // re-priced at today's rate. Only when a product has no purchase on file does this fall back
        // to products.cost_price, which is UGX (see COST_CURRENCY).
        //
        // The previous version tagged every figure with $ctx->nativeCurrency(). On a USD-base tenant
        // that returns 'USD', so UGX costs were being labelled and summed as dollars.
        //
        // Products with no cost at all are counted and named rather than treated as free: a total that
        // quietly excludes half the shelf is a worse number than no total, and this is a figure
        // meant to be waved at the sales team.
        $rc        = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $disp      = $rc->consolidationCurrency();
        $buyCost   = $this->costMap($t, array_keys($open));
        $valueRows = []; $noCost = 0; $noCostNames = []; $fromCatalogue = 0; $fromPurchase = 0;
        $counted = 0;
        try { $counted = (int) Product::where('tenant_id', $t)->where('active', true)->count(); }
        catch (\Throwable $e) { $counted = 0; }

        $inStock = 0; $soldOut = 0; $over = 0; $units = 0.0;
        $reservedUnits = 0.0; $reservedLines = 0;
        $belowReorder = 0; $withFloor = 0;   // products at/under their reorder point, and how many have one set
        $rows = [];
        foreach ($open as $pid => $o) {
            $oq  = round((float) $o['qty'], 3);
            $bq  = round((float) ($bought[$pid]['qty'] ?? 0), 3);
            $sq  = round((float) ($sold[$pid] ?? 0), 3);
            $cur = round($oq + $bq - $sq, 3);

            // Current stock is UNCHANGED — still counted + bought − everything sold. On shelf simply
            // adds back the part that has not left yet, so the three columns always reconcile:
            //     on shelf − reserved = current
            $rsv     = round((float) ($this->reserved[$pid] ?? 0), 3);
            $onShelf = round($cur + $rsv, 3);

            if ($cur > 0)     { $inStock++; $units += $cur; }
            elseif ($cur < 0) { $over++; }
            else              { $soldOut++; }
            $reservedUnits += $rsv;
            if ($rsv > 0) { $reservedLines++; }

            $when = substr((string) $o['at'], 0, 10);
            $sub  = ! empty($o['new'])
                ? 'new line — nothing before the count, so every carton bought is stock'
                : ('counted ' . $when . ($o['by'] !== '' ? (' by ' . $o['by']) : ''));
            $lines = (int) ($bought[$pid]['lines'] ?? 0);
            if ($lines > 0) {
                $sub .= ' · ' . $lines . ' purchase' . ($lines === 1 ? '' : 's') . ' since';
                if (($bought[$pid]['supplier'] ?? '') !== '') { $sub .= ' · last from ' . $bought[$pid]['supplier']; }
            }

            // Cost basis: the last real purchase of this product, else the catalogue price.
            $c = $buyCost[$pid] ?? null;
            if ($c === null && ($costs[$pid] ?? null) !== null && (float) $costs[$pid] > 0) {
                $c = ['unit' => (float) $costs[$pid], 'currency' => self::COST_CURRENCY,
                      'usd' => null, 'date' => null, 'basis' => 'catalogue'];
                if ($cur > 0) { $fromCatalogue++; }
            } elseif ($c !== null && $cur > 0) {
                $fromPurchase++;                          // counted on hand, so the two agree with noCost
            }

            $unitCost = $c['unit'] ?? null;
            $lineVal  = ($unitCost !== null && $cur > 0) ? round($cur * $unitCost, 2) : 0.0;

            // The purchase's own USD figure scaled to the line, so the line converts on the rate that
            // was actually paid rather than today's. Null when there is no stored rate — Fx then dates
            // it from the purchase.
            $lineUsd = ($c !== null && ($c['usd'] ?? null) !== null && $cur > 0)
                ? round(((float) $c['usd']) * $cur, 2) : null;

            $unitText = '—'; $valueText = '—'; $valueSort = 0.0;
            if ($unitCost === null || $unitCost <= 0) {
                if ($cur > 0) {
                    $noCost++;
                    if (count($noCostNames) < 6) { $noCostNames[] = (string) ($names[$pid] ?? ('#' . $pid)); }
                }
            } else {
                $unitText = $rc->text($unitCost, $c['currency'], $c['usd'], $c['date']);
                if ($cur > 0) {
                    $valueRows[] = ['amount' => $lineVal, 'currency' => $c['currency'],
                                    'usd' => $lineUsd, 'date' => $c['date']];
                    $valueText = $rc->text($lineVal, $c['currency'], $lineUsd, $c['date']);
                    // Sorted on the CONSOLIDATED figure, never the native one. In original-currency
                    // mode the column shows each line in its own currency, and ranking 3,000,000 UGX
                    // above $2,000 because the number is bigger would be nonsense.
                    $valueSort = $rc->consolidate([['amount' => $lineVal, 'currency' => $c['currency'],
                                                    'usd' => $lineUsd, 'date' => $c['date']]])['amount'];
                }
            }

            // Reorder status — judged against Free to sell (what Juba counted, less what has left, less
            // what is reserved), and ONLY when the owner has set a floor. A product with no reorder
            // point set is never judged. And because this screen only lists counted products, the ~97%
            // of the catalogue nobody has counted is not a row here at all — so an uncounted shelf can
            // never raise a false "buy now". That is the whole reason the alert can be trusted.
            $rp = $reorder[$pid] ?? null;
            $statusText = '—'; $statusBadge = 'default'; $statusSort = 3;
            if ($rp !== null) {
                $withFloor++;
                if ($cur <= 0) {                                  // has a floor and is out — most urgent
                    $statusText = 'Reorder — out'; $statusBadge = 'danger'; $statusSort = 0; $belowReorder++;
                } elseif ($cur <= $rp) {                          // still on the shelf but at/under the floor
                    $statusText = 'Reorder'; $statusBadge = 'warn'; $statusSort = 1; $belowReorder++;
                } else {
                    $statusText = 'OK'; $statusBadge = 'ok'; $statusSort = 2;
                }
            }

            $rows[] = [
                // Click-through to the product's transaction history (JubaProductCapability). Same
                // derivation, per line — so the drill-down reconciles to this exact row.
                'id'   => $pid,
                'link' => '/api/v1/workspace/juba.product/' . $pid,
                'product' => ['value' => (string) ($names[$pid] ?? ('#' . $pid)), 'sub' => $sub, 'avatar' => false],
                'opening' => ['value' => $this->qty($oq), 'sort' => $oq],
                'bought'  => ['value' => $this->qty($bq), 'sort' => $bq],
                'sold'    => ['value' => $this->qty($sq), 'sort' => $sq],
                'onshelf' => [
                    'value' => $this->qty($onShelf), 'sort' => $onShelf,
                    'tone'  => $onShelf > 0 ? 'ok' : ($onShelf < 0 ? 'danger' : 'default'),
                ],
                'reserved' => [
                    'value' => $rsv > 0 ? $this->qty($rsv) : '—', 'sort' => $rsv,
                    'tone'  => $rsv > 0 ? 'warn' : '',
                ],
                'current' => [
                    'value' => $this->qty($cur), 'sort' => $cur,
                    'tone'  => $cur > 0 ? 'ok' : ($cur < 0 ? 'danger' : 'default'),
                ],
                'status' => ['value' => $statusText, 'badge' => $statusBadge, 'sort' => $statusSort],
                'unitcost' => [
                    'value' => $unitText,
                    'sort'  => $unitCost ?? 0,
                ],
                'value' => [
                    'value' => $valueText,
                    'sort'  => $valueSort,
                    'tone'  => ($unitCost === null || $unitCost <= 0) && $cur > 0 ? 'warn' : '',
                ],
            ];
        }

        // Sorted by MONEY, not by quantity. A thousand cheap units is not the thing to chase; the
        // expensive pallet nobody has shifted is.
        usort($rows, fn ($a, $b) => $b['value']['sort'] <=> $a['value']['sort']);
        $stockValue = $rc->consolidate($valueRows)['amount'];

        // Sales since the count against a product nobody has counted. Not shown as a row — there is
        // no shelf figure to show — but counted, because it is the size of the job still to do.
        $ghosts = 0;
        foreach ($sold as $pid => $q) { if ($q > 0 && ! isset($open[$pid])) { $ghosts++; } }

        $columns = [
            ['key' => 'product', 'label' => 'Product', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'opening', 'label' => 'Counted', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'bought',  'label' => 'Bought since', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'sold',    'label' => 'Sold since', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'onshelf', 'label' => 'On shelf', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'reserved', 'label' => 'Reserved', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'current', 'label' => 'Free to sell', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status', 'label' => 'Reorder', 'type' => 'badge', 'sortable' => true],
            ['key' => 'unitcost', 'label' => 'Cost each', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'value', 'label' => 'Value at cost', 'align' => 'right', 'type' => 'num', 'sortable' => true],
        ];

        $notCounted = max(0, $counted - count($open));

        $sub = 'On shelf = what Juba counted, plus everything bought since, less everything that has actually '
             . 'left. Reserved = sold but still standing here, on orders not yet dispatched. Free to sell = '
             . 'on shelf less reserved, and it is the same number this screen has always shown. '
             . 'A purchase adds the moment it is saved against a product; an invoice deducts the moment it '
             . 'is raised. Cash, credit and unpaid all count — payment status never changes stock.';

        // Which states count as still-here, named, because a split nobody can audit is just a
        // second number to distrust.
        if ($reservedUnits > 0) {
            $sub .= ' ' . $this->qty(round($reservedUnits, 3)) . ' units across '
                 . $reservedLines . ' product' . ($reservedLines === 1 ? '' : 's')
                 . ' are reserved: sold on an order still at ' . implode(', ', self::ON_SHELF_STATUSES)
                 . '. Anything dispatched, in transit or delivered has left and is not reserved. '
                 . 'This is why a physical count reads higher than Free to sell, and both are right.';
        }

        // v5.10 — the two ways goods move without the screen noticing, stated where the number is.
        // Both are silent by nature: a purchase typed as free text and an invoice worded differently
        // than the catalogue both look completely normal on their own screens.
        if ($noCost > 0) {
            $sub .= ' Stock value is at COST and excludes ' . $noCost . ' item'
                 . ($noCost === 1 ? '' : 's') . ' with no cost price set — '
                 . implode(', ', $noCostNames) . (count($noCostNames) < $noCost ? ' …' : '')
                 . ' — so the shelf is worth MORE than the tile says.';
        }

        // Where each cost came from, and in what currency, stated on the screen. A valuation whose
        // basis is invisible is a number nobody can argue with, which is the opposite of useful.
        $sub .= ' Cost is the price on the last purchase of that item, in the currency it was bought in,'
             . ' shown at your reporting-currency setting.';
        if ($fromCatalogue > 0) {
            $sub .= ' ' . $fromCatalogue . ' item' . ($fromCatalogue === 1 ? ' has' : 's have')
                 . ' no purchase on file, so ' . ($fromCatalogue === 1 ? 'it uses' : 'they use')
                 . ' the catalogue cost price, which is held in ' . self::COST_CURRENCY . '.';
        }
        if ($this->costError) {
            $sub .= ' ⚠ Purchase prices could not be read, so every cost here is the catalogue figure.';
        }

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
        if ($withFloor > 0) {
            $sub .= ' The Reorder column checks Free to sell against each product\'s reorder point: '
                 . $belowReorder . ' of ' . $withFloor . ' with a floor set '
                 . ($belowReorder === 1 ? 'has' : 'have') . ' reached it. Products with no reorder point set — '
                 . 'and the ' . number_format($notCounted) . ' never counted — are not judged, because a floor '
                 . 'only means something against a stock figure you trust. Set one on the product page.';
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
                // $disp, NOT $cur. $cur is reassigned inside the row loop to that row's CURRENT STOCK,
                // so this tile was formatting the total with the last product's carton count as its
                // currency code — "2,475 115". Two variables, one letter apart, one of them a float.
                ['key' => 'value', 'label' => 'Stock value', 'value' => \App\Capability\Support\Money::fmt($stockValue, $disp),
                 'tone' => 'ok',
                 'foot' => $noCost > 0
                     ? ($noCost . ' item' . ($noCost === 1 ? '' : 's') . ' have no cost price, so the real figure is higher')
                     : 'at cost, across every counted item on hand'],
                ['key' => 'instock', 'label' => 'In stock', 'value' => $inStock, 'tone' => 'ok', 'foot' => 'counted items still on hand'],
                ['key' => 'units', 'label' => 'Units on hand', 'value' => $this->qty(round($units, 3)), 'tone' => 'ok', 'foot' => 'total across those items'],
                ['key' => 'reserved', 'label' => 'Reserved', 'value' => $this->qty(round($reservedUnits, 3)),
                 'tone' => $reservedUnits > 0 ? 'warn' : 'ok',
                 'foot' => $reservedUnits > 0
                     ? ('sold on ' . $reservedLines . ' product' . ($reservedLines === 1 ? '' : 's') . ', still on the shelf')
                     : 'nothing sold is still standing here'],
                ['key' => 'soldout', 'label' => 'Sold out', 'value' => $soldOut, 'tone' => $soldOut > 0 ? 'warn' : 'ok', 'foot' => 'counted, all sold since'],
                ['key' => 'over', 'label' => 'Sold more than we had', 'value' => $over,
                 'tone' => $over > 0 ? 'danger' : 'ok',
                 'foot' => 'sold more since the count than the count plus purchases allows'],
                ['key' => 'uncounted', 'label' => 'Never counted', 'value' => number_format($notCounted),
                 'tone' => $notCounted > 0 ? 'warn' : 'ok',
                 'foot' => $ghosts > 0 ? ($ghosts . ' of them have sold since') : 'not listed until somebody counts them'],
                // The actionable number: counted products with a reorder point set that have fallen to or
                // below it. Owner-set floors only — silent (value 0) until somebody sets one.
                ['key' => 'reorder', 'label' => 'Below reorder', 'value' => $belowReorder,
                 'tone' => $belowReorder > 0 ? 'danger' : 'ok',
                 'foot' => $withFloor > 0
                     ? ('of ' . $withFloor . ' with a reorder point set — add these to a Demand Plan')
                     : 'set a reorder point on a product to watch it here'],
            ])
            // Grid totals footer as well as the tile: the tile is the headline, the footer is what
            // stays visible when somebody filters the table down to one category.
            ->table($columns, $rows, [], [
                'currency' => $disp,
                'totals'   => [
                    ['key' => 'onshelf', 'label' => 'On shelf'],
                    ['key' => 'reserved', 'label' => 'Reserved'],
                    ['key' => 'current', 'label' => 'Free to sell'],
                    ['key' => 'value', 'label' => 'Value at cost', 'money' => true],
                ],
            ])
            ->meta([
                'capability' => 'juba.stock', 'ttl' => 30, 'permissions' => ['menu:products'],
                // Not rendered anywhere. It exists so the screen can be CHECKED from outside itself.
                // A browser can walk every invoice document and add the quantities up independently —
                // it cannot see an order's items_json, which no read endpoint exposes. So the screen
                // states which half of every Sold figure came from which document, and the checker
                // verifies the half it can reach instead of guessing at the whole and crying wolf.
                'reconcile' => $this->reconcile($detail, $names, count($rows)),
                // The valuation's provenance, so the total can be argued with. Not rendered.
                'value' => [
                    'display'     => $disp,                       // what the tile and totals are in
                    'mode'        => $rc->modeLabel(),            // base / original / ?cur= override
                    'costBase'    => self::COST_CURRENCY,         // the catalogue fallback's currency
                    'fromPurchase'=> $fromPurchase,              // priced from a real bill
                    'fromCatalogue'=> $fromCatalogue,             // priced from products.cost_price
                    'noCost'      => $noCost,                     // on hand, excluded from the total
                    'total'       => round($stockValue, 2),
                    'error'       => $this->costError,
                ],
                // on shelf − reserved = free to sell, per product and in total. Checkable from outside.
                'shelf' => [
                    'reservedUnits'    => round($reservedUnits, 3),
                    'reservedProducts' => $reservedLines,
                    'heldStatuses'     => self::ON_SHELF_STATUSES,
                ],
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
     * What each product last COST, and in what currency.
     *
     * Asked of the purchase document, not the catalogue. Every order_costs row carries its own
     * `currency` and `rate_to_usd`, so the price is currency-tagged evidence rather than something
     * inferred from a tenant setting — and the stored rate lets ReportingCurrency convert on the rate
     * that was actually paid instead of today's.
     *
     * Deliberately NOT filtered by the opening cutoff: a product counted in Juba but not re-bought
     * since still has a real price on its last bill, and that is the honest basis to value it at.
     *
     * Its own query and its own try/catch, separate from purchasedMap. A missing or renamed cost
     * column must cost this screen its VALUATION, never its stock arithmetic — a Juba Stock page that
     * reports zero cartons bought because it could not read a price would be far worse than one that
     * reports cartons with no price against them.
     *
     * @param  array<int,int> $ids  product ids to price
     * @return array<int,array{unit:float,currency:string,usd:?float,date:?string,basis:string}>
     */
    private function costMap(int $tid, array $ids): array
    {
        $out = [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (! $ids) { return $out; }

        try {
            $rows = DB::table('order_costs')
                ->where('tenant_id', $tid)->where('stage', 'purchase')->whereNull('voided_at')
                ->whereIn('product_id', $ids)
                ->where('qty', '>', 0)->where('unit_cost', '>', 0)
                ->orderBy('product_id')->orderByDesc('created_at')->orderByDesc('id')
                ->get(['product_id', 'unit_cost', 'currency', 'rate_to_usd', 'created_at']);
        } catch (\Throwable $e) {
            $this->costError = true;

            return $out;
        }

        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            if (isset($out[$pid])) { continue; }          // newest-first, so the first row per id wins
            $unit = (float) $r->unit_cost;
            if ($unit <= 0) { continue; }
            $rate = (float) ($r->rate_to_usd ?? 0);
            $cur  = strtoupper(trim((string) ($r->currency ?? '')));
            $out[$pid] = [
                'unit'     => $unit,
                'currency' => $cur !== '' ? $cur : self::COST_CURRENCY,
                // rate_to_usd is native units per 1 USD, so the unit price in USD is unit / rate.
                'usd'      => $rate > 0 ? round($unit / $rate, 6) : null,
                'date'     => substr((string) $r->created_at, 0, 10) ?: null,
                'basis'    => 'purchase',
            ];
        }

        return $out;
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
        $this->reserved = [];

        // Sold, but not yet gone. An INVOICE never lands here — billing goods is the strongest
        // statement there is that they left. Only an uninvoiced order at a pre-dispatch status does.
        $onShelfStatus = function ($raw): bool {
            $k = trim((string) preg_replace('/[\s_-]+/u', ' ', mb_strtolower(trim((string) $raw))));

            return $k !== '' && in_array($k, self::ON_SHELF_STATUSES, true);
        };
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
                ->get(['id', 'order_no', 'items_json', 'created_at', 'status']) as $o) {
                $oid = (int) $o->id;
                if (isset($invoiced[$oid])) { continue; }        // already counted on its invoice
                $owhen = (string) $o->created_at;
                $held  = $onShelfStatus($o->status ?? '');       // sold, but still standing here

                $ij = $o->items_json;
                if (is_string($ij)) { $ij = json_decode($ij, true); }
                if (! is_array($ij) || $ij === []) { continue; }

                $ono = (string) ($o->order_no ?: ('#' . $oid));
                foreach ($ij as $it) {
                    if (! is_array($it)) { continue; }
                    $q = (float) ($it['qty'] ?? 0);
                    if ($q <= 0) { continue; }
                    $pid = (int) ($it['product_id'] ?? ($it['id'] ?? 0));
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

                    // v5.15 — THE DATE GATE, and it belongs HERE: after the product is known, however
                    // it was found. It used to sit above, inside `if ($pid > 0)`, so it only ever
                    // tested lines whose items_json carried a product_id. A line with no product_id
                    // was resolved BY NAME and then fell straight through to the total with no date
                    // test at all — so ORD-4, ORD-10 and ORD-14, orders from weeks ago, were being
                    // deducted from a count taken tonight. Twenty cartons of foil kept reappearing
                    // after every recount because the count could not close off history that was
                    // never being compared to it.
                    //
                    // Same two rules the invoice half above applies, and now written once:
                    //   product HAS a count   → anything at or before it is already inside the count
                    //   product has NO count  → anything before the first count is history (preSold)
                    if (isset($since[$pid])) {
                        if ($owhen !== '' && $owhen <= $since[$pid]['at']) { continue; }
                    } elseif ($firstOpen !== null && $owhen !== '' && $owhen <= $firstOpen) {
                        $this->preSold[$pid] = ($this->preSold[$pid] ?? 0) + $q;
                        continue;
                    }

                    $sold[$pid] = ($sold[$pid] ?? 0) + $q;
                    $d['ord'][$pid] = ($d['ord'][$pid] ?? 0) + $q;
                    $d['ordLines']++; $d['ordUnits'] += $q;
                    $d['ordNos'][$oid] = $ono;
                    $fromJson[$oid] = true;
                    if ($held) { $this->reserved[$pid] = ($this->reserved[$pid] ?? 0) + $q; }
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
                       'orders.order_no', 'orders.created_at', 'orders.status']) as $r) {
                $oid = (int) $r->order_id;
                if (isset($invoiced[$oid]) || isset($fromJson[$oid])) { continue; }
                $q = (float) $r->qty;
                if ($q <= 0) { continue; }
                $pid = (int) $r->product_id;
                // The same two rules as the other two paths. The second one was missing here too:
                // a product with no count of its own had its whole order history counted as sold
                // since, instead of being closed off as the history it is.
                if (isset($since[$pid])) {
                    if ((string) $r->created_at <= $since[$pid]['at']) { continue; }
                } elseif ($firstOpen !== null && (string) $r->created_at <= $firstOpen) {
                    $this->preSold[$pid] = ($this->preSold[$pid] ?? 0) + $q;
                    continue;
                }
                $sold[$pid] = ($sold[$pid] ?? 0) + $q;
                $d['ord'][$pid] = ($d['ord'][$pid] ?? 0) + $q;
                $d['ordLines']++; $d['ordUnits'] += $q;
                $d['ordNos'][$oid] = (string) ($r->order_no ?: ('#' . $oid));
                if ($onShelfStatus($r->status ?? '')) { $this->reserved[$pid] = ($this->reserved[$pid] ?? 0) + $q; }
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
