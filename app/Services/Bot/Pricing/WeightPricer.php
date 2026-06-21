<?php
namespace App\Services\Bot\Pricing;

/**
 * Weight Pricing V1 — pricer. Pure logic, storage-agnostic.
 *
 * Priority (your spec):
 *   1. Exact variant match  (merchant-defined price for that exact weight wins)
 *   2. Pro-rata from a reference price
 *   3. Round (nearest 100 UGX by default)
 * with a minimum-weight floor (100g default).
 *
 * The caller supplies the reference price and any explicit variants as plain data, so
 * this class does not care whether variants live in a table or separate product rows.
 *
 * price(int $grams, [
 *     'reference_price'        => 50000,        // price of reference_weight_grams
 *     'reference_weight_grams' => 1000,         // default 1000
 *     'variants'               => [250=>12500, 500=>25000, 1000=>50000],
 *     'round_to'               => 100,          // default 100
 *     'min_grams'              => 100,          // default 100
 * ]): array
 *
 * Returns:
 *   ['ok'=>true,  'grams'=>750, 'price'=>37500, 'source'=>'prorata'|'variant']
 *   ['ok'=>false, 'reason'=>'min', 'min_grams'=>100]
 *   ['ok'=>false, 'reason'=>'no_reference']        // not priceable
 */
class WeightPricer
{
    public static function price(int $grams, array $opt): array
    {
        $refWeight = (int) ($opt['reference_weight_grams'] ?? 1000);
        $refPrice  = $opt['reference_price'] ?? null;
        $variants  = $opt['variants'] ?? [];
        $roundTo   = max(1, (int) ($opt['round_to'] ?? 100));
        $minGrams  = max(1, (int) ($opt['min_grams'] ?? 100));

        if ($grams < $minGrams) {
            return ['ok' => false, 'reason' => 'min', 'min_grams' => $minGrams];
        }

        // 1) exact variant match wins — never override a merchant-set price
        if (isset($variants[$grams])) {
            return ['ok' => true, 'grams' => $grams, 'price' => (int) round($variants[$grams]), 'source' => 'variant'];
        }

        // 2) pro-rata
        if ($refPrice === null || $refWeight <= 0) {
            return ['ok' => false, 'reason' => 'no_reference'];
        }
        $raw = ((float) $refPrice / $refWeight) * $grams;

        // 3) round (half-up) to nearest step
        $price = (int) (round($raw / $roundTo) * $roundTo);

        return ['ok' => true, 'grams' => $grams, 'price' => $price, 'source' => 'prorata'];
    }
}
