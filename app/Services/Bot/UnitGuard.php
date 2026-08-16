<?php

namespace App\Services\Bot;

/**
 * UnitGuard — Phase-0 shopping-safety invariant (single source of truth).
 *
 * THE RULE: a weight/volume SIZE token ("250g", "2kg", "500ml") must NEVER become the cart
 * QUANTITY. Quantity is a count of sellable units; size selects a variant or a weight amount.
 * The two never cross. This is what prevents the reproduced "add 250g sugar" -> qty 250 failure.
 *
 * Used by BOTH understanding paths so the same guarantee holds whether or not an LLM is enabled:
 *   - the deterministic ShoppingEngine (single-candidate resolution), and
 *   - the post-NLU add path in BotBrain (validating the LLM's extracted qty).
 *
 * The decision is deliberately pure (no DB, no framework) so it is exhaustively harness-testable.
 * Weight PRICING is intentionally out of scope here — this class only decides count vs weight vs
 * clarify; the caller prices a weight line through the authoritative WeightPricer with the full
 * product model, so a size is never priced from partial data.
 */
final class UnitGuard
{
    /**
     * Decide a safe cart-line intent from a parsed item + the resolved product.
     *
     * @param array $ctx {
     *   name:            string   product display name (may embed its own pack size, e.g. "Sugar 1kg")
     *   sold_by_weight:  bool     is this product priced by weight?
     *   size:            ?string  normalised size token ("250g"/"2kg"/"500ml"/"1l") or null
     *   count:           ?int     an EXPLICIT unit count the customer stated (null if none)
     * }
     * @return array one of:
     *   ['mode'=>'count',   'qty'=>int]                 add this many whole units
     *   ['mode'=>'weight',  'grams'=>int, 'qty'=>1]     add one weight-priced line of this many grams
     *   ['mode'=>'clarify', 'requested'=>string, 'sold_by_weight'=>bool]   size can't be resolved safely; ask
     */
    public static function decide(array $ctx): array
    {
        $size  = isset($ctx['size']) && $ctx['size'] !== '' ? (string) $ctx['size'] : null;
        $count = array_key_exists('count', $ctx) && $ctx['count'] !== null ? (int) $ctx['count'] : null;

        // No size in play: a plain count line. This is the ONLY path that may use a stated count as qty.
        if ($size === null) {
            return ['mode' => 'count', 'qty' => max(1, $count ?? 1)];
        }

        $soldByWeight = ! empty($ctx['sold_by_weight']);
        $grams        = self::sizeToGrams($size);

        // Sold-by-weight product + a weight size + no competing explicit count → auto weight line.
        // (A stated count alongside a size — "2 x 250g" — is genuinely ambiguous and falls through to clarify.)
        if ($soldByWeight && $grams !== null && $count === null) {
            return ['mode' => 'weight', 'grams' => $grams, 'qty' => 1];
        }

        // Exact pack match: the product name already IS this size (e.g. asked "1kg", SKU is "Sugar 1kg").
        // Then the size just confirms the variant and the count (default 1) is the quantity.
        $skuSize = CatalogueMatcher::skuSize((string) ($ctx['name'] ?? ''));
        if ($skuSize !== null && $skuSize === $size) {
            return ['mode' => 'count', 'qty' => max(1, $count ?? 1)];
        }

        // Exact pack MULTIPLE: "2kg" of a "1kg" pack is 2 whole packs — a safe auto-resolve. Only when
        // the requested amount is an exact integer multiple of the SKU's own pack, in the same unit
        // family, and the customer didn't also state a separate count (which would be ambiguous).
        if ($skuSize !== null && $count === null) {
            $mult = self::packMultiple($size, $skuSize);
            if ($mult !== null) {
                return ['mode' => 'count', 'qty' => $mult];
            }
        }

        // A size we cannot resolve into a whole unit or a priced weight → ask, never guess a quantity.
        // (e.g. "250g" of a 1kg pack, "2kg" of a 5 L jerrycan, or a sizeless SKU asked for by weight.)
        return ['mode' => 'clarify', 'requested' => $size, 'sold_by_weight' => $soldByWeight];
    }

    /**
     * How many whole SKU packs make up the requested size — e.g. "2kg" of a "1kg" pack = 2. Returns
     * null unless both are the same unit family (weight vs volume) and the requested amount is an exact
     * integer multiple of the pack (so "250g" of a 1kg pack, or "2kg" of a 5 L pack, is NOT composed).
     */
    public static function packMultiple(?string $size, ?string $skuSize): ?int
    {
        if ($size === null || $skuSize === null) {
            return null;
        }
        $fam = self::unitFamily($size);
        if ($fam === null || $fam !== self::unitFamily($skuSize)) {
            return null;
        }
        $req  = CatalogueMatcher::sizeMagnitude($size);
        $pack = CatalogueMatcher::sizeMagnitude($skuSize);
        if ($req === null || $pack === null || $pack <= 0) {
            return null;
        }
        $mult = $req / $pack;
        $r    = (int) round($mult);

        return ($r >= 1 && abs($mult - $r) < 1e-9) ? $r : null;
    }

    /** 'w' for a weight size, 'v' for a volume size, null otherwise. */
    private static function unitFamily(string $normSize): ?string
    {
        if (! preg_match('/([a-z]+)$/i', $normSize, $m)) {
            return null;
        }
        $u = strtolower($m[1]);
        if (in_array($u, ['kg', 'g', 'mg'], true)) {
            return 'w';
        }
        if (in_array($u, ['ml', 'cl', 'l'], true)) {
            return 'v';
        }

        return null;
    }

    /**
     * Find the first weight/volume SIZE token in free text and normalise it ("250 g" -> "250g").
     * Count units (pieces/cartons/boxes/packs) are NOT sizes and are deliberately not matched here.
     */
    public static function detectSize(string $text): ?string
    {
        return CatalogueMatcher::normSize($text);
    }

    /**
     * The effective size for one extracted item. In order:
     *  1. a size still visible in the item's own query text;
     *  2. a size the LLM folded into the QUANTITY (query "sugar", qty 250, from "250g sugar");
     *  3. a size sitting directly beside this item's words in the original message even though the
     *     LLM dropped it and set qty to 1 (query "ajinomoto", qty 1, from "250g ajinomoto").
     * Case 3 is what makes the guard hold when the model normalises the qty instead of inflating it.
     */
    public static function sizeForItem(string $itemQuery, int $itemQty, string $originalText = ''): ?string
    {
        $s = self::detectSize($itemQuery);
        if ($s !== null) {
            return $s;
        }
        if ($originalText === '') {
            return null;
        }

        return self::sizeFoldedIntoQty($originalText, $itemQty)
            ?? self::sizeBesideItem($originalText, $itemQuery);
    }

    /**
     * A weight/volume size token sitting immediately before or after this item's words in the message
     * ("250g ajinomoto" / "ajinomoto 250g"). Catches a stated size the LLM stripped from the query
     * without inflating qty, so it can be resolved (weight line / exact pack / clarify) instead of
     * silently dropped. Only adjacency counts, so a size meant for a different item isn't stolen.
     */
    public static function sizeBesideItem(string $text, string $itemQuery): ?string
    {
        $t     = mb_strtolower($text);
        $words = array_values(array_filter(
            preg_split('/\s+/', trim(mb_strtolower($itemQuery))) ?: [],
            fn ($w) => mb_strlen($w) >= 3          // skip tiny/noise tokens
        ));
        if (! $words) {
            return null;
        }
        $units = 'kg|kgs|g|gm|gms|gram|grams|ml|cl|l|lt|ltr|litre|litres|liter|liters';
        $size  = '\d+(?:\.\d+)?\s*(?:' . $units . ')';
        foreach ($words as $w) {
            $wq = preg_quote($w, '/');
            if (preg_match('/(' . $size . ')\b\s+' . $wq . '/i', $t, $m)
             || preg_match('/' . $wq . '\s+(' . $size . ')\b/i', $t, $m)) {
                return self::detectSize($m[1]);
            }
        }

        return null;
    }

    /**
     * If the original message carries a weight/volume size whose raw number equals this qty, the size
     * was almost certainly mistaken for the quantity — return that normalised size so the caller can
     * re-resolve it safely. qty <= 1 can't be an inflated size, so it's never treated as one.
     */
    public static function sizeFoldedIntoQty(string $text, int $qty): ?string
    {
        if ($qty <= 1) {
            return null;
        }
        $units = 'kg|kgs|g|gm|gms|gram|grams|ml|cl|l|lt|ltr|litre|litres|liter|liters';
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(' . $units . ')\b/i', mb_strtolower($text), $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                if ((int) round((float) $m[1]) === $qty) {
                    return self::detectSize($m[0]);
                }
            }
        }

        return null;
    }

    /**
     * Grams for a WEIGHT size ("250g"->250, "2kg"->2000, "1kg"->1000). Volume (ml/l/cl) returns null
     * because it is not a weight and must not be fed to the weight pricer.
     */
    public static function sizeToGrams(?string $size): ?int
    {
        if ($size === null || $size === '') {
            return null;
        }
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*(kg|kgs|g|gm|gms|gram|grams)$/i', trim($size), $m)) {
            return null;
        }
        $n    = (float) $m[1];
        $unit = strtolower($m[2]);
        $grams = in_array($unit, ['kg', 'kgs'], true) ? $n * 1000 : $n;

        return (int) round($grams);
    }
}
