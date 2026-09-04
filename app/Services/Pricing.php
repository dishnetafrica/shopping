<?php
namespace App\Services;

use App\Models\Tenant;

class Pricing
{
    /**
     * Minor-unit precision for a currency. Zero-decimal currencies (UGX and the East
     * African shillings, plus the ISO zero-decimal set) format as whole numbers; every
     * other currency (USD, EUR, …) keeps 2 decimals. This is the single source of truth
     * so prices, totals, receipts and messages never silently round away cents.
     */
    public static function decimalsForCurrency(string $cur): int
    {
        $zero = ['UGX','KES','TZS','RWF','BIF','SSP','JPY','KRW','VND','XAF','XOF','GNF','MGA','CLP','PYG','ISK','KMF','XPF'];
        return in_array(strtoupper(trim($cur)), $zero, true) ? 0 : 2;
    }

    public static function decimals(Tenant $tenant): int
    {
        return self::decimalsForCurrency((string) $tenant->setting('currency', 'UGX'));
    }

    /** Net price after the tenant's store-wide discount, rounded to the currency's precision. */
    public static function net(Tenant $tenant, float $base): float
    {
        $pct = (float) $tenant->setting('discount_pct', 0);
        $amt = (float) $tenant->setting('discount_amt', 0);
        return max(0, round($base * (1 - $pct / 100) - $amt, self::decimals($tenant)));
    }

    public static function money(Tenant $tenant, float $amount): string
    {
        $cur = (string) $tenant->setting('currency', 'UGX');
        return $cur . ' ' . number_format($amount, self::decimalsForCurrency($cur));
    }

    /**
     * The per-piece ("loose") selling price for one unit out of a carton: the carton price divided
     * by the pack size, plus the loose markup, rounded to the currency's precision. The single source
     * of truth for the loose formula (bot quote, storefront and the catalogues all agree). Returns 0
     * when the product isn't sold loose (pack size ≤ 1, or no carton price).
     */
    public static function loosePrice(float $cartonPrice, int $packSize, float $markupPct, int $dec): float
    {
        if ($packSize <= 1 || $cartonPrice <= 0) {
            return 0.0;
        }
        return round(($cartonPrice / $packSize) * (1 + $markupPct / 100), $dec);
    }

    /**
     * The retail price of ONE piece, honouring an explicit per-product override. When the product has a
     * positive `piece_price` set, that IS the piece price (rounded to the currency's precision) — the
     * owner's box price and piece price are then two independent numbers. Otherwise it falls back to the
     * derived loose formula, so a product with no override behaves exactly as before. Returns 0 when the
     * product isn't sold loose (pack size ≤ 1 or no carton price) and no override is set.
     */
    public static function piecePrice(?float $override, float $cartonPrice, int $packSize, float $markupPct, int $dec): float
    {
        if ($override !== null && $override > 0) {
            return round($override, $dec);
        }
        return self::loosePrice($cartonPrice, $packSize, $markupPct, $dec);
    }
}
