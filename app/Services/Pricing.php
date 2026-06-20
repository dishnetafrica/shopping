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
}
