<?php

namespace App\Services\Sales;

/**
 * Read-only reconciliation for the historical multi-currency REVENUE bug (sibling of the COGS one).
 *
 * The old OrderLedgerService::postSale booked `revenue = order.total` (net of VAT) tagged as the base
 * currency but WITHOUT converting it from the order's sell currency. For a sell_currency = UGX order,
 * a shilling net amount was booked as if it were USD — inflating Sales Revenue (4000), and via the
 * UgandaVatSalesPolicy's derived legs also VAT Output (2200) and Accounts Receivable (1100), by the
 * FX factor. This reconstructs the posted net from the ACTUAL kernel posting and compares it to the
 * correctly-converted net, deriving the per-leg overstatement. It writes nothing.
 *
 * The compensating entry (Phase-4 remediation, NOT here) reverses only the overstatement:
 *   Dr 4000 Sales Revenue (net_over)  ·  Dr 2200 VAT Output (vat_over)  ·  Cr 1100 AR (gross_over)
 * balanced because gross_over = net_over + vat_over.
 *
 * Statuses mirror the COGS audit:
 *   NOT_POSTED / NOT_AFFECTED / AUTOMATICALLY_REMEDIABLE / REQUIRES_RECONCILIATION.
 */
final class SaleFxAudit
{
    public const VERSION = 'sale-fx-remediation-v1';

    public const NOT_POSTED   = 'NOT_POSTED';
    public const NOT_AFFECTED = 'NOT_AFFECTED';
    public const REMEDIABLE    = 'AUTOMATICALLY_REMEDIABLE';
    public const REQUIRES      = 'REQUIRES_RECONCILIATION';

    /**
     * @param array{
     *   order_id:int, order_no:string, date:?string, base_currency:string, sell_currency:string,
     *   base_factor:int,             // base currency subunit (USD=100, UGX=1) — minor↔major
     *   posted_net_minor:?int,       // ACTUAL net revenue the kernel booked (minor, base) — null if never posted
     *   vat_bp:int                   // output VAT basis points the policy applied (e.g. 1800 = 18%)
     * } $o
     * @param callable $toBaseMinor fn(float $amountMajor, string $from): ?int — correct base amount in MINOR
     *                               units, or null when the FX rate is unavailable (never invented).
     * @return array<string,mixed>
     */
    public static function assess(array $o, callable $toBaseMinor): array
    {
        $orderId = (int) $o['order_id'];
        $base    = strtoupper((string) $o['base_currency']);
        $sell    = strtoupper((string) ($o['sell_currency'] ?: $base));
        $factor  = max(1, (int) $o['base_factor']);
        $vatBp   = (int) $o['vat_bp'];
        $posted  = $o['posted_net_minor'];   // may be null

        // The native net amount that was booked: the buggy code did minor = round(native * base_factor)
        // without converting, so native = posted_minor / base_factor, expressed in the SELL currency.
        $nativeNet   = $posted !== null ? $posted / $factor : null;
        $correctNet  = $posted !== null ? $toBaseMinor((float) $nativeNet, $sell) : null;   // null => FX missing

        $netOver = ($posted !== null && $correctNet !== null) ? ((int) $posted - (int) $correctNet) : null;

        if ($posted === null) {
            $status = self::NOT_POSTED;
        } elseif ($correctNet === null) {
            $status = self::REQUIRES;
        } elseif ($netOver <= 0) {
            // sell == base (converted == posted) or no overstatement → nothing to reverse.
            $status = self::NOT_AFFECTED;
        } else {
            $status = self::REMEDIABLE;
        }

        // Derived legs at the policy's VAT rate (only meaningful when we have a positive overstatement).
        $vatOver   = $netOver !== null && $netOver > 0 ? (int) round($netOver * $vatBp / 10000) : ($netOver !== null ? 0 : null);
        $grossOver = $netOver !== null ? $netOver + ($vatOver ?? 0) : null;

        $m = fn (?int $minor) => $minor === null ? null : round($minor / $factor, 2);

        return [
            'order_id'            => $orderId,
            'order_no'            => (string) $o['order_no'],
            'date'                => (string) ($o['date'] ?? ''),
            'base_currency'       => $base,
            'sell_currency'       => $sell,
            'posted_net_minor'    => $posted,
            'correct_net_minor'   => $correctNet,
            'net_over_minor'      => $netOver,
            'vat_over_minor'      => $vatOver,
            'gross_over_minor'    => $grossOver,
            'posted_net'          => $m($posted),
            'correct_net'         => $m($correctNet),
            'net_overstatement'   => $m($netOver),
            'vat_overstatement'   => $m($vatOver),
            'gross_overstatement' => $m($grossOver),
            'correction_aggregate' => 'salefx_' . $orderId,
            'source_sale_aggregate' => (string) $orderId,
            'version'             => self::VERSION,
            'status'              => $status,
            'remediable'          => $status === self::REMEDIABLE,
        ];
    }
}
