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
    /** Whole-number formatting (the common case for headlines/totals). */
    public static function fmt(float|int|string $amount, ?string $currency = null): string
    {
        return self::render((float) $amount, $currency, 0);
    }

    /** Two-decimal formatting for unit prices. */
    public static function fmt2(float|int|string $amount, ?string $currency = null): string
    {
        return self::render((float) $amount, $currency, 2);
    }

    private static function render(float $amount, ?string $currency, int $decimals): string
    {
        $cur = strtoupper((string) ($currency ?: 'UGX'));
        $num = number_format($amount, $decimals);

        return $cur === 'USD' ? '$' . $num : $num . ' ' . $cur;
    }
}
