<?php

namespace App\Support;

/**
 * Extracts a minimum-order quantity that lives INSIDE a product name as text — the Family Shoppers
 * catalogue embeds it like "ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON)". Enforcement
 * (OrderCalculator moq_mode=roundup) reads the products.moq COLUMN, so names like these enforce
 * nothing until the number is copied into the column — `php artisan moq:backfill` does that, using
 * this class. Pure function, no framework dependencies (directly harness-testable).
 */
final class MoqPatterns
{
    /**
     * The MOQ embedded in a product name, or null when none is stated.
     *
     * Recognised (case-insensitive): "MIN 5 CARTON" · "MIN. 5" · "MINIMUM 5 CTN" · "MIN ORDER 5" ·
     * "MINIMUM ORDER QTY 5" · "MOQ 5" · "MOQ: 5" · "5 CTN MINIMUM" / "5 CARTONS MIN".
     * NOT matched: bare sizes ("5KG", "45CM", "6PC", "OF 6PC"), counts ("CARTON OF 4PC"), or any
     * number not tied to a minimum word — better to leave the column empty than invent a minimum.
     */
    public static function fromName(string $name): ?int
    {
        $s = ' ' . preg_replace('/\s+/u', ' ', trim($name)) . ' ';

        // Leading form: MIN/MINIMUM/MOQ [order] [qty] <number>
        if (preg_match('/\b(?:moq|min(?:imum)?)\.?\s*(?:order\s*)?(?:qty\s*)?[:\-]?\s*(\d{1,4})\b/iu', $s, $m)) {
            return self::sane((int) $m[1]);
        }

        // Trailing form: <number> [unit-word] MIN/MINIMUM  (e.g. "5 CTN MINIMUM", "10 CARTONS MIN")
        if (preg_match('/\b(\d{1,4})\s*(?:x\s*)?(?:carton|cartons|ctn|ctns|box|boxes|bag|bags|pcs?|pieces?|dozen|packs?|bales?|units?)?\s+min(?:imum)?\b/iu', $s, $m)) {
            return self::sane((int) $m[1]);
        }

        return null;
    }

    /** Guard against garbage captures — a real wholesale MOQ is 2..2000 (1 = no minimum worth storing). */
    private static function sane(int $n): ?int
    {
        return ($n >= 2 && $n <= 2000) ? $n : null;
    }
}
