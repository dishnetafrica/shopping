<?php
namespace App\Services\Bot\Pricing;

/**
 * Weight Pricing V1 — unit parser. Pure logic.
 *
 * Recognizes a weight token anywhere in a message and normalizes it to an integer
 * number of GRAMS. Kilogram forms accept decimals (1.5kg -> 1500). Never returns a
 * "quantity" — only grams — so callers cannot accidentally treat 750g as qty 750.
 *
 *   100g / 100 g / 100 gram / 100 grams -> 100
 *   500gm / 500 gm                      -> 500
 *   1kg / 1 kg / 1 kilo / 1 kilos       -> 1000
 *   1.5kg / 0.75 kg                     -> 1500 / 750
 */
class WeightParser
{
    /** First weight token in the string -> grams, or null if none present. */
    public static function grams(string $text): ?int
    {
        // longer unit words first so "gram" isn't eaten by "g"
        if (! preg_match(
            '/(\d+(?:\.\d+)?)\s*(kilograms?|kilogrammes?|kilos?|kgs?|grams?|gms?|gm|g)\b/i',
            mb_strtolower($text), $m
        )) {
            return null;
        }
        $num  = (float) $m[1];
        $unit = $m[2];
        $grams = (str_starts_with($unit, 'k')) ? $num * 1000.0 : $num;     // k* = kilo family
        $g = (int) round($grams);
        return $g > 0 ? $g : null;
    }

    /** True if the text contains an explicit weight unit (so we know it's a weight order). */
    public static function hasWeight(string $text): bool
    {
        return self::grams($text) !== null;
    }

    /** Human label for a gram amount: 750 -> "750g", 1500 -> "1.5kg", 1000 -> "1kg". */
    public static function label(int $grams): string
    {
        if ($grams % 1000 === 0) return ($grams / 1000) . 'kg';
        if ($grams > 1000)       return rtrim(rtrim(number_format($grams / 1000, 3, '.', ''), '0'), '.') . 'kg';
        return $grams . 'g';
    }
}
