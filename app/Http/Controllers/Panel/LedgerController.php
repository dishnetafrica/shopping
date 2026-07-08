<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 read-only financial statements, derived from the double-entry ledger.
 * All figures are functional currency (USD). Owner/manager only.
 */
class LedgerController extends Controller
{
    private function ok(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return null;
        if (! ($u->isOwnerLike() || in_array((string) $u->role, ['manager','accounts'], true))) return null;
        return $u;
    }

    public function page(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        if (! ($u->isOwnerLike() || in_array((string) $u->role, ['manager','accounts'], true))) abort(404);
        $path = resource_path('panel/ledger.html');
        if (! is_file($path)) abort(500, 'Ledger asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    /** POST /papi/ledger/rebuild — regenerate the derived ledger from order_costs. */
    public function rebuild(Request $request, LedgerService $svc)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $r = $svc->backfillOrderCosts((int) $u->tenant_id);
        return response()->json(['ok' => true] + $r);
    }

    /** account balances (debit-credit) as code => ['name','type','normal','balance'] */
    private function balances(int $tenantId): array
    {
        $accts = LedgerAccount::where('tenant_id', $tenantId)->get()->keyBy('id');
        $rows = DB::table('ledger_lines')
            ->join('ledger_journals', 'ledger_lines.journal_id', '=', 'ledger_journals.id')
            ->where('ledger_journals.tenant_id', $tenantId)
            ->where('ledger_journals.status', 'posted')
            ->groupBy('ledger_lines.account_id')
            ->select('ledger_lines.account_id',
                DB::raw('SUM(ledger_lines.debit_usd) as dr'),
                DB::raw('SUM(ledger_lines.credit_usd) as cr'))
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $a = $accts[$r->account_id] ?? null;
            if (! $a) continue;
            $bal = round((float) $r->dr - (float) $r->cr, 2); // debit-positive
            $out[$a->code] = [
                'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'normal' => $a->normal,
                'dr' => round((float) $r->dr, 2), 'cr' => round((float) $r->cr, 2), 'balance' => $bal,
            ];
        }
        return $out;
    }

    /** GET /papi/ledger/trial-balance */
    public function trialBalance(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $b = $this->balances((int) $u->tenant_id);
        ksort($b);
        $totalDr = 0.0; $totalCr = 0.0;
        $rows = [];
        foreach ($b as $x) {
            // present each account on its natural side
            $dr = $x['balance'] > 0 ? $x['balance'] : 0.0;
            $cr = $x['balance'] < 0 ? -$x['balance'] : 0.0;
            $totalDr += $dr; $totalCr += $cr;
            $rows[] = ['code' => $x['code'], 'name' => $x['name'], 'type' => $x['type'], 'debit' => round($dr, 2), 'credit' => round($cr, 2)];
        }
        return response()->json([
            'ok' => true, 'rows' => $rows,
            'total_debit' => round($totalDr, 2), 'total_credit' => round($totalCr, 2),
            'balanced' => abs($totalDr - $totalCr) < 0.01,
        ]);
    }

    /** GET /papi/ledger/pnl — income statement */
    public function pnl(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $b = $this->balances((int) $u->tenant_id);
        $income = []; $expense = []; $incomeTotal = 0.0; $expenseTotal = 0.0;
        foreach ($b as $x) {
            if ($x['type'] === 'income') { $amt = -$x['balance']; $income[] = ['name' => $x['name'], 'amount' => round($amt, 2)]; $incomeTotal += $amt; }
            if ($x['type'] === 'expense') { $amt = $x['balance']; $expense[] = ['name' => $x['name'], 'amount' => round($amt, 2)]; $expenseTotal += $amt; }
        }
        return response()->json([
            'ok' => true, 'income' => $income, 'expense' => $expense,
            'income_total' => round($incomeTotal, 2), 'expense_total' => round($expenseTotal, 2),
            'net_profit' => round($incomeTotal - $expenseTotal, 2),
        ]);
    }

    /** GET /papi/ledger/balance-sheet */
    public function balanceSheet(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $b = $this->balances((int) $u->tenant_id);
        $assets = []; $liabilities = []; $equity = [];
        $assetTotal = 0.0; $liabTotal = 0.0; $eqTotal = 0.0;
        $incomeTotal = 0.0; $expenseTotal = 0.0;
        foreach ($b as $x) {
            if ($x['type'] === 'asset')     { $assets[] = ['name' => $x['name'], 'amount' => round($x['balance'], 2)]; $assetTotal += $x['balance']; }
            if ($x['type'] === 'liability') { $amt = -$x['balance']; $liabilities[] = ['name' => $x['name'], 'amount' => round($amt, 2)]; $liabTotal += $amt; }
            if ($x['type'] === 'equity')    { $amt = -$x['balance']; $equity[] = ['name' => $x['name'], 'amount' => round($amt, 2)]; $eqTotal += $amt; }
            if ($x['type'] === 'income')    { $incomeTotal += -$x['balance']; }
            if ($x['type'] === 'expense')   { $expenseTotal += $x['balance']; }
        }
        $currentEarnings = round($incomeTotal - $expenseTotal, 2);   // not yet closed to equity
        $equity[] = ['name' => 'Current-year earnings (P&L)', 'amount' => $currentEarnings];
        $eqTotal += $currentEarnings;
        return response()->json([
            'ok' => true,
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'asset_total' => round($assetTotal, 2), 'liability_total' => round($liabTotal, 2), 'equity_total' => round($eqTotal, 2),
            'liab_plus_equity' => round($liabTotal + $eqTotal, 2),
            'balanced' => abs($assetTotal - ($liabTotal + $eqTotal)) < 0.01,
            'imbalance' => round($assetTotal - ($liabTotal + $eqTotal), 2),
        ]);
    }

    /** GET /papi/ledger/gl?code=1010 — general ledger for one account */
    public function generalLedger(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $code = trim((string) $request->query('code', ''));
        $acct = LedgerAccount::where('tenant_id', $u->tenant_id)->where('code', $code)->first();
        if (! $acct) return response()->json(['ok' => false, 'error' => 'no_account'], 404);
        $lines = DB::table('ledger_lines')
            ->join('ledger_journals', 'ledger_lines.journal_id', '=', 'ledger_journals.id')
            ->where('ledger_journals.tenant_id', $u->tenant_id)
            ->where('ledger_journals.status', 'posted')
            ->where('ledger_lines.account_id', $acct->id)
            ->orderBy('ledger_journals.posted_at')->orderBy('ledger_journals.id')
            ->select('ledger_journals.ref', 'ledger_journals.memo', 'ledger_journals.posted_at',
                'ledger_lines.debit_usd', 'ledger_lines.credit_usd', 'ledger_lines.currency', 'ledger_lines.native_amount')
            ->limit(1000)->get();
        $run = 0.0; $out = [];
        foreach ($lines as $l) {
            $run += (float) $l->debit_usd - (float) $l->credit_usd;
            $out[] = [
                'ref' => $l->ref, 'memo' => $l->memo,
                'when' => $l->posted_at,
                'debit' => round((float) $l->debit_usd, 2), 'credit' => round((float) $l->credit_usd, 2),
                'balance' => round($run, 2),
                'currency' => $l->currency, 'native' => $l->native_amount !== null ? (float) $l->native_amount : null,
            ];
        }
        return response()->json(['ok' => true, 'account' => ['code' => $acct->code, 'name' => $acct->name, 'type' => $acct->type], 'lines' => $out]);
    }
}
