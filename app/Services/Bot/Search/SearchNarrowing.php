<?php

namespace App\Services\Bot\Search;

/**
 * Supermarket Search — category discovery. Pure logic, no framework deps.
 *
 * When a search returns more than a threshold (default 20) of products, don't dump a long list —
 * ask one narrowing question. This picks the dimension that best splits the result set
 * (category > brand > price tier) and returns 2-6 tappable options.
 */
class SearchNarrowing
{
    public const THRESHOLD = 20;

    public static function shouldNarrow(int $count, int $threshold = self::THRESHOLD): bool
    {
        return $count > $threshold;
    }

    /**
     * Build a narrowing question from result rows. Rows are assoc arrays that may carry
     * 'category', 'brand', 'price'. Returns ['dimension','question','options'] or null.
     */
    public static function facets(array $rows, int $max = 4): ?array
    {
        if (count($rows) < 2) return null;

        foreach (['category', 'brand'] as $dim) {
            $opts = self::topValues($rows, $dim, $max);
            if (count($opts) >= 2) {
                return [
                    'dimension' => $dim,
                    'question'  => $dim === 'brand' ? 'Which brand?' : 'Which type?',
                    'options'   => $opts,
                ];
            }
        }

        // Fallback: price tiers (Budget / Daily Use / Premium) from the price spread.
        $tiers = self::priceTiers($rows);
        if (count($tiers) >= 2) {
            return ['dimension' => 'price', 'question' => 'Which range?', 'options' => $tiers];
        }

        return null;
    }

    /** Distinct non-empty values of a field, ordered by frequency, capped at $max. */
    private static function topValues(array $rows, string $field, int $max): array
    {
        $counts = [];
        foreach ($rows as $r) {
            $v = trim((string) ($r[$field] ?? ''));
            if ($v === '') continue;
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }
        if (! $counts) return [];
        arsort($counts);
        // a dimension is only useful if it actually divides the set (not all in one bucket)
        if (count($counts) < 2) return [];
        return array_slice(array_keys($counts), 0, $max);
    }

    private static function priceTiers(array $rows): array
    {
        $prices = array_values(array_filter(array_map(fn ($r) => (float) ($r['price'] ?? 0), $rows), fn ($p) => $p > 0));
        if (count($prices) < 2) return [];
        sort($prices);
        $min = $prices[0];
        $max = $prices[count($prices) - 1];
        if ($max <= $min) return [];

        return ['Budget', 'Daily Use', 'Premium'];
    }

    /** Map a chosen option back to a filter for the next search pass. */
    public static function filterFor(string $dimension, string $option): array
    {
        if ($dimension === 'price') return ['price_tier' => $option];
        return [$dimension => $option];
    }
}
