<?php
namespace App\Apps\Orders\Resolve;

use App\Services\Bot\Pricing\WeightPricer;

/**
 * Weight-aware resolution with EXPLICIT rules (reusing the existing WeightPricer): exact variant
 * wins; else pro-rata only if the product allows it; never silently substitute a larger pack;
 * below the minimum, ask. Pure — takes plain product data.
 */
class WeightResolver
{
    /**
     * @param array{sold_by_weight:bool,price:?float,reference_price:?float,reference_weight_grams:?int,
     *              variants:array<int,array{id:?int,price:float}>,allow_prorata:bool,min_grams:?int,
     *              default_variant_id:?int} $product
     * @return array{ok:bool,weight_grams:?int,unit_price:?float,variant_id:?int,source:?string,reason:?string}
     */
    public function resolve(array $product, ?int $grams): array
    {
        if ($grams === null) {                                   // sold by unit
            return $this->ok(null, $product['price'] ?? null, $product['default_variant_id'] ?? null, 'unit');
        }
        if (empty($product['sold_by_weight'])) {
            return $this->fail('not_weight_priced');             // a weight was given for a unit product → clarify
        }

        $priceVariants = [];
        foreach (($product['variants'] ?? []) as $g => $v) { $priceVariants[(int) $g] = (float) $v['price']; }

        $res = WeightPricer::price($grams, [
            'reference_price'        => $product['reference_price'] ?? null,
            'reference_weight_grams' => (int) ($product['reference_weight_grams'] ?: 1000),
            'variants'               => $priceVariants,
            'min_grams'              => (int) ($product['min_grams'] ?? 100),
        ]);

        if (! ($res['ok'] ?? false)) {
            return $this->fail($res['reason'] ?? 'no_reference', $res['min_grams'] ?? null);
        }
        if ($res['source'] === 'variant') {
            return $this->ok($grams, (float) $res['price'], $product['variants'][$grams]['id'] ?? null, 'variant');
        }
        // pro-rata — only if the product permits it; otherwise never silently upsize → clarify
        if (empty($product['allow_prorata'])) {
            return $this->fail('needs_weight_clarify');
        }
        return $this->ok($grams, (float) $res['price'], null, 'prorata');
    }

    private function ok(?int $g, ?float $price, ?int $variantId, string $source): array
    {
        return ['ok' => true, 'weight_grams' => $g, 'unit_price' => $price, 'variant_id' => $variantId, 'source' => $source, 'reason' => null];
    }
    private function fail(string $reason, ?int $min = null): array
    {
        return ['ok' => false, 'weight_grams' => null, 'unit_price' => null, 'variant_id' => null, 'source' => null, 'reason' => $reason] + ($min ? ['min_grams' => $min] : []);
    }
}
