<?php declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * v4.65 — what a supplier calls a thing, versus what the catalogue calls it.
 *
 * PURE. Two names in, a verdict out. No models, no database.
 *
 * A purchase line moves stock only when it carries a product id, and it only carries one when the
 * typed item matched a product name exactly. On a real shop that almost never holds: the catalogue
 * says `ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON)` and the supplier's invoice — and
 * therefore the person typing it — says `Golden Foil 45cm`. Both are correct. They will never be
 * the same string, and no amount of care at the keyboard will make them so, because the supplier
 * will write it their way again next month.
 *
 * So the fix is not to make people type more carefully. It is to let a product carry the OTHER
 * names it is known by, and `products.keywords` is already that field — the bot has used it for
 * fuzzy search since the beginning, so a name added here makes the purchase link AND makes the bot
 * recognise the phrase when a customer uses it.
 *
 * ── the one rule ───────────────────────────────────────────────────────────────────────────────
 *
 * An alias match is EXACT, against a comma-separated entry. Not fuzzy, not partial, not a
 * substring. Everything in this file that looks like similarity is for SUGGESTING a match to a
 * person; nothing here ever links on a guess. A hundred cartons of foil counted against the wrong
 * product is worse than a hundred cartons counted against nothing, because the second is visible
 * and the first is not.
 */
final class PurchaseNameMatch
{
    /** Lowercase, trimmed, inner whitespace collapsed. The one normalisation, used by every path. */
    public static function key(?string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $s)));
    }

    /**
     * The other names a product answers to.
     *
     * Comma-separated, because that is the only way a multi-word alias can be told from a list of
     * search words. A keywords field written the old way — `rice grain staple`, space separated —
     * yields one entry that will match nothing, which is exactly right: it was never an alias.
     *
     * @return array<int,string> normalised, de-duplicated, blanks dropped
     */
    public static function aliases(?string $keywords): array
    {
        $out = [];
        foreach (explode(',', (string) $keywords) as $k) {
            $n = self::key($k);
            if ($n !== '' && ! in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /** Does this product answer to this name — by its own name, or by an alias? */
    public static function matches(string $purchaseName, ?string $productName, ?string $keywords): bool
    {
        $n = self::key($purchaseName);
        if ($n === '') {
            return false;
        }

        return $n === self::key($productName) || in_array($n, self::aliases($keywords), true);
    }

    /**
     * A product code shared by both names — `8389`, `SKU-2231`, `45CM`.
     *
     * The strongest signal there is for SUGGESTING a match, and the one a word-overlap score misses
     * completely: "Silver Plate (8389)" and "Aluminum Foil Container Medium-8389" share one token
     * out of eight and are obviously the same item to anybody who has held one.
     *
     * Still only a suggestion. A code is a strong hint and not a fact, and the person confirming it
     * takes two seconds.
     *
     * @return array<int,string> codes present in BOTH names
     */
    public static function sharedCodes(string $a, string $b): array
    {
        $codes = function (string $s): array {
            $out = [];
            foreach (preg_split('/[^a-z0-9]+/i', mb_strtolower($s)) as $tok) {
                // A token with digits in it and enough length to be a code rather than a size or a
                // count. "6pc" and "1l" are packaging; "8389" and "45cm" identify a thing.
                if (mb_strlen($tok) >= 4 && preg_match('/\d/', $tok)) {
                    $out[$tok] = true;
                }
            }

            // Cast: PHP turns a numeric-string array key back into an int, so a code of "8389"
            // would come out typed differently from "45cm" and a strict in_array() against it
            // would quietly fail.
            return array_map('strval', array_keys($out));
        };

        return array_values(array_intersect($codes($a), $codes($b)));
    }

    /**
     * How alike two names are, 0–100, for ordering suggestions in front of a person.
     *
     * Deliberately crude and deliberately never used to decide anything. Word overlap, plus a large
     * bonus for a shared product code, because the code is the part a human recognises instantly.
     */
    public static function similarity(string $a, string $b): int
    {
        $words = function (string $s): array {
            $s = mb_strtolower($s);
            $s = (string) preg_replace('/\(.*?\)/u', ' ', $s);                  // (carton of 6pc, min 5)
            $s = (string) preg_replace('/\b(carton|cartons|ctn|box|boxes|pcs?|pieces?|min|of)\b/u', ' ', $s);
            $s = (string) preg_replace('/[^a-z0-9]+/u', ' ', $s);

            return array_values(array_unique(array_filter(explode(' ', $s), fn ($w) => mb_strlen($w) > 1)));
        };

        $A = $words($a); $B = $words($b);
        if (! $A || ! $B) {
            return 0;
        }
        $hit = count(array_intersect($A, $B));
        $jac = $hit / (count($A) + count($B) - $hit);

        $bonus = self::sharedCodes($a, $b) ? 0.45 : 0.0;

        return (int) round(100 * min(1.0, $jac + $bonus));
    }

    /**
     * Adding an alias to a keywords field, without disturbing what is already in it.
     *
     * The bot has been searching that field for months. This appends; it never rewrites, never
     * reorders and never removes — a keywords field is somebody's work and an import is not entitled
     * to tidy it.
     */
    public static function addAlias(?string $keywords, string $alias): string
    {
        $existing = (string) $keywords;
        $n = self::key($alias);
        if ($n === '' || in_array($n, self::aliases($existing), true)) {
            return $existing;                    // already answers to it
        }

        return trim($existing) === '' ? trim($alias) : rtrim(trim($existing), ',') . ', ' . trim($alias);
    }
}
