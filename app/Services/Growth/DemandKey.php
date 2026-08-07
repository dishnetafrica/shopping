<?php declare(strict_types=1);

namespace App\Services\Growth;

/**
 * v4.58 — turning "do you have brake pads?" and "Brake Pad" into one line on a report.
 *
 * PURE. A string in, a grouping key and a readable label out.
 *
 * Unmet demand arrives as free text from two places — a customer typing into the storefront search
 * that finds nothing, and the bot noticing somebody asked for something not in the catalogue. Both
 * are worth almost nothing one row at a time. "Musa asked for brake pads" is a follow-up; "nine
 * customers asked for brake pads this month" is a purchase order.
 *
 * Getting from the first to the second is entirely a question of grouping, and grouping is entirely
 * a question of this class. `PartRequestService::normKey` already existed and strips every
 * non-alphanumeric character, which makes "brake pad" and "brake pads" two different demands — so
 * the aggregate it feeds always undercounts, quietly, in the direction of doing nothing.
 *
 * The rule followed here: normalise aggressively for the KEY, and keep a human's own words for the
 * LABEL. Nobody should have to read "brakepad" on a report.
 */
final class DemandKey
{
    /**
     * Lead-ins people type before the thing they actually want. Stripped so "do you have rice" and
     * "rice" are one demand. Ordered longest-first so "do you have" is removed before "have".
     */
    private const NOISE = [
        'do you have any', 'do you have', 'do you sell', 'are you having', 'you have',
        'i am looking for', 'am looking for', 'looking for', 'i want to buy', 'i want', 'i need',
        'we need', 'need', 'please send', 'send me', 'price of', 'price for', 'cost of',
        'how much is', 'how much for', 'how much', 'any', 'got', 'available', 'availability of',
    ];

    /** Below this a "word" is noise or a stray letter, not something anyone is asking for. */
    private const MIN_LENGTH = 3;

    /** The grouping key. Never shown to anyone. */
    public static function key(string $raw): string
    {
        $s = self::clean($raw);
        if ($s === '') {
            return '';
        }

        $words = array_map([self::class, 'singular'], explode(' ', $s));
        sort($words);   // "pad brake" and "brake pad" are the same request typed by two people

        return implode(' ', $words);
    }

    /**
     * What to print. The customer's own phrasing, tidied — not the key.
     *
     * Given several spellings of one demand, the one MOST people typed wins; ties go to the longest,
     * because "brake pads front" tells a buyer more than "pads".
     *
     * @param  array<int,string>  $variants  raw strings that share a key
     */
    public static function label(array $variants): string
    {
        $counts = [];
        foreach ($variants as $v) {
            $t = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
            if ($t === '') {
                continue;
            }
            $counts[$t] = ($counts[$t] ?? 0) + 1;
        }
        if (! $counts) {
            return '';
        }

        $best = null; $bestCount = -1;
        foreach ($counts as $text => $n) {
            if ($n > $bestCount || ($n === $bestCount && mb_strlen($text) > mb_strlen((string) $best))) {
                $best = $text; $bestCount = $n;
            }
        }

        return self::sentenceCase((string) $best);
    }

    /** Is this worth putting on a report at all? */
    public static function usable(string $raw): bool
    {
        $k = self::key($raw);

        return $k !== '' && mb_strlen(str_replace(' ', '', $k)) >= self::MIN_LENGTH;
    }

    /** Lowercase, punctuation to spaces, lead-ins removed, whitespace collapsed. */
    private static function clean(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        foreach (self::NOISE as $n) {
            if (str_starts_with($s, $n . ' ')) {
                $s = trim(substr($s, strlen($n) + 1));
            }
        }

        // A number on its own is a quantity, not a product. "5kg" stays; "5" goes.
        $words = array_filter(explode(' ', $s), fn ($w) => $w !== '' && ! ctype_digit($w));

        return implode(' ', $words);
    }

    /**
     * Crude English singularisation, and deliberately so.
     *
     * "pads" → "pad" is the whole job. Anything cleverer starts turning "gas" into "ga" and
     * "molasses" into "molasse", which splits a demand instead of joining it — the exact failure
     * this class exists to prevent, arrived at from the other direction.
     */
    public static function singular(string $w): string
    {
        $len = mb_strlen($w);
        if ($len < 4 || ! str_ends_with($w, 's')) {
            return $w;
        }
        if (str_ends_with($w, 'ss') || str_ends_with($w, 'us') || str_ends_with($w, 'is')) {
            return $w;                                   // glass, bus, chassis — already singular
        }
        if (str_ends_with($w, 'ies') && $len > 4) {
            return mb_substr($w, 0, $len - 3) . 'y';     // batteries → battery
        }
        if (preg_match('/(s|x|z|ch|sh)es$/u', $w)) {
            return mb_substr($w, 0, $len - 2);           // boxes → box, brushes → brush
        }

        return mb_substr($w, 0, $len - 1);               // pads → pad
    }

    /** First letter up, the rest as typed — brands and sizes must survive untouched. */
    private static function sentenceCase(string $s): string
    {
        return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
