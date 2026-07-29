<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Invoice;
use App\Models\OrderCost;
use App\Services\Finance\PayablesCalculator;
use App\Services\Money\Fx;
use Illuminate\Support\Carbon;

/**
 * Books — a real, read-only set of financial statements built live from the SAME reconciled cashbook
 * (order_costs) the rest of Finance uses. It adds the two things the plain cash P&L cannot show on its
 * own: a director's LOAN as a liability (with 3%/month interest as a finance cost), and a balance sheet
 * that totals cash across ALL currencies in USD (the Finance tile shows only the USD wallet).
 *
 * Nothing here writes to the ledger. The only writes are three CONFIG settings (who is a lender, the
 * monthly rate, and the manual stock value) saved via the owner-only `save_config` command — because
 * live stock quantities aren't reliable until purchases are logged against products.
 *
 * Config (tenant settings, with safe defaults):
 *   books.loan_holders  — comma list of names treated as LENDERS (default "Bhavin")
 *   books.loan_rate     — monthly interest as a fraction (default 0.03 = 3%/month)
 *   books.stock_usd     — manual inventory value in USD until stock goes live (default 0)
 */
final class BooksCapability implements ProcessCapability
{
    /** Cash-in stages (everything else is cash-out), mirroring CashService/cash-pnl. */
    private const IN_STAGES = ['funding', 'cash_in', 'customer_payment'];

    public function key(): string { return 'books'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenant;
        $tid = $ctx->tenantId();

        // ---- config -------------------------------------------------------
        $rate = (float) $t->setting('books.loan_rate', 0.03);
        if ($rate <= 0) $rate = 0.03;
        $stockUsd = (float) $t->setting('books.stock_usd', 0);
        $holders = array_values(array_filter(array_map('trim',
            explode(',', (string) $t->setting('books.loan_holders', 'Bhavin')))));
        $isLender = function ($name) use ($holders): bool {
            $n = (string) $name;
            foreach ($holders as $h) if ($h !== '' && stripos($n, $h) !== false) return true;
            return false;
        };
        $isCapitalNote = fn ($n) => (bool) preg_match('/capital|startup|introduc|invest/i', (string) $n);

        // ---- one pass over the cash ledger -------------------------------
        $b = ['revenue' => 0.0, 'purchase' => 0.0, 'supplier' => 0.0, 'opex' => 0.0,
              'other_in' => 0.0, 'other_out' => 0.0, 'capital' => 0.0, 'drawings' => 0.0];
        $cashUsd = 0.0;                 // all currencies, valued in USD (cost basis)
        $loanPrincipal = 0.0; $loanInterest = 0.0; $intPaid = 0.0; $prinPaid = 0.0;
        $advances = [];                 // per-advance rows for the loan schedule
        $equityByHolder = [];           // non-lender capital, net of drawings
        $now = Carbon::now();

        $rows = OrderCost::where('tenant_id', $tid)
            ->whereNotIn('status', ['void', 'rejected'])
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
            ->get(['stage', 'usd_amount', 'note', 'holder', 'category', 'created_at']);

        foreach ($rows as $c) {
            $s = (string) $c->stage;
            $usd = (float) $c->usd_amount;
            $note = (string) $c->note;
            $holder = trim((string) $c->holder);
            $isConv = in_array((string) $c->category, ['Conversion', 'Transfer'], true);

            // total cash on hand = signed usd over every real cash row (incl. conversions, which net to 0)
            $cashUsd += (in_array($s, self::IN_STAGES, true) ? 1 : -1) * $usd;
            if ($isConv) continue;      // FX/custody — not P&L, not a loan/equity event

            $isCapital = ($s === 'funding') || (in_array($s, self::IN_STAGES, true) && $isCapitalNote($note));
            $lender = $isLender($holder) || $isLender($note);

            // P&L buckets (same rule as /papi/cash-pnl)
            if ($isCapital) $b['capital'] += $usd;
            elseif ($s === 'customer_payment') $b['revenue'] += $usd;
            elseif ($s === 'cash_in') $b['other_in'] += $usd;
            elseif ($s === 'purchase') $b['purchase'] += $usd;
            elseif ($s === 'supplier_payment') $b['supplier'] += $usd;
            elseif ($s === 'expense') $b['opex'] += $usd;
            elseif ($s === 'drawing') $b['drawings'] += $usd;
            elseif ($s === 'cash_out') $b['other_out'] += $usd;

            // LOAN: lender money in = an advance (grow at the monthly rate to today)
            if ($isCapital && $lender) {
                $d = max(0, (int) $now->diffInDays(Carbon::parse($c->created_at), true));
                $grown = $usd * pow(1 + $rate, $d / 30.0);
                $loanPrincipal += $usd; $loanInterest += $grown - $usd;
                $advances[] = ['date' => Carbon::parse($c->created_at)->format('Y-m-d'), 'p' => $usd,
                    'days' => $d, 'int' => $grown - $usd];
            }
            // LOAN: lender money out, noted as a loan/interest movement = a repayment
            if (! in_array($s, self::IN_STAGES, true) && $lender
                && preg_match('/loan|interest|repay|repaid|return/i', $note)) {
                if (preg_match('/interest/i', $note)) $intPaid += $usd; else $prinPaid += $usd;
            }

            // EQUITY: real owners' capital (non-lenders), net of their drawings
            if ($isCapital && ! $lender && $holder !== '') {
                $equityByHolder[$holder] = ($equityByHolder[$holder] ?? 0) + $usd;
            } elseif ($s === 'drawing' && ! $lender && $holder !== '') {
                $equityByHolder[$holder] = ($equityByHolder[$holder] ?? 0) - $usd;
            }
        }

        // ---- receivable + payable (USD) ----------------------------------
        $fx = Fx::forTenant($t);
        $receivable = 0.0;
        foreach (Invoice::where('tenant_id', $tid)->where('status', '!=', 'void')->where('balance', '>', 0)->get() as $inv) {
            $cur = strtoupper((string) \App\Services\Money\SalesBaseCurrency::txnCurrency($inv, $t));
            $r = $cur === 'USD' ? 1.0 : ((float) $fx->ratePerUsd($cur) ?: 1.0);
            $receivable += (float) $inv->balance / ($r ?: 1.0);
        }
        $payable = (float) PayablesCalculator::totalUsd($tid);

        // ---- derive the statements ---------------------------------------
        $revenue = round($b['revenue'], 2);
        $cogs = round($b['purchase'] + $b['supplier'], 2);
        $grossProfit = round($revenue - $cogs, 2);
        $opex = round($b['opex'], 2);
        $otherOut = round($b['other_out'], 2);
        $otherIn = round($b['other_in'], 2);
        $operatingProfit = round($grossProfit - $opex - $otherOut + $otherIn, 2);
        $financeCost = round($loanInterest - $intPaid, 2);       // interest accrued but not yet paid
        $netProfit = round($operatingProfit - $financeCost, 2);

        $loanOwed = round($loanPrincipal + $loanInterest - $intPaid - $prinPaid, 2);
        $cash = round($cashUsd, 2);
        $equityContributed = round(array_sum($equityByHolder), 2);
        $totalAssets = round($cash + $stockUsd + $receivable, 2);
        $totalLiab = round($payable + $loanOwed, 2);
        $totalEquity = round($totalAssets - $totalLiab, 2);
        $retained = round($totalEquity - $equityContributed, 2);
        $balances = abs($totalAssets - ($totalLiab + $totalEquity)) < 0.05;

        // ---- render: KPI tiles + one grouped statement table -------------
        $money = fn ($v) => '$' . number_format((float) $v, 2);
        $row = fn ($stmt, $line, $val, $strong = false) => [
            'statement' => ['value' => $stmt],
            'line' => ['value' => ($strong ? '➤ ' : '') . $line],
            'amount' => ['value' => $money($val), 'sort' => (float) $val, 'tone' => $strong ? 'strong' : 'default'],
        ];

        $table = [];
        // P&L
        $table[] = $row('Profit & Loss', 'Sales revenue (cash received)', $revenue);
        $table[] = $row('Profit & Loss', 'Cost of goods (cash paid)', -$cogs);
        $table[] = $row('Profit & Loss', 'Gross profit  (' . ($revenue > 0 ? round($grossProfit / $revenue * 100, 1) : 0) . '%)', $grossProfit, true);
        $table[] = $row('Profit & Loss', 'Operating expenses', -$opex);
        if ($otherOut) $table[] = $row('Profit & Loss', 'Other cash paid (e.g. transport)', -$otherOut);
        if ($otherIn) $table[] = $row('Profit & Loss', 'Other cash received', $otherIn);
        $table[] = $row('Profit & Loss', 'Operating profit', $operatingProfit, true);
        $table[] = $row('Profit & Loss', 'Finance cost — loan interest', -$financeCost);
        $table[] = $row('Profit & Loss', 'NET PROFIT', $netProfit, true);
        // Balance sheet
        $table[] = $row('Balance Sheet', 'Cash on hand (all currencies)', $cash);
        $table[] = $row('Balance Sheet', 'Inventory / stock *', $stockUsd);
        $table[] = $row('Balance Sheet', 'Receivables (customers owe)', $receivable);
        $table[] = $row('Balance Sheet', 'TOTAL ASSETS', $totalAssets, true);
        $table[] = $row('Balance Sheet', 'Supplier payables', $payable);
        $table[] = $row('Balance Sheet', 'Loan — ' . (implode(', ', $holders) ?: 'director'), $loanOwed);
        $table[] = $row('Balance Sheet', 'TOTAL LIABILITIES', $totalLiab, true);
        $table[] = $row('Balance Sheet', 'Owners’ capital', $equityContributed);
        $table[] = $row('Balance Sheet', 'Retained earnings', $retained);
        $table[] = $row('Balance Sheet', 'TOTAL EQUITY', $totalEquity, true);
        $table[] = $row('Balance Sheet', 'Assets = Liabilities + Equity ' . ($balances ? '✓' : '✗ check'), $totalAssets, true);
        // Equity detail
        arsort($equityByHolder);
        foreach ($equityByHolder as $name => $v) $table[] = $row('Owners’ equity', $name . ' — capital', round($v, 2));
        if (! $equityByHolder) $table[] = $row('Owners’ equity', '(no non-lender capital found)', 0);
        // Loan schedule
        usort($advances, fn ($x, $y) => strcmp($x['date'], $y['date']));
        foreach ($advances as $a) $table[] = $row('Loan schedule', $a['date'] . ' · advance (' . $a['days'] . 'd, +' . $money($a['int']) . ' int)', $a['p']);
        $table[] = $row('Loan schedule', 'Principal advanced', round($loanPrincipal, 2));
        $table[] = $row('Loan schedule', 'Interest accrued to date', round($loanInterest, 2));
        if ($intPaid) $table[] = $row('Loan schedule', 'Interest paid', -round($intPaid, 2));
        if ($prinPaid) $table[] = $row('Loan schedule', 'Principal repaid', -round($prinPaid, 2));
        $table[] = $row('Loan schedule', 'BALANCE OWED', $loanOwed, true);
        $table[] = $row('Loan schedule', 'Interest next month ≈ (pay to stop compounding)', round($loanOwed * $rate, 2));

        $configAction = ['key' => 'save_config', 'label' => 'Set stock value / lender', 'method' => 'POST',
            'endpoint' => '/api/v1/workspace/books/_/save_config', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['stock_usd' => 'number', 'loan_holders' => 'text', 'loan_rate' => 'number'],
            'labels' => [
                'stock_usd' => 'Inventory value in USD (until stock goes live)',
                'loan_holders' => 'Lender name(s), comma-separated',
                'loan_rate' => 'Loan interest per month (e.g. 0.03 = 3%)',
            ],
            'prefill' => ['stock_usd' => $stockUsd, 'loan_holders' => implode(', ', $holders), 'loan_rate' => $rate]];

        return (new WorkspacePayload)
            ->object(['type' => 'process:books', 'title' => 'Books'])
            ->headline($money($netProfit), 'net profit · after loan interest', $netProfit >= 0 ? 'ok' : 'danger')
            ->financials([
                new Financial('net_profit', 'Net profit', $netProfit, 'USD', $netProfit >= 0 ? 'ok' : 'danger'),
                new Financial('cash', 'Cash (all currencies)', $cash, 'USD', 'default'),
                new Financial('loan', 'Loan owed', $loanOwed, 'USD', 'warn'),
                new Financial('equity', 'Owners’ equity', $totalEquity, 'USD', 'default'),
            ])
            ->table(
                [
                    ['key' => 'statement', 'label' => 'Statement', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'line', 'label' => 'Line'],
                    ['key' => 'amount', 'label' => 'Amount (USD)', 'type' => 'num', 'align' => 'right', 'sortable' => true],
                ],
                $table,
                [['key' => 'statement', 'label' => 'Statement']],
                ['pageSize' => 60, 'title' => 'Financial statements',
                    'sub' => 'live from the cashbook · balance sheet ' . ($balances ? 'balances ✓' : 'needs a check'),
                    'empty' => 'No cash activity yet.']
            )
            ->actions([
                $configAction,
                ['key' => 'export_pdf', 'label' => 'Cashbook PDF', 'href' => '/api/v1/doc/cashbook-export?format=pdf', 'actor' => 'human', 'tone' => 'default'],
            ])
            ->meta(['capability' => 'books', 'ttl' => 45, 'permissions' => ['menu:finance'],
                'note' => '* Inventory is the manual value you set (Set stock value) until purchases are logged against products; every other line is live. Cash spans USD+UGX+SSP valued in USD, so it is higher than the Finance tile (USD wallet only).']);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $p = $this->project($ctx)->toArray();
        $out = [];
        foreach (($p['financials'] ?? []) as $f) $out[$f['key']] = $f['value'];
        return $out;
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action !== 'save_config') return ['ok' => false, 'error' => 'unknown_action'];
        $u = $ctx->user;
        if (! (method_exists($u, 'isOwnerLike') && $u->isOwnerLike())) return ['ok' => false, 'error' => 'forbidden'];
        $t = $ctx->tenant;
        if (array_key_exists('stock_usd', $input)) $t->putSetting('books.stock_usd', round((float) $input['stock_usd'], 2));
        if (array_key_exists('loan_holders', $input)) $t->putSetting('books.loan_holders', trim((string) $input['loan_holders']));
        if (array_key_exists('loan_rate', $input)) {
            $r = (float) $input['loan_rate'];
            if ($r > 0) $t->putSetting('books.loan_rate', $r);
        }
        return ['ok' => true, 'saved' => true];
    }
}
