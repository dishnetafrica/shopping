<?php
namespace App\Services\Bot\Pricing;

/**
 * Normalizes a protocol quantity {value, unit} into the order primitives the cart understands:
 * a count (qty) and, for sold-by-weight items, a weight in grams. Extensible by design — new units
 * are added here without touching the AI protocol, the parser, or the pricer.
 *
 *   250 g    → ['qty'=>1, 'weight_grams'=>250, 'loose'=>false]
 *   1 kg     → ['qty'=>1, 'weight_grams'=>1000,'loose'=>false]
 *   4 pcs    → ['qty'=>4, 'weight_grams'=>null, 'loose'=>true]   (an explicit PIECE unit)
 *   1 carton → ['qty'=>1, 'weight_grams'=>null, 'loose'=>false]
 *   1 dozen  → ['qty'=>12,'weight_grams'=>null, 'loose'=>false]
 */
class QuantityNormalizer
{
    private const GRAMS = ['g', 'gm', 'gms', 'gram', 'grams'];
    private const KILOS = ['kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms'];
    private const PIECES = ['pc', 'pcs', 'piece', 'pieces', 'pkt', 'pkts', 'pack', 'packs', 'packet', 'packets', 'unit', 'units', ''];
    // A genuine per-PIECE unit — the customer wants single pieces, not a carton/pack. This is the
    // deterministic "loose" signal the pricer uses to charge the per-piece (retail) rate on a
    // carton-packed product. Deliberately NARROW: pack/packet/carton/dozen and the empty default
    // are NOT loose (they denote the wholesale pack), so nothing changes unless a piece word is said.
    private const LOOSE = ['pc', 'pcs', 'piece', 'pieces', 'each'];

    /** @return array{qty:int,weight_grams:?int,loose:bool} */
    public static function normalize(float $value, string $unit): array
    {
        $u = strtolower(trim($unit));

        if (in_array($u, self::GRAMS, true)) return ['qty' => 1, 'weight_grams' => max(1, (int) round($value)), 'loose' => false];
        if (in_array($u, self::KILOS, true)) return ['qty' => 1, 'weight_grams' => max(1, (int) round($value * 1000)), 'loose' => false];

        // count-style units (incl. unknown/empty) → a quantity; never a weight
        $qty = match (true) {
            in_array($u, ['dozen', 'dozens'], true) => (int) round($value * 12),
            default                                 => (int) round($value),
        };
        return ['qty' => max(1, $qty), 'weight_grams' => null, 'loose' => in_array($u, self::LOOSE, true)];
    }
}
