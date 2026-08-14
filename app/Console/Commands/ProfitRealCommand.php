<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Money\ReportingCurrency;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v5.17 — what the business ACTUALLY earned, on a trading basis.
 *
 * The Books screen reports a CASH margin. Its cost of goods is money paid to suppliers in the
 * period, not the cost of the goods that were sold — the 04 Aug audit named this as the one thing
 * still open, and it is the reason the profit figure lurches whenever a big supplier bill settles:
 *
 *   buy 100 cartons in July, sell 10  -> July shows the whole bill as cost, so July looks terrible
 *   sell the other 90 in August       -> August shows no cost at all, so August looks brilliant
 *
 * Neither month is true. This command answers the accrual question instead:
 *
 *   revenue        what was billed for goods that left
 *   cost of sales  the quantity that left x what that item actually cost us
 *   gross profit   the difference, which is the trading margin
 *   expenses       running the business — purchases EXCLUDED, they are stock, not cost
 *   net profit     what the business really made
 *
 * ── the one thing that makes this honest ────────────────────────────────────────────────────────
 *
 * A sold line can only be costed if it can be matched to a product AND that product has a price on
 * record. Every line that cannot is COUNTED and its revenue REPORTED, because revenue with no cost
 * against it inflates the margin, and a margin that quietly excludes its own failures is worse than
 * no margin at all. Read the coverage figure before you read the profit.
 *
 * Matching and costing are the same rules Juba Stock uses — exact name or a typed keyword alias,
 * never a similarity score; cost from the last purchase bill in the currency it was paid in, falling
 * back to products.cost_price which is held in UGX.
 *
 * READ ONLY. Writes nothing, changes no setting, sends nothing.
 *
 *   php artisan profit:real --tenant=17
 *   php artisan profit:real --tenant=17 --from=2026-08-01
 *   php artisan profit:real --tenant=17 --from=2026-07-01 --to=2026-07-31
 */
final class ProfitRealCommand extends Command
{
    protected $signature = 'profit:real
        {--tenant=17 : tenant id}
        {--from= : only count documents on or after this date (YYYY-MM-DD)}
        {--to= : only count documents on or before this date (YYYY-MM-DD)}
        {--top=12 : how many products to list, best margin first}';

    protected $description = 'Trading profit from the documents: revenue, cost of what actually sold, expenses.';

    /** Money-in stages in the cashbook. Everything else is money out. */
    private const IN_STAGES = ['funding', 'cash_in', 'customer_payment'];

    /** products.cost_price is written by OrderCostController as UGX. See claude/COST-PRICE-IS-UGX.md */
    private const COST_CURRENCY = 'UGX';

    public function handle(TenantContext $ctx): int
    {
        $T = (int) $this->option('tenant');
        $ctx->set($T);                       // the zero-rows trap: the global scope fails CLOSED

        $t = Tenant::find($T);
        if (! $t) { $this->error('No tenant ' . $T); return self::FAILURE; }

        $from = trim((string) $this->option('from')) ?: null;
        $to   = trim((string) $this->option('to')) ?: null;
        $hi   = $to !== null ? ($to . ' 23:59:59') : null;

        $rc  = ReportingCurrency::forTenant($t);
        $cur = $rc->consolidationCurrency();
        $usd = fn (float $amt, ?string $c, ?float $u = null, ?string $d = null)
            => $rc->consolidate([['amount' => $amt, 'currency' => $c ?: $cur, 'usd' => $u, 'date' => $d]])['amount'];

        $line = fn ($s = '') => $this->line((string) $s);
        $rule = fn () => $line(str_repeat('-', 78));
        $m    = fn (float $v) => str_pad(\App\Capability\Support\Money::fmt($v, $cur), 16, ' ', STR_PAD_LEFT);

        $line('');
        $line('REAL PROFIT (trading basis) — ' . $t->name . ' — tenant ' . $T);
        $line('   period: ' . ($from ?? 'the beginning') . '  to  ' . ($to ?? 'now')
            . '   ·   reported in ' . $cur);

        /* ---- the maps: name -> product, and product -> what it cost ----------------------- */
        [$byName, $names] = $this->nameMap($T);
        $cost = $this->costMap($T);
        if (! $byName) { $this->error('No products readable for tenant ' . $T . ' — check the tenant id.'); return self::FAILURE; }

        /* ---- revenue and cost of sales, line by line -------------------------------------- */
        $rev = 0.0; $cogs = 0.0;
        $lines = 0; $costedBill = 0; $costedCat = 0; $uncosted = 0;
        $uncostedRev = 0.0; $uncostedNames = [];
        $invDocs = []; $ordDocs = [];
        $perProduct = [];

        $take = function (?int $pid, string $rawName, float $qty, float $amount, ?string $docCur,
                          ?float $docUsd, ?string $date) use (&$rev, &$cogs, &$lines, &$costedBill, &$costedCat,
                          &$uncosted, &$uncostedRev, &$uncostedNames, &$perProduct, $cost, $names, $usd) {
            $lines++;
            $r = $usd($amount, $docCur, $docUsd, $date);
            $rev += $r;

            $c = $pid !== null ? ($cost[$pid] ?? null) : null;
            if ($c === null) {
                $uncosted++;
                $uncostedRev += $r;
                $nm = $pid !== null ? (string) ($names[$pid] ?? ('#' . $pid)) : trim($rawName);
                if ($nm !== '' && count($uncostedNames) < 8 && ! in_array($nm, $uncostedNames, true)) { $uncostedNames[] = $nm; }

                return;
            }
            $c['basis'] === 'purchase' ? $costedBill++ : $costedCat++;
            // The unit cost converts on the rate that was actually PAID (its own stored usd), so a
            // margin is never re-priced by today's exchange rate.
            $lineCost = $usd($c['unit'] * $qty, $c['currency'],
                ($c['usd'] !== null ? $c['usd'] * $qty : null), $c['date']);
            $cogs += $lineCost;

            $k = (int) $pid;
            if (! isset($perProduct[$k])) { $perProduct[$k] = ['qty' => 0.0, 'rev' => 0.0, 'cost' => 0.0]; }
            $perProduct[$k]['qty']  += $qty;
            $perProduct[$k]['rev']  += $r;
            $perProduct[$k]['cost'] += $lineCost;
        };

        // 1. invoice lines. The invoice is the fuller sales record on this shop — see JubaStockCapability.
        $invoiced = [];
        try {
            $q = DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoice_items.tenant_id', $T)
                ->where('invoices.status', '!=', 'void');
            foreach ($q->get(['invoice_items.name', 'invoice_items.qty', 'invoice_items.line_total',
                              'invoices.id as inv_id', 'invoices.order_id', 'invoices.currency',
                              'invoices.issued_at', 'invoices.created_at']) as $r) {
                if ($r->order_id) { $invoiced[(int) $r->order_id] = true; }
                $when = (string) ($r->issued_at ?: $r->created_at);
                if ($from !== null && $when < $from) { continue; }
                if ($hi !== null && $when > $hi) { continue; }
                $qty = (float) $r->qty;
                if ($qty <= 0) { continue; }
                $invDocs[(int) $r->inv_id] = true;
                $take($byName[$this->norm((string) $r->name)] ?? null, (string) $r->name, $qty,
                    (float) $r->line_total, strtoupper((string) ($r->currency ?: '')) ?: null, null, substr($when, 0, 10));
            }
        } catch (\Throwable $e) {
            $this->error('   invoice lines could not be read: ' . $e->getMessage());

            return self::FAILURE;
        }

        // 2. orders that were never invoiced — their own lines, from items_json.
        try {
            foreach (DB::table('orders')->where('tenant_id', $T)
                ->whereRaw("lower(coalesce(status,'')) not in ('cancelled','canceled','rejected','void')")
                ->get(['id', 'items_json', 'created_at', 'sell_currency']) as $o) {
                $oid = (int) $o->id;
                if (isset($invoiced[$oid])) { continue; }
                $when = (string) $o->created_at;
                if ($from !== null && $when < $from) { continue; }
                if ($hi !== null && $when > $hi) { continue; }
                $ij = is_string($o->items_json) ? json_decode($o->items_json, true) : $o->items_json;
                if (! is_array($ij) || $ij === []) { continue; }
                $oc = strtoupper((string) ($o->sell_currency ?? '')) ?: null;
                foreach ($ij as $it) {
                    if (! is_array($it)) { continue; }
                    $qty = (float) ($it['qty'] ?? 0);
                    if ($qty <= 0) { continue; }
                    $pid = (int) ($it['product_id'] ?? ($it['id'] ?? 0));
                    if ($pid <= 0) { $pid = $byName[$this->norm((string) ($it['name'] ?? ''))] ?? null; }
                    $ordDocs[$oid] = true;
                    $take($pid ?: null, (string) ($it['name'] ?? ''), $qty, $this->lineAmount($it, $qty),
                        $oc, null, substr($when, 0, 10));
                }
            }
        } catch (\Throwable $e) {
            $this->error('   order lines could not be read: ' . $e->getMessage());
        }

        /* ---- expenses ---------------------------------------------------------------------
         *
         * v5.17.1 — classified by the SERVER'S OWN RULES, from PanelApiController::cashPnl. The first
         * version of this command bucketed on `stage` alone and produced a $92,471 expense total on a
         * shop with $38,984 of revenue — a fictional $70,903 loss. What it swept in:
         *
         *   Conversion / Transfer  $62,723   both legs of an FX swap or a move between holders. They
         *                                    net to zero by construction. cashPnl excludes them by
         *                                    CATEGORY, and the v4.36 audit records that a NULL-category
         *                                    bug here was caught by its own reconciliation check.
         *   supplier_payment       $18,223   settling a bill that was already recorded as a purchase.
         *                                    On a cash basis cashPnl calls it cost of goods; on an
         *                                    ACCRUAL basis it is neither cost nor expense — the goods
         *                                    were costed when they SOLD, a few lines above. Counting
         *                                    it here charges the same stock twice.
         *   Loan Repayment          $2,503   financing. Turns cash into a smaller debt; not a cost.
         *                                    Detected exactly as cashPnl detects it, same setting.
         *   Local purchase / Purchase        stock, reached by category rather than stage.
         *
         * Only `expense` and `cash_out` are running costs, which is the same split cashPnl uses:
         * operating profit = gross − opex − other_out + other_in.
         */
        $exp = []; $expTotal = 0.0; $purchases = 0.0;
        $skipped = []; $otherIn = 0.0;
        $lenders = $this->lenderPattern((string) $t->setting('books.loan_holders', 'Bhavin'));
        $isCapitalNote = fn ($n) => (bool) preg_match('/capital|startup|introduc|invest/i', (string) $n);
        try {
            $q = DB::table('order_costs')->where('tenant_id', $T)->whereNull('voided_at')
                ->whereNotIn('status', ['void', 'rejected']);
            if ($from !== null) { $q->where('created_at', '>=', $from); }
            if ($hi !== null)   { $q->where('created_at', '<=', $hi); }
            foreach ($q->get(['stage', 'category', 'native_amount', 'currency', 'usd_amount', 'created_at', 'note', 'holder']) as $c) {
                $stage = strtolower(trim((string) $c->stage));
                $cat   = trim((string) ($c->category ?? ''));
                $amt = $usd((float) $c->native_amount, (string) $c->currency,
                    ((float) $c->usd_amount) > 0 ? (float) $c->usd_amount : null, substr((string) $c->created_at, 0, 10));
                $drop = function (string $why) use (&$skipped, $amt) { $skipped[$why] = ($skipped[$why] ?? 0) + $amt; };

                // 1. FX legs and custody moves, by category — they net to zero across the pair.
                if (in_array($cat, ['Conversion', 'Transfer'], true)) { $drop('currency conversions and transfers between holders'); continue; }
                // 2. financing, before anything else can claim it
                if ($this->isLoanRepayment($lenders, $stage, (string) $c->holder, (string) $c->note, $cat)) {
                    $drop('loan repayments (financing, not a cost)'); continue;
                }
                // 3. stock, and settling the bills for stock
                if ($stage === 'purchase')          { $purchases += $amt; $drop('purchases (stock — costed when the goods SELL)'); continue; }
                if ($stage === 'supplier_payment')  { $drop('supplier bill settlements (the goods were costed when they sold)'); continue; }
                // 4. owners' money in and out
                if ($stage === 'funding' || ($stage === 'cash_in' && $isCapitalNote($c->note))) { $drop('capital introduced'); continue; }
                if ($stage === 'drawing' || $stage === 'drawings') { $drop('owner drawings'); continue; }
                // 5. customer receipts — the INVOICE is the revenue, not the payment for it
                if ($stage === 'customer_payment') { $drop('customer payments (the invoice is already counted as revenue)'); continue; }
                if ($stage === 'cash_in')          { $otherIn += $amt; continue; }

                if ($stage !== 'expense' && $stage !== 'cash_out') { $drop('stage "' . ($stage ?: 'blank') . '" — not classified'); continue; }
                $key = $cat !== '' ? $cat : $stage;
                $exp[$key] = ($exp[$key] ?? 0) + $amt;
                $expTotal += $amt;
            }
        } catch (\Throwable $e) {
            $this->error('   expenses could not be read: ' . $e->getMessage());
        }
        $expTotal -= $otherIn;                    // other cash in offsets other cash out, as cashPnl does

        /* ---- report ----------------------------------------------------------------------- */
        $gross = $rev - $cogs;
        $net   = $gross - $expTotal;
        $marg  = $rev > 0 ? ($gross / $rev * 100) : 0.0;
        $cov   = $rev > 0 ? (($rev - $uncostedRev) / $rev * 100) : 0.0;

        $rule();
        $line('WHAT WE SOLD');
        $line('   invoices                    ' . str_pad((string) count($invDocs), 6, ' ', STR_PAD_LEFT) . ' documents');
        $line('   orders never invoiced       ' . str_pad((string) count($ordDocs), 6, ' ', STR_PAD_LEFT) . ' documents');
        $line('   sold lines                  ' . str_pad((string) $lines, 6, ' ', STR_PAD_LEFT));
        $line('   REVENUE                  ' . $m($rev));

        $rule();
        $line('WHAT THOSE GOODS COST US');
        $line('   costed from a purchase bill ' . str_pad((string) $costedBill, 6, ' ', STR_PAD_LEFT) . ' lines');
        $line('   costed from the catalogue   ' . str_pad((string) $costedCat, 6, ' ', STR_PAD_LEFT) . ' lines  (read as ' . self::COST_CURRENCY . ')');
        $line('   COULD NOT BE COSTED         ' . str_pad((string) $uncosted, 6, ' ', STR_PAD_LEFT) . ' lines  carrying ' . trim($m($uncostedRev)) . ' of revenue');
        if ($uncosted > 0) {
            $line('      ' . implode(', ', array_map(fn ($n) => mb_substr($n, 0, 32), $uncostedNames))
                . (count($uncostedNames) >= 8 ? ' …' : ''));
        }
        $line('   COST OF GOODS SOLD       ' . $m($cogs));

        $rule();
        $line('   GROSS PROFIT             ' . $m($gross) . '   ' . number_format($marg, 1) . '% margin');
        if ($cov < 99.5) {
            $line('   ⚠ only ' . number_format($cov, 1) . '% of revenue has a cost against it. The other '
                . number_format(100 - $cov, 1) . '% is counted as pure profit here, so the');
            $line('     REAL margin is LOWER than the figure above. Give those items a cost price and re-run.');
        }

        $rule();
        $line('RUNNING THE BUSINESS  (purchases excluded — they are stock, not cost)');
        arsort($exp);
        foreach (array_slice($exp, 0, 12, true) as $k => $v) {
            $line('   ' . str_pad(mb_substr((string) $k, 0, 28), 30) . $m($v));
        }
        if (count($exp) > 12) { $line('   ' . str_pad('… ' . (count($exp) - 12) . ' more', 30)); }
        if ($otherIn > 0.005) { $line('   ' . str_pad('less other cash in', 30) . $m(-$otherIn)); }
        $line('   TOTAL EXPENSES           ' . $m($expTotal));

        // Every dollar of cash movement that is NOT an expense, and why. Without this the total is
        // just a number to trust; with it, it is a number to check.
        if ($skipped) {
            arsort($skipped);
            $line('');
            $line('   cash movements deliberately NOT counted as expenses:');
            foreach ($skipped as $why => $v) { $line('      ' . $m($v) . '   ' . $why); }
        }

        $rule();
        $line('   NET PROFIT               ' . $m($net) . ($net < 0 ? '   *** A LOSS ***' : ''));
        $rule();

        $line('HOW THIS DIFFERS FROM THE BOOKS SCREEN');
        $line('   cash paid to suppliers      ' . $m($purchases));
        $line('   cost of goods actually sold ' . $m($cogs));
        $line('   difference                  ' . $m($purchases - $cogs));
        $line('');
        $line('   The Books screen treats the FIRST figure as cost. This command uses the SECOND.');
        $line('   The difference is goods bought and not yet sold — stock. If it is large and positive');
        $line('   the Books profit is understated; if negative you are selling stock bought earlier');
        $line('   and the Books profit is flattered.');

        /* ---- best and worst products ------------------------------------------------------ */
        $top = max(1, (int) $this->option('top'));
        $rows = [];
        foreach ($perProduct as $pid => $p) {
            if ($p['rev'] <= 0) { continue; }
            $rows[] = ['n' => (string) ($names[$pid] ?? ('#' . $pid)), 'q' => $p['qty'],
                       'r' => $p['rev'], 'c' => $p['cost'], 'g' => $p['rev'] - $p['cost'],
                       'pc' => ($p['rev'] - $p['cost']) / $p['rev'] * 100];
        }
        usort($rows, fn ($a, $b) => $b['g'] <=> $a['g']);
        $show = function (array $set, string $title) use ($line, $rule, $m) {
            $rule();
            $line($title);
            $line('   ' . str_pad('gross', 16, ' ', STR_PAD_LEFT) . '  ' . str_pad('margin', 8, ' ', STR_PAD_LEFT)
                . '  ' . str_pad('units', 8, ' ', STR_PAD_LEFT) . '  product');
            foreach ($set as $r) {
                $line('   ' . $m($r['g']) . '  ' . str_pad(number_format($r['pc'], 1) . '%', 8, ' ', STR_PAD_LEFT)
                    . '  ' . str_pad(rtrim(rtrim(number_format($r['q'], 2, '.', ''), '0'), '.'), 8, ' ', STR_PAD_LEFT)
                    . '  ' . mb_substr($r['n'], 0, 40));
            }
        };
        if ($rows) {
            $show(array_slice($rows, 0, $top), 'WHERE THE MONEY IS MADE');
            $losers = array_values(array_filter($rows, fn ($r) => $r['g'] < 0));
            if ($losers) {
                usort($losers, fn ($a, $b) => $a['g'] <=> $b['g']);
                $show(array_slice($losers, 0, $top), 'SOLD AT A LOSS — check the price or the cost');
            }
        }

        $line('');
        $line('Read-only. Nothing was changed.');

        return self::SUCCESS;
    }

    /** Exact, case-folded, whitespace-collapsed. The shared normaliser, not a second one. */
    private function norm(string $s): string
    {
        return \App\Services\Inventory\PurchaseNameMatch::key($s);
    }

    /**
     * name -> product id, including the aliases typed into products.keywords. A string claimed by two
     * products is AMBIGUOUS and matched to NEITHER — putting a sale on the wrong product is worse
     * than leaving it uncosted, because uncosted is visible on the report and wrong is not.
     *
     * @return array{0:array<string,?int>,1:array<int,string>}
     */
    private function nameMap(int $tid): array
    {
        $byName = []; $names = [];
        $claim = function (string $raw, int $pid) use (&$byName) {
            $k = $this->norm($raw);
            if ($k === '') { return; }
            $byName[$k] = (array_key_exists($k, $byName) && $byName[$k] !== $pid) ? null : $pid;
        };
        try {
            foreach (Product::where('tenant_id', $tid)->get(['id', 'name', 'keywords']) as $p) {
                $pid = (int) $p->id;
                $names[$pid] = (string) $p->name;
                $claim((string) $p->name, $pid);
                $kw = (string) ($p->keywords ?? '');
                if (preg_match('/\R/u', $kw)) {
                    foreach (preg_split('/\R/u', $kw) ?: [] as $a) { $claim($a, $pid); }
                } else {
                    foreach (\App\Services\Inventory\PurchaseNameMatch::aliases($kw) as $a) { $claim($a, $pid); }
                    $claim($kw, $pid);
                }
            }
        } catch (\Throwable $e) {
            return [[], []];
        }

        return [$byName, $names];
    }

    /**
     * What each product cost, from the last purchase bill, in the currency it was paid in.
     * Identical rule to JubaStockCapability::costMap so the two screens cannot disagree.
     *
     * @return array<int,array{unit:float,currency:string,usd:?float,date:?string,basis:string}>
     */
    private function costMap(int $tid): array
    {
        $out = [];
        try {
            foreach (DB::table('order_costs')
                ->where('tenant_id', $tid)->where('stage', 'purchase')->whereNull('voided_at')
                ->whereNotNull('product_id')->where('qty', '>', 0)->where('unit_cost', '>', 0)
                ->orderBy('product_id')->orderByDesc('created_at')->orderByDesc('id')
                ->get(['product_id', 'unit_cost', 'currency', 'rate_to_usd', 'created_at']) as $r) {
                $pid = (int) $r->product_id;
                if (isset($out[$pid])) { continue; }              // newest first
                $rate = (float) ($r->rate_to_usd ?? 0);
                $out[$pid] = ['unit' => (float) $r->unit_cost,
                              'currency' => strtoupper(trim((string) $r->currency)) ?: self::COST_CURRENCY,
                              'usd' => $rate > 0 ? (float) $r->unit_cost / $rate : null,
                              'date' => substr((string) $r->created_at, 0, 10), 'basis' => 'purchase'];
            }
        } catch (\Throwable $e) {
            // fall through to the catalogue only
        }
        try {
            foreach (Product::where('tenant_id', $tid)->whereNotNull('cost_price')->where('cost_price', '>', 0)
                ->get(['id', 'cost_price']) as $p) {
                $pid = (int) $p->id;
                if (isset($out[$pid])) { continue; }
                $out[$pid] = ['unit' => (float) $p->cost_price, 'currency' => self::COST_CURRENCY,
                              'usd' => null, 'date' => null, 'basis' => 'catalogue'];
            }
        } catch (\Throwable $e) {
            // nothing more to try
        }

        return $out;
    }

    /** The lender regex, built exactly as PanelApiController::lenderPattern builds it. */
    private function lenderPattern(string $names): string
    {
        $parts = array_filter(array_map('trim', explode(',', $names)));
        $parts = array_map(fn ($n) => preg_quote($n, '/'), $parts);

        return '/' . ($parts ? implode('|', $parts) : 'bhavin') . '/i';
    }

    /**
     * Money out, naming a lender, and saying so — the same three conditions the server and the Books
     * screen both apply, so a repayment cannot be an expense on one screen and financing on another.
     * The `\b` matters: without it "return" matches inside ordinary words.
     */
    private function isLoanRepayment(string $lenders, string $stage, string $holder, string $note, string $category): bool
    {
        if (in_array($stage, self::IN_STAGES, true)) { return false; }
        $text = trim($holder . ' ' . $note . ' ' . $category);

        return $text !== ''
            && preg_match($lenders, $text) === 1
            && preg_match('/\b(loan|interest|repay|repaid|return)\w*\b/i', $text) === 1;
    }

    /** The money on an order line, whatever the front end called the field. */
    private function lineAmount(array $it, float $qty): float
    {
        foreach (['line_total', 'total', 'amount', 'subtotal'] as $k) {
            if (isset($it[$k]) && is_numeric($it[$k]) && (float) $it[$k] > 0) { return (float) $it[$k]; }
        }
        foreach (['unit_price', 'price', 'rate', 'sell_price'] as $k) {
            if (isset($it[$k]) && is_numeric($it[$k]) && (float) $it[$k] > 0) { return (float) $it[$k] * $qty; }
        }

        return 0.0;
    }
}
