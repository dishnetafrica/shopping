<?php

namespace App\Services\Bot\Pricing;

/**
 * Weight Pricing V1 — size-reply interpreter. Pure logic, no framework deps.
 *
 * After a single sold-by-weight product is shown (image card: "250g / 500g / 1kg"),
 * the customer's next message may be a bare size for THAT item. This decides whether a
 * message is such a reply and returns the grams, e.g. for a Jalebi card offering
 * [250, 500, 1000]:
 *
 *   "250"              -> 250    (bare number matching an offered size)
 *   "250g" / "250 g"   -> 250
 *   "250 gram jalebi"  -> 250    (names the focal item -> still a size reply)
 *   "1kg" / "0.5 kg"   -> 1000 / 500
 *   "300g"             -> 300    (explicit unit, even if not on the card)
 *   "1" / "2"          -> null   (a list index, never a weight)
 *   "750"              -> null   (bare number NOT offered -> leave for normal flow)
 *   "250g rice"        -> null   (names a different product -> not this item)
 *   "jalebi" / "hi"    -> null   (no weight at all)
 *
 * Never maps a bare list index ("1") to grams, so the numbered-list selector still works.
 */
class WeightReply
{
    /** Lowercased alphanumeric word tokens. */
    private static function toks(string $s): array
    {
        preg_match_all('/[a-z0-9]+/u', mb_strtolower($s), $m);
        return $m[0] ?? [];
    }

    /**
     * @param string $text            the customer's message
     * @param int[]  $offeredGrams    grams the card advertised (e.g. [250,500,1000])
     * @param string[] $focalNameTokens  tokens of the focal product's name (e.g. ['jalebi','1','kg'])
     * @return int|null grams, or null if this isn't a size reply for the focal item
     */
    public static function grams(string $text, array $offeredGrams, array $focalNameTokens): ?int
    {
        $t = trim(mb_strtolower($text));
        if ($t === '') return null;

        // 1) explicit weight token anywhere ("250g", "1kg", "0.5 kg", "250 gram ...")
        $g = WeightParser::grams($t);

        // 2) a BARE number, but only if it matches an advertised size (so "1"/"2" stay
        //    list indices and an arbitrary "750" isn't read as 750 grams).
        if ($g === null && preg_match('/^\d{2,}$/', $t)) {
            $n = (int) $t;
            if (in_array($n, array_map('intval', $offeredGrams), true)) $g = $n;
        }

        if ($g === null || $g <= 0) return null;

        // 3) scope guard: strip the weight token; whatever remains must be either the focal
        //    product's own name words or harmless filler — otherwise the customer named a
        //    DIFFERENT product and this isn't a size reply for this item.
        $rest = preg_replace(
            '/\d+(?:\.\d+)?\s*(?:kilograms?|kilogrammes?|kilos?|kgs?|grams?|gms?|gm|g)?/iu',
            ' ', $t
        );
        $stop = array_merge(
            array_map('strtolower', $focalNameTokens),
            ['of', 'the', 'a', 'an', 'please', 'pls', 'plz', 'kindly', 'want', 'need',
             'add', 'i', 'me', 'my', 'give', 'just', 'some', 'order', 'to', 'and', 'can', 'get']
        );
        foreach (self::toks((string) $rest) as $w) {
            if (! in_array($w, $stop, true)) return null;
        }

        return $g;
    }
}
