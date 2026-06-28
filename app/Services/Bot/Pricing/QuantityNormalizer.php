<?php
namespace App\Services\Bot\Pricing;

/**
 * Normalizes a protocol quantity {value, unit} into the order primitives the cart understands:
 * a count (qty) and, for sold-by-weight items, a weight in grams. Extensible by design — new units
 * are added here without touching the AI protocol, the parser, or the pricer.
 *
 *   250 g    → ['qty'=>1, 'weight_grams'=>250]
 *   1 kg     → ['qty'=>1, 'weight_grams'=>1000]
 *   4 pcs    → ['qty'=>4, 'weight_grams'=>null]
 *   1 dozen  → ['qty'=>12,'weight_grams'=>null]
 */
class QuantityNormalizer
{
    private const GRAMS = ['g', 'gm', 'gms', 'gram', 'grams'];
    private const KILOS = ['kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms'];
    private const PIECES = ['pc', 'pcs', 'piece', 'pieces', 'pkt', 'pkts', 'pack', 'packs', 'packet', 'packets', 'unit', 'units', ''];

    /** @return array{qty:int,weight_grams:?int} */
    public static function normalize(float $value, string $unit): array
    {
        $u = strtolower(trim($unit));

        if (in_array($u, self::GRAMS, true)) return ['qty' => 1, 'weight_grams' => max(1, (int) round($value))];
        if (in_array($u, self::KILOS, true)) return ['qty' => 1, 'weight_grams' => max(1, (int) round($value * 1000))];

        // count-style units (incl. unknown/empty) → a quantity; never a weight
        $qty = match (true) {
            in_array($u, ['dozen', 'dozens'], true) => (int) round($value * 12),
            default                                 => (int) round($value),
        };
        return ['qty' => max(1, $qty), 'weight_grams' => null];
    }
}
