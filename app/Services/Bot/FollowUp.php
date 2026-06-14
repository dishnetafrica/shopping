<?php

namespace App\Services\Bot;

/**
 * Detects a context follow-up ("more brands", "show more", "larger size", "cheaper one")
 * and returns the modifier. Pure & static.
 *
 * Returns null for anything that names a product ("more rice") or isn't a follow-up — the
 * caller only acts on a follow-up when there is an active product/category context.
 *
 *  'more'    -> show more / other options in the same context
 *  'cheaper' -> sort by price ascending
 *  'premium' -> sort by price descending
 *  'larger'  -> sort by size descending
 *  'smaller' -> sort by size ascending
 */
class FollowUp
{
    private const MAP = [
        'more' => ['more', 'show more', 'show me more', 'give me more', 'more brands', 'more brand',
            'other brands', 'other brand', 'more options', 'other options', 'any other options',
            'any other option', 'any other', 'any others', 'any more', 'what else', 'anything else',
            'more choices', 'more variety', 'others', 'other ones', 'show others', 'different brand',
            'different brands', 'different size', 'another size', 'other size', 'other sizes',
            'different sizes', 'sizes', 'options', 'alternatives', 'any alternative'],
        'cheaper' => ['cheaper', 'cheaper one', 'cheaper option', 'cheapest', 'cheapest one',
            'lower price', 'less expensive', 'cheap one', 'budget one', 'affordable one', 'something cheaper'],
        'premium' => ['premium', 'premium one', 'expensive one', 'higher quality', 'better one',
            'best one', 'top one', 'high end', 'something better'],
        'larger' => ['larger', 'larger size', 'larger one', 'bigger', 'bigger size', 'bigger one',
            'large size', 'big one', 'bigger pack', 'large one'],
        'smaller' => ['smaller', 'smaller size', 'smaller one', 'small one', 'small size', 'smaller pack'],
    ];

    private const LEAD = ['do you have', 'got', 'any', 'show me', 'give me', 'i want', 'can i get',
        'u have', 'you have', 'pls', 'please', 'send me'];
    private const TRAIL = ['if u have', 'if you have', 'if available', 'available', 'please', 'pls',
        'plz', 'for me', 'then', 'now', 'u have', 'you have'];

    public static function parse(string $text): ?string
    {
        $t = mb_strtolower($text);
        $t = preg_replace('/[^a-z0-9\s]+/', ' ', $t);
        $t = trim(preg_replace('/\s+/', ' ', $t));
        if ($t === '') return null;

        // strip trailing then leading filler, repeatedly
        foreach (self::TRAIL as $f) { $t = preg_replace('/\s+' . preg_quote($f, '/') . '$/', '', $t); }
        foreach (self::LEAD as $f)  { $t = preg_replace('/^' . preg_quote($f, '/') . '\s+/', '', $t); }
        $t = trim($t);

        foreach (self::MAP as $mod => $phrases) {
            if (in_array($t, $phrases, true)) return $mod;
        }
        return null;
    }
}
