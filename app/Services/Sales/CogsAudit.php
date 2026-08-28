<?php

namespace App\Services\Sales;

/**
 * Read-only reconciliation for the historical multi-currency COGS bug (Phase 3A).
 *
 * The old OrderLedgerService::postSale booked, tagged as the base currency and WITHOUT conversion:
 *   revenue = order.total   (actually in the order's sell currency)
 *   cost    = cost_price×qty (actually in UGX, OrderCosting::COST_CURRENCY)
 * So the posted figures are a DETERMINISTIC function of the order data — this reconstructs them and
 * compares against the correct base-currency figures (OrderCosting), per order. It writes nothing.
 *
 * Statuses:
 *   NOT_AFFECTED              — no cost recorded, or no currency mismatch (posted == correct).
 *   AUTOMATICALLY_REMEDIABLE  — a real overstatement AND the FX rate is available for the order date.
 *   REQUIRES_RECONCILIATION   — affected but the historical FX rate could not be established (never invented).
 */
final class CogsAudit
{
    /** Remediation version tag — stamped on compensating entries in Phase 3B; also for idempotency. */
    public const VERSION = 'cogs-remediation-v1';

    public const NOT_AFFECTED = 'NOT_AFFECTED';
    public const REMEDIABLE   = 'AUTOMATICALLY_REMEDIABLE';
    public const REQUIRES     = 'REQUIRES_RECONCILIATION';

    /**
     * Assess one order. Pure given $toBase.
     *
     * @param array{id:int,order_no:string,date:?string,delivered:?string,sell_currency:string,
     *              base_currency:string,cost_currency:string,sales:float,cogs_native:float} $o
     * @param callable $toBase fn(float $amount, string $fromCurrency): ?float  → base amount, or null if the
     *                          rate for that currency/date is unavailable (do NOT invent one)
     * @return array<string,mixed>
     */
    public static function assess(array $o, callable $toBase): array
    {
        $base    = strtoupper((string) $o['base_currency']);
        $sell    = strtoupper((string) ($o['sell_currency'] ?: $base));
        $costCur = strtoupper((string) $o['cost_currency']);
        $sales   = round((float) $o['sales'], 2);
        $cogsNat = round((float) $o['cogs_native'], 2);
        $hasCost = $cogsNat > 0.0001;

        // What the buggy code posted (both booked as base, unconverted).
        $postedRev  = $sales;
        $postedCogs = $cogsNat;

        // The correct base-currency figures.
        $correctRev  = $toBase($sales, $sell);
        $correctCogs = $hasCost ? $toBase($cogsNat, $costCur) : 0.0;
        $fxAvailable = ($correctRev !== null) && ($correctCogs !== null);

        if (! $hasCost) {
            $status = self::NOT_AFFECTED;
        } elseif (! $fxAvailable) {
            $status = self::REQUIRES;
        } else {
            $mismatch = abs($postedCogs - $correctCogs) > 0.01 || abs($postedRev - $correctRev) > 0.01;
            $status = $mismatch ? self::REMEDIABLE : self::NOT_AFFECTED;
        }

        $cogsOver = $correctCogs !== null ? round($postedCogs - $correctCogs, 2) : null;
        $profit   = $fxAvailable ? round(($correctRev - $correctCogs), 2) : null;
        $pct      = ($profit !== null && $correctRev > 0.0001) ? round($profit / $correctRev * 100, 1) : null;

        return [
            'order_id'            => (int) $o['id'],
            'order_no'            => (string) $o['order_no'],
            'date'                => (string) ($o['date'] ?? ''),
            'delivered'           => (string) ($o['delivered'] ?? ''),
            'sell_currency'       => $sell,
            'sales'               => $sales,
            'posted_revenue_base' => $postedRev,
            'posted_cogs_base'    => $postedCogs,
            'cost_currency'       => $costCur,
            'cost_native'         => $cogsNat,
            'correct_cogs_base'   => $correctCogs,
            'incorrect_cogs_base' => $postedCogs,
            'cogs_overstatement'  => $cogsOver,
            'correct_revenue_base' => $correctRev,
            'correct_gross_profit' => $profit,
            'correct_gross_margin_pct' => $pct,
            'journal_ref'         => 'sale:' . (int) $o['id'],   // SalesLedger keys the posting on the order id
            'requires_compensating' => $status === self::REMEDIABLE,
            'fx_available'        => $fxAvailable,
            'status'             => $status,
        ];
    }
}
