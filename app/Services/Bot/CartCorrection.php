<?php

namespace App\Services\Bot;

/**
 * Detects a quantity-correction message ("make it 1", "only 1 pkt", "one packet only",
 * "change to 2") and returns the intended quantity. Pure & static — unit-testable.
 *
 * Returns null when the message is not a correction (no cue, or no number), so the
 * caller falls through to normal handling.
 */
class CartCorrection
{
    public static function newQuantity(string $text): ?int
    {
        $lc = mb_strtolower(trim($text));

        // A correction needs an explicit cue — otherwise "2 milk" would look like one.
        if (! preg_match('/\b(change|make it|set it to|set to|update|increase to|reduce to|only|just)\b/', $lc)) {
            return null;
        }

        if (preg_match('/\b(\d{1,3})\b/', $lc, $m)) {
            return max(1, (int) $m[1]);
        }
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
            'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'single' => 1];
        foreach ($words as $w => $v) {
            if (preg_match('/\b' . $w . '\b/', $lc)) return $v;
        }
        return null;
    }
}
