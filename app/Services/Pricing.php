<?php
namespace App\Services;

use App\Models\Tenant;

class Pricing
{
    /** Net price after the tenant's store-wide discount, in base currency (UGX). */
    public static function net(Tenant $tenant, float $base): float
    {
        $pct = (float) $tenant->setting('discount_pct', 0);
        $amt = (float) $tenant->setting('discount_amt', 0);
        return max(0, round($base * (1 - $pct / 100) - $amt));
    }

    public static function money(Tenant $tenant, float $amount): string
    {
        $cur = $tenant->setting('currency', 'UGX');
        return $cur.' '.number_format($amount);
    }
}
