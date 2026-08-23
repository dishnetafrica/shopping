<?php

namespace App\Services\Sales;

/**
 * Phase 3B — pure planner for one compensating COGS-FX correction.
 *
 * Given what the kernel ACTUALLY posted for a sale (read from the cloudbss_events log — NOT the
 * reconstruction the Phase-3A audit uses) and the correct base-currency cost, it decides whether a
 * compensating entry is owed and, if so, its exact amount. It writes nothing; the command turns a
 * REMEDIABLE plan into a 'CogsCorrected' event (CogsCorrectionPolicy → Dr 1200 Inventory / Cr 5000 COGS).
 *
 * The correction amount is  posted_cost − correct_cost  in MINOR units of the base/posting currency —
 * derived from the real posting, so cost_price drift since the sale cannot skew it.
 *
 * Statuses:
 *   NOT_POSTED              — no sale posting found in the kernel for this order (nothing to correct).
 *   ALREADY_REMEDIATED      — a correction for this order already exists (idempotency: never double-post).
 *   REQUIRES_RECONCILIATION — the historical FX rate is unavailable, so the correct cost can't be
 *                             established (never invented). Excluded from auto-remediation.
 *   NOT_AFFECTED            — posted == correct (or posted ≤ correct): no overstatement to reverse.
 *   AUTOMATICALLY_REMEDIABLE — a real positive overstatement with FX available → compensating entry owed.
 */
final class CogsRemediation
{
    public const VERSION = 'cogs-remediation-v1';

    public const NOT_POSTED  = 'NOT_POSTED';
    public const ALREADY     = 'ALREADY_REMEDIATED';
    public const REQUIRES    = 'REQUIRES_RECONCILIATION';
    public const NOT_AFFECTED = 'NOT_AFFECTED';
    public const REMEDIABLE   = 'AUTOMATICALLY_REMEDIABLE';

    /**
     * @param array{
     *   order_id:int, order_no:string, base_currency:string, cost_currency:string, date:?string,
     *   posted_cost_minor:?int,      // ACTUAL COGS the kernel booked (minor, base) — null if never posted
     *   posted_revenue_minor:?int,   // ACTUAL revenue the kernel booked (minor, base), for the audit row
     *   cogs_native:float,           // cost_price×qty in cost_currency (to compute the correct cost)
     *   already_remediated:bool      // a CogsCorrected event already exists for this order
     * } $sale
     * @param callable $toBaseMinor fn(float $amount, string $from): ?int  → correct base amount in MINOR
     *                               units, or null when the FX rate for that currency/date is unavailable.
     * @return array<string,mixed>
     */
    public static function plan(array $sale, callable $toBaseMinor): array
    {
        $orderId = (int) $sale['order_id'];
        $base    = strtoupper((string) $sale['base_currency']);
        $costCur = strtoupper((string) $sale['cost_currency']);
        $posted  = $sale['posted_cost_minor'];              // may be null
        $native  = round((float) $sale['cogs_native'], 4);

        $correct = ($native > 0.0001) ? $toBaseMinor($native, $costCur) : 0;   // null => FX missing
        $over    = ($posted !== null && $correct !== null) ? ((int) $posted - (int) $correct) : null;

        if ($posted === null) {
            $status = self::NOT_POSTED;
        } elseif (! empty($sale['already_remediated'])) {
            $status = self::ALREADY;
        } elseif ($correct === null) {
            $status = self::REQUIRES;
        } elseif ($over <= 0) {
            $status = self::NOT_AFFECTED;
        } else {
            $status = self::REMEDIABLE;
        }

        $factor = ($base === 'UGX') ? 1 : 100;              // minor→major for display only

        return [
            'order_id'             => $orderId,
            'order_no'             => (string) $sale['order_no'],
            'date'                 => (string) ($sale['date'] ?? ''),
            'base_currency'        => $base,
            'cost_currency'        => $costCur,
            'posted_cost_minor'    => $posted,
            'correct_cost_minor'   => $correct,
            'overstatement_minor'  => $over,
            'posted_cost'          => $posted !== null ? round($posted / $factor, 2) : null,
            'correct_cost'         => $correct !== null ? round($correct / $factor, 2) : null,
            'overstatement'        => $over !== null ? round($over / $factor, 2) : null,
            'posted_revenue'       => isset($sale['posted_revenue_minor']) && $sale['posted_revenue_minor'] !== null
                                        ? round(((int) $sale['posted_revenue_minor']) / $factor, 2) : null,
            // The compensating aggregate id IS the idempotency key: LedgerPoster no-ops when a posting
            // for this id already exists, and the command's ALREADY_REMEDIATED pre-check skips it too.
            'correction_aggregate' => 'cogsfix_' . $orderId,
            'source_sale_aggregate' => (string) $orderId,
            'version'              => self::VERSION,
            'status'               => $status,
            'remediable'           => $status === self::REMEDIABLE,
        ];
    }
}
