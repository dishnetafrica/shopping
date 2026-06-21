<?php

namespace App\Services\Bot\Pricing;

/**
 * Weight Pricing V1 — size-reply interpreter. Pure logic, no framework deps.
 *
 * After a single sold-by-weight product is shown (image card: "250g / 500g / 1kg"),
 * the customer's next message may be a size for THAT item. This returns the grams, or
 * null when the message isn't a size reply for the focal product. For a Jalebi card
 * offering [250, 500, 1000]:
 *
 *   bare number on the card   "250"                 -> 250
 *   explicit weight           "250g" / "1kg"        -> 250 / 1000
 *   fraction of a kg          "half kg" / "1/2 kg"  -> 500
 *                             "quarter kilo"        -> 250
 *                             "three quarter kg"    -> 750
 *   word number + kg          "two kg"              -> 2000
 *   bare relative word        "half" / "full"       -> 500 / 1000   (vs largest offered)
 *                             "quarter"             -> 250
 *   names the focal item too  "half kg jalebi"      -> 500
 *
 *   list index                "1" / "2"             -> null  (never a weight)
 *   off-card bare number      "750"                 -> null  (don't invent a size)
 *   different product named   "250g rice"           -> null  (not this item)
 *   no weight                 "jalebi" / "hi"       -> null
 */
class WeightReply
{
    private const UNIT_WORDS = [
        'g', 'gm', 'gms', 'gram', 'grams',
        'kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms', 'kilogramme', 'kilogrammes',
    ];
    private const FRACTION_WORDS = ['half', 'quarter', 'quarters', 'three', 'full', 'whole'];
    private const NUMBER_WORDS   = ['one', 'two', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];
    private const FILLER         = ['of', 'the', 'a', 'an', 'and', 'please', 'pls', 'plz', 'kindly',
        'want', 'need', 'add', 'i', 'me', 'my', 'give', 'just', 'some', 'order', 'to', 'can', 'get'];

    /** Lowercased word tokens, splitting letter runs from digit runs ("250g" -> 250, g). */
    private static function toks(string $s): array
    {
        preg_match_all('/[a-z]+|\d+/u', mb_strtolower($s), $m);
        return $m[0] ?? [];
    }

    /**
     * @param string   $text            the customer's message
     * @param int[]    $offeredGrams    grams the card advertised (e.g. [250,500,1000])
     * @param string[] $focalNameTokens tokens of the focal product's name (e.g. ['jalebi','1','kg'])
     */
    public static function grams(string $text, array $offeredGrams, array $focalNameTokens): ?int
    {
        $t = trim(mb_strtolower($text));
        if ($t === '') return null;

        $offered = array_values(array_filter(array_map('intval', $offeredGrams), fn ($x) => $x > 0));
        $maxOffered = $offered ? max($offered) : 0;

        // (A) a fraction / word-number qualifying a kg/kilo unit -> absolute grams
        $g = self::fractionKgGrams($t);

        // (B) an explicit numeric weight token ("250g", "1kg", "0.5kg", "1.5 kg")
        if ($g === null) $g = WeightParser::grams($t);

        // (C) a BARE number, only if it matches an advertised size (so "1"/"2" stay indices
        //     and an arbitrary "750" isn't read as grams)
        if ($g === null && preg_match('/^\d{2,}$/', $t) && in_array((int) $t, $offered, true)) {
            $g = (int) $t;
        }

        // (D) a bare relative word, vs the largest advertised size: full/half/quarter/three-quarter
        if ($g === null && $maxOffered > 0) {
            $rel = self::relativeWord($t);
            if ($rel !== null) $g = (int) round($maxOffered * $rel);
        }

        if ($g === null || $g <= 0) return null;

        // Scope guard: every word must be part of the weight expression, the focal product's
        // own name, or harmless filler. A foreign product word (e.g. "rice") -> not this item.
        $ok = array_merge(
            array_map('strtolower', $focalNameTokens),
            self::UNIT_WORDS, self::FRACTION_WORDS, self::NUMBER_WORDS, self::FILLER
        );
        foreach (self::toks($t) as $w) {
            if (ctype_digit($w)) continue;                 // numbers are part of the size
            if (! in_array($w, $ok, true)) return null;    // a foreign word -> defer to normal flow
        }

        return $g;
    }

    /** "half kg" / "1/2 kg" / "three quarter kilo" / "two kg" -> grams; null if no kg-unit fraction. */
    private static function fractionKgGrams(string $t): ?int
    {
        if (! preg_match('/\b(kgs?|kilos?|kilogram\w*|kilogramme\w*)\b/u', $t)) return null;

        // a/b kg  ("1/2 kg", "3/4 kg")
        if (preg_match('#(\d+)\s*/\s*(\d+)\s*(?:kgs?|kilos?|kilogram\w*|kilogramme\w*)\b#u', $t, $m)) {
            $b = (int) $m[2];
            if ($b > 0) return self::kg((int) $m[1] / $b);
        }

        // <words> kg  ("half kg", "three quarter kilo", "two kg", "full kg")
        if (preg_match('/([a-z][a-z\s\-]*?)\s*(kgs?|kilos?|kilogram\w*|kilogramme\w*)\b/u', $t, $m)) {
            $f = self::wordFactor($m[1]);
            if ($f !== null) return self::kg($f);
        }
        return null;
    }

    private static function kg(float $factor): ?int
    {
        $g = (int) round($factor * 1000);
        return $g > 0 ? $g : null;
    }

    /** Map a leading word phrase to a multiple of 1kg. Longest phrases first (specificity). */
    private static function wordFactor(string $w): ?float
    {
        $w = ' ' . trim(preg_replace('/[\s\-]+/', ' ', mb_strtolower($w))) . ' ';
        $w = preg_replace('/\b(an?)\b/', ' ', $w);            // drop articles (keep "and")
        $w = trim(preg_replace('/\s+/', ' ', $w));
        if ($w === '') return null;

        $map = [
            'one and half' => 1.5, 'one and a half' => 1.5,
            'three quarters' => 0.75, 'three quarter' => 0.75,
            'one quarter' => 0.25, 'one half' => 0.5,
            'quarter' => 0.25, 'half' => 0.5,
            'whole' => 1.0, 'full' => 1.0, 'one' => 1.0,
            'two' => 2.0, 'three' => 3.0, 'four' => 4.0, 'five' => 5.0,
        ];
        foreach ($map as $k => $v) {                         // insertion order = longest first
            if ($w === $k || preg_match('/(^| )' . preg_quote($k, '/') . '$/', $w)) return $v;
        }
        return null;
    }

    /** Bare relative word -> fraction of the largest offered size. */
    private static function relativeWord(string $t): ?float
    {
        $t = ' ' . preg_replace('/[\s\-]+/', ' ', trim(mb_strtolower($t))) . ' ';
        if (preg_match('/ (three quarters?|3 quarters?) /', $t)) return 0.75;
        if (preg_match('/ quarter /', $t)) return 0.25;
        if (preg_match('/ half /', $t))    return 0.5;
        if (preg_match('/ (full|whole) /', $t)) return 1.0;
        return null;
    }
}
