<?php

namespace App\Services\Bot;

/**
 * Decides whether one category clearly wins a product-word vote.
 *
 * AiBrain::detectCategoryAsk() counts, per category, the products whose name contains a word from
 * the customer's message, and sends that category's catalogue when one category "clearly dominates".
 * It used to compare the top category only with the runner-up. A model/variant word shared across the
 * whole range then picked the wrong section: "i need mini kit 5 pcs how much" matched "Mini" in four
 * mounts and in seven other products spread over five categories, so Mounts (4) beat the runner-up (2)
 * and the customer asking about a kit was sent the Mounts catalogue.
 *
 * The winner must now also hold more than half of ALL matching products, so a word that is spread
 * across the range elects nobody and the caller falls back to the full price list.
 *
 * Pure and static, so it can be tested without the framework.
 */
final class CategoryVote
{
    /** Fewer matching products than this is too weak a signal to pick a section. */
    public const MIN_TOP = 3;

    /**
     * @param array<string,int> $counts category => number of matching products
     */
    public static function winner(array $counts): ?string
    {
        $counts = array_filter(array_map('intval', $counts), fn (int $n) => $n > 0);
        if (! $counts) {
            return null;
        }
        arsort($counts);
        $names = array_keys($counts);
        $top   = $counts[$names[0]];

        if ($top < self::MIN_TOP) {
            return null;
        }
        // No clear winner over the runner-up → don't guess (unchanged rule).
        if (isset($names[1]) && $counts[$names[1]] * 2 > $top) {
            return null;
        }
        // The winner must hold a majority of every match, not just beat second place.
        if ($top * 2 <= array_sum($counts)) {
            return null;
        }

        return (string) $names[0];
    }
}
