<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — "how much is it?" detector. Pure logic, no framework deps.
 *
 * Fires only for whole-offer PRICE questions with no specific dish named, so it can be answered
 * from the conversation's active offer:
 *   "Ketla ni che?" / "Ketla na?" / "Kitla nu?" / "how much?" / "price?" / "su bhav?" -> true
 *
 * Item counts ("Chapati ketli?") are handled earlier by ItemQueryParser, so the "ketli/kitli"
 * (how-many) stems are deliberately NOT treated as price here.
 */
class PriceQueryParser
{
    private const PRICE_WORDS = ['price', 'rate', 'cost', 'kimat', 'keemat', 'kimmat', 'bhav', 'bhaav', 'mrp', 'amount'];

    /** "how much" stems — note: ketli/kitli (how MANY) are excluded on purpose. */
    private const HOW_MUCH = ['ketla', 'ketlu', 'ketlana', 'kitla', 'kitlu', 'kitna', 'kitne', 'kitni'];

    public static function detect(string $text): bool
    {
        $t = ' ' . trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]+/', ' ', mb_strtolower($text)))) . ' ';
        if ($t === '  ') return false;

        if (str_contains($t, ' how much ') || str_contains($t, ' how much')) return true;

        foreach (array_merge(self::PRICE_WORDS, self::HOW_MUCH) as $w) {
            if (str_contains($t, ' ' . $w . ' ')) return true;
        }
        return false;
    }
}
