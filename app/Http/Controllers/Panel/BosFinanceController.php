<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\OrderCost;
use App\Services\Money\CashService;
use Illuminate\Http\Request;

/**
 * Modern Finance hub. GET /papi/bos-finance — the money summary the Finance screen leads with, all REAL:
 * cash-on-hand per currency (the CashService SSOT), money to collect, open invoices, payments this month,
 * and a cash-basis month P&L. No trend badge (we have no cash-history snapshot, so we don't fake one).
 */
class BosFinanceController extends Controller
{
    public function show(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $tid = (int) $t->id;

        try {
            // Cash on hand — the single source of truth, per currency (never mixed into one number).
            $bal = CashService::forTenant($t)->balances();
            $usd = round((float) ($bal['USD'] ?? 0), 2);
            $others = [];
            foreach (['UGX', 'SSP'] as $ccy) {
                if (! empty($bal[$ccy])) $others[] = ['ccy' => $ccy, 'amount' => round((float) $bal[$ccy])];
            }

            // Money to collect + open-invoice count.
            $toCollect = (float) Invoice::where('tenant_id', $tid)
                ->whereIn('status', ['issued', 'part_paid'])->sum('balance');
            $invCount = (int) Invoice::where('tenant_id', $tid)
                ->whereIn('status', ['issued', 'part_paid'])->count();

            // Payments received this month (USD-valued).
            $payMonth = (float) CustomerPayment::where('tenant_id', $tid)
                ->where('created_at', '>=', now()->startOfMonth())->sum('usd_amount');

            // Cash-basis month P&L: revenue (customer_payment) − COGS (purchase + supplier), from order_costs.
            $rev = 0.0; $cogs = 0.0;
            OrderCost::where('tenant_id', $tid)->whereNotIn('status', ['void', 'rejected'])
                ->where('created_at', '>=', now()->startOfMonth())
                ->get(['stage', 'usd_amount'])
                ->each(function ($c) use (&$rev, &$cogs) {
                    $u = (float) $c->usd_amount;
                    if ($c->stage === 'customer_payment') $rev += $u;
                    elseif (in_array($c->stage, ['purchase', 'supplier'], true)) $cogs += $u;
                });
            $pnl = round($rev - $cogs, 2);

            return response()->json([
                'ok'          => true,
                'currency'    => 'USD',
                'cash'        => ['usd' => $usd, 'others' => $others],
                'to_collect'  => round($toCollect, 2),
                'invoices'    => $invCount,
                'payments'    => round($payMonth, 2),
                'pnl_month'   => $pnl,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('bos-finance failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'unavailable'], 200);
        }
    }
}
