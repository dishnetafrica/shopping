<?php

// Minimal stand-in for App\Services\Pricing so the Bot engine's reply/format helpers run in the
// pure-PHP harness (no Laravel boot). Only the methods the engine touches; NOT used under PHPUnit,
// where the real Pricing is autoloaded. Values here are cosmetic — the harness asserts quantities.

namespace App\Services;

if (! class_exists('App\\Services\\Pricing', false)) {
    class Pricing
    {
        public static function decimalsForCurrency(string $currency): int { return 0; }

        public static function net($tenant, float $price): float { return $price; }

        public static function money($tenant, float $amount): string { return number_format($amount); }
    }
}
