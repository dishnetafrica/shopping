<?php

namespace App\Capability\Support;

/**
 * Presentation-only money formatting for capability headlines/labels.
 *
 * The engine is multi-currency: every invoice/order/cost carries its own native currency
 * (UGX/SSP/USD). Headlines must therefore show the RIGHT symbol — never a hard-coded '$'.
 * This helper does no math and touches no models; it only turns (amount, currency) into a
 * human string. USD gets the '$' prefix; every other currency is suffixed with its code
 * (e.g. "745,000 UGX") so a local total is never mistaken for dollars.
 */
final class Money
{
    /**
     * Currency-aware formatting (the common case for headlines/totals).
     *
     * Decimals follow the currency, not a fixed 0: zero-decimal currencies (UGX/SSP and the
     * rest of the East-African + ISO zero-decimal set) stay whole, while USD/EUR keep their
     * 2 minor digits. This is what lets a real cent price ($1.02, or $0.50) survive display
     * instead of being rounded to "$1"/"$0". Precision comes from the single source of truth,
     * \App\Services\Pricing::decimalsForCurrency(), so nothing here re-decides rounding.
     */
    public static function fmt(float|int|string $amount, ?string $currency = null): string
    {
        return self::render((float) $amount, $currency, null);
    }

    /** Force two decimals regardless of currency (unit prices where cents are always shown). */
    public static function fmt2(float|int|string $amount, ?string $currency = null): string
    {
        return self::render((float) $amount, $currency, 2);
    }

    private static function render(float $amount, ?string $currency, ?int $decimals): string
    {
        $cur = strtoupper((string) ($currency ?: 'UGX'));
        $dec = $decimals ?? \App\Services\Pricing::decimalsForCurrency($cur);
        $num = number_format($amount, $dec);

        return $cur === 'USD' ? '$' . $num : $num . ' ' . $cur;
    }
}
