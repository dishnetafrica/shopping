<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — "is X in the menu?" query parser. Pure logic, no framework deps.
 *
 * Detects item-presence / item-count questions and extracts the item phrase. English +
 * romanised Gujarati. Returns ['type' => presence|count, 'item' => '<phrase>'] or null.
 *
 *   "Chaas che?"            -> presence, chaas
 *   "Tameta sev che?"       -> presence, tameta sev
 *   "Rice included che?"    -> presence, rice
 *   "Chaas male?"           -> presence, chaas
 *   "Chapati ketli?"        -> count,    chapati
 *   "is there raita?"       -> presence, raita
 *
 *   "su che?" / "aaje su che?" -> null  (whole-menu question, not an item)
 *   "kem che?"                 -> item 'kem' (rejected later by the food check, so a greeting
 *                                 is never answered as a menu item)
 */
class ItemQueryParser
{
    /** words that signal an availability question (and are stripped from the item) */
    private const PRESENCE = ['che', 'chhe', 'chho', 'male', 'malse', 'madse', 'milshe', 'milse',
        'hoy', 'hoi', 'hase', 'hashe', 'ave', 'avshe', 'available', 'included', 'include', 'there'];

    private const COUNT = ['ketli', 'ketla', 'kitli', 'kitla'];

    /** leading framing words, stripped from the item */
    private const LEAD = ['is', 'do', 'you', 'have', 'has', 'got', 'can', 'i', 'get', 'any', 'please',
        'koi', 'kai', 'su', 'shu', 'how', 'many'];

    /** an item that is only one of these is a whole-menu / chit-chat word, not a dish */
    private const STOP_ITEM = ['su', 'shu', 'kai', 'kaai', 'kya', 'what', 'whats', 'menu', 'thali',
        'special', 'lunch', 'dinner', 'food', 'aaj', 'aaje', 'today', 'tiffin', 'kuch', 'something',
        'anything', 'it', 'jaman', 'jamvanu'];

    public static function detect(string $text): ?array
    {
        $t = trim(mb_strtolower($text));
        $t = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]+/', ' ', $t)));
        if ($t === '') return null;

        $toks = explode(' ', $t);

        $hasCount    = (bool) array_intersect($toks, self::COUNT) || str_contains($t, 'how many');
        $hasPresence = (bool) array_intersect($toks, self::PRESENCE);
        if (! $hasCount && ! $hasPresence) return null;

        // the item = the words left after removing the framing/availability/count words
        $strip = array_merge(self::PRESENCE, self::COUNT, self::LEAD);
        $itemToks = array_values(array_filter($toks, fn ($w) => ! in_array($w, $strip, true) && ! ctype_digit($w)));

        // drop pure whole-menu / chit-chat words
        $itemToks = array_values(array_filter($itemToks, fn ($w) => ! in_array($w, self::STOP_ITEM, true)));
        $item = trim(implode(' ', $itemToks));
        if ($item === '') return null;

        return ['type' => $hasCount ? 'count' : 'presence', 'item' => $item];
    }
}
