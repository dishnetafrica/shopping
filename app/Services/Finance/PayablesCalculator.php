<?php

namespace App\Services\Finance;

use App\Models\OrderCost;

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
