<?php

namespace App\Services\Finance;

use App\Models\OrderCost;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Money\Fx;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for SUPPLIER PAYABLES in USD.
 *
 * Why this exists: a credit bill is a charge; a "supplier payment" reduces it. But payments are
 * stored as SEPARATE order_cost rows (stage = supplier_payment) — they do NOT update paid_native on
 * the bill (paid_native stays 0 for a credit bill paid via a separate transaction). So any calc that
 * reads only `native_amount - paid_native` OVERSTATES what is owed by the full value of every payment
 * ever made. That was the dashboard/working-capital "you owe" bug.
 *
 * This class subtracts the supplier_payment rows — exactly like SupplierController::owedBySupplierUsd —
 * and, because payments are not linked to specific bills, applies each supplier's payments OLDEST-FIRST
 * (FIFO) when ageing what remains. Overpaid suppliers floor at 0 (an overpayment is a receivable, not a
 * negative payable). Use this everywhere payables are totalled so every screen shows the same number.
 */
final class PayablesCalculator
{
    /**
     * @return array{0: array<int, array<int, array{usd: float, at: mixed}>>, 1: array<int, float>}
     *         [ bills-by-supplier (oldest first), total-payments-by-supplier ] in USD.
     */
    private static function gather(int $tid): array
    {
        $bills = [];
        foreach (OrderCost::where('tenant_id', $tid)->where('pay_status', 'credit')
                    ->whereNotIn('status', ['void', 'rejected'])->orderBy('created_at')
                    ->get(['supplier_id', 'native_amount', 'rate_to_usd', 'usd_amount', 'created_at']) as $c) {
            // Use the stored usd_amount (same field SupplierController::owedBySupplierUsd sums) so every
            // screen agrees to the penny; fall back to native/rate only if usd_amount is missing.
            $usd = (float) ($c->usd_amount ?: ((float) $c->native_amount / ((float) $c->rate_to_usd ?: 1.0)));
            if ($usd <= 0.005) continue;
            $bills[(int) ($c->supplier_id ?? 0)][] = ['usd' => $usd, 'at' => $c->created_at];
        }

        $paid = [];
        foreach (OrderCost::where('tenant_id', $tid)->where('stage', 'supplier_payment')
                    ->whereNotIn('status', ['void', 'rejected'])
                    ->get(['supplier_id', 'native_amount', 'rate_to_usd', 'usd_amount']) as $p) {
            $rate = (float) $p->rate_to_usd ?: 1.0;
            $usd  = (float) ($p->usd_amount ?: ((float) $p->native_amount / $rate));
            $sid  = (int) ($p->supplier_id ?? 0);
            $paid[$sid] = ($paid[$sid] ?? 0.0) + $usd;
        }

        return [$bills, $paid];
    }

    /**
     * Net payable PER SUPPLIER in USD: credit bills − supplier payments (+ opening balance), using the
     * stored usd_amount (same field the supplier detail page sums). Raw net — NOT floored — so an
     * overpaid supplier reads negative, exactly like the detail page. This is the single source of
     * truth shared by the supplier detail (SupplierController::owedBySupplierUsd) AND the supplier list,
     * so the two screens can never disagree again.
     *
     * @return array<int,float> supplier_id => net owed USD
     */
    public static function bySupplierUsd(int $tid): array
    {
        $out = [];

        foreach (OrderCost::where('tenant_id', $tid)->whereNotNull('supplier_id')
                     ->where('pay_status', 'credit')->whereNotIn('status', ['rejected', 'void'])
                     ->select('supplier_id', DB::raw('SUM(usd_amount) as usd'))->groupBy('supplier_id')->get() as $c) {
            $out[(int) $c->supplier_id] = ($out[(int) $c->supplier_id] ?? 0.0) + (float) $c->usd;
        }

        foreach (OrderCost::where('tenant_id', $tid)->whereNotNull('supplier_id')
                     ->where('stage', 'supplier_payment')->whereNotIn('status', ['rejected', 'void'])
                     ->select('supplier_id', DB::raw('SUM(usd_amount) as usd'))->groupBy('supplier_id')->get() as $p) {
            $out[(int) $p->supplier_id] = ($out[(int) $p->supplier_id] ?? 0.0) - (float) $p->usd;
        }

        $openings = Supplier::where('tenant_id', $tid)->whereNotNull('opening_balance')
            ->where('opening_balance', '>', 0)->get(['id', 'opening_balance', 'opening_currency']);
        if ($openings->isNotEmpty()) {
            $fx = Fx::forTenant(Tenant::find($tid));
            foreach ($openings as $s) {
                $rate = $fx->ratePerUsd(strtoupper((string) ($s->opening_currency ?: 'USD')));
                if ($rate <= 0) $rate = 1;
                $out[(int) $s->id] = ($out[(int) $s->id] ?? 0.0) + ((float) $s->opening_balance / $rate);
            }
        }

        return $out;
    }

    /** Total net payable in USD (never negative). */
    public static function totalUsd(int $tid): float
    {
        [$bills, $paid] = self::gather($tid);
        $total = 0.0;
        foreach ($bills as $sid => $rows) {
            $credit = $paid[$sid] ?? 0.0;
            foreach ($rows as $r) {
                $rem = $r['usd'];
                if ($credit > 0) { $take = min($credit, $rem); $rem -= $take; $credit -= $take; }
                if ($rem > 0.005) $total += $rem;
            }
        }
        return round($total, 2);
    }

    /**
     * Net payable aged into buckets, USD. Payments are applied oldest-first, then whatever bill amount
     * remains is aged by that bill's date.
     *
     * @param callable $bucket fn($date): string  — maps a bill date to a bucket label.
     * @return array<string, float>
     */
    public static function agedUsd(int $tid, callable $bucket): array
    {
        [$bills, $paid] = self::gather($tid);
        $age = [];
        foreach ($bills as $sid => $rows) {
            $credit = $paid[$sid] ?? 0.0;
            foreach ($rows as $r) {
                $rem = $r['usd'];
                if ($credit > 0) { $take = min($credit, $rem); $rem -= $take; $credit -= $take; }
                if ($rem <= 0.005) continue;
                $b = (string) $bucket($r['at']);
                $age[$b] = ($age[$b] ?? 0.0) + $rem;
            }
        }
        foreach ($age as $k => $v) $age[$k] = round($v, 2);
        return $age;
    }
}
