<?php

namespace App\Services\Bot;

/**
 * Detects a context follow-up ("more items you have", "show more", "larger size",
 * "cheaper one") and returns the modifier. Pure & static.
 *
 *  'more'    -> show more / other options in the same context
 *  'cheaper' -> sort by price ascending
 *  'premium' -> sort by price descending
 *  'larger'  -> sort by size descending
 *  'smaller' -> sort by size ascending
 *
 * Returns null for anything that names a specific product ("more rice", "more bread") or
 * isn't a follow-up — the caller only acts when there is an active context.
 */
class FollowUp
{
    // size / price modifiers — matched as whole (filler-stripped) phrases
    private const MODIFIERS = [
        'cheaper' => ['cheaper', 'cheaper one', 'cheaper option', 'cheapest', 'cheapest one',
            'lower price', 'less expensive', 'cheap one', 'budget one', 'affordable one', 'something cheaper'],
        'premium' => ['premium', 'premium one', 'expensive one', 'higher quality', 'better one',
            'best one', 'top one', 'high end', 'something better'],
        'larger'  => ['larger', 'larger size', 'larger one', 'bigger', 'bigger size', 'bigger one',
            'large size', 'big one', 'bigger pack', 'large one', 'big', 'big size', 'large',
            'big size one', 'bigger size one', 'biggest', 'big pack', 'family size', 'family pack',
            'big size pack', 'big quantity', 'big bottle'],
        'smaller' => ['smaller', 'smaller size', 'smaller one', 'small one', 'small size', 'smaller pack',
            'small', 'small pack', 'small bottle', 'smallest', 'mini'],
    ];

    // generic "what we're showing" nouns (incl. common typos) — never specific products
    private const GENERIC = 'items?|itmes?|itme|iteams?|things?|stuff|options?|opt|brands?|choices?|'
        . 'products?|prodcuts?|varieties|variety|types?|kinds?|ones?|selections?|range|flavou?rs?';

    private const LEAD = ['you dont have', 'you don t have', 'u dont have', 'u don t have', 'dont have',
        'don t have', 'you have no', 'there is no', 'theres no', 'no', 'havent got', 'have you got',
        'do you have', 'do u have', 'got any', 'got', 'any', 'show me', 'show', 'give me',
        'gimme', 'i want', 'i need', 'can i get', 'u have', 'you have', 'pls', 'please', 'send me', 'some',
        'i am meaning', 'am meaning', 'i mean', 'i meant', 'meaning', 'i wanted'];
    private const TRAIL = ['if u have', 'if you have', 'if available', 'available', 'in stock', 'please', 'pls',
        'plz', 'for me', 'then', 'now', 'u have', 'you have', 'do you have', 'with you'];

    public static function parse(string $text): ?string
    {
        $t = mb_strtolower($text);
        $t = preg_replace('/[^a-z0-9\s]+/', ' ', $t);
        $t = trim(preg_replace('/\s+/', ' ', $t));
        if ($t === '') return null;

        // filler-stripped core for whole-phrase matching
        $core = $t;
        foreach (self::TRAIL as $f) { $core = preg_replace('/\s+' . preg_quote($f, '/') . '$/', '', $core); }
        foreach (self::LEAD as $f)  { $core = preg_replace('/^' . preg_quote($f, '/') . '\s+/', '', $core); }
        $core = trim($core);

        // 1) size / price modifiers (specific) win first
        foreach (self::MODIFIERS as $mod => $phrases) {
            if (in_array($core, $phrases, true)) return $mod;
        }

        // 1b) price OBJECTION ("too expensive", "that's costly", "over budget") -> cheaper.
        // Runs AFTER the exact match above so a request for "expensive one" (premium) is
        // never flipped. Matches objection-shaped phrases, not a bare "expensive one".
        if (preg_match('/\b(too|very|so|bit|really|way)\s+(expensive|costly|pricey|dear|much)\b/', $t)
            || preg_match('/^(expensive|costly|pricey)$/', $core)
            || preg_match('/\b(over|out of|beyond|above)\s+(my\s+)?budget\b/', $t)
            || preg_match('/\bcan\s*t\s+afford\b/', $t)
            || preg_match('/\bnot\s+affordable\b/', $t)
            || preg_match('/\b(any ?thing|some ?thing|show( me)?)\s+cheaper\b/', $t)) {
            return 'cheaper';
        }

        // 2) generic "more / other / what else" — robust to typos and trailing "you have"
        $g = self::GENERIC;
        $morePatterns = [
            '/^more$/',
            '/^(show|give me|gimme|got|any|some|i want|do you have|do u have|u have|you have)?\s*more\s+(' . $g . ')\b/',
            '/\b(any other|some other|other|more|another|different)\s+(' . $g . ')\b/',
            '/^(what|anything|something|any)\s+(else|more|other)\b/',
            '/^(else|others?|other ones?|more ones?)$/',
            '/\bwhat\s+(else|other)\b/',
        ];
        foreach ($morePatterns as $re) {
            if (preg_match($re, $t)) return 'more';
        }

        // 3) legacy whole-phrase "more" forms
        $moreCore = ['more', 'show more', 'show me more', 'give me more', 'more variety', 'more choices',
            'others', 'other ones', 'what else', 'anything else', 'different size', 'another size',
            'other size', 'other sizes', 'different sizes', 'sizes', 'alternatives', 'any alternative'];
        if (in_array($core, $moreCore, true)) return 'more';

        return null;
    }
}
