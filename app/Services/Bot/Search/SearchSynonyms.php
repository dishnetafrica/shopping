<?php

namespace App\Services\Bot\Search;

/**
 * Supermarket Search — synonym groups. Pure logic, no framework deps.
 *
 * Bidirectional grocery synonyms across English / Swahili / Gujlish so "chawal", "sukari" and
 * "oil" all reach the right shelf. Two uses:
 *   expand()         — widen a query for the DB fallback (rice -> rice, chawal, bhaat, mchele)
 *   meiliSynonyms()  — the synonyms map pushed to the Meilisearch index settings
 *
 * Owners can extend groups later; this is a starter set tuned for East-African grocery.
 */
class SearchSynonyms
{
    /** @var array<int,string[]> each row is a set of equivalent terms */
    public const GROUPS = [
        ['rice', 'chawal', 'bhaat', 'mchele'],
        ['sugar', 'sukari', 'chini', 'sakar'],
        ['cooking oil', 'oil', 'tel', 'mafuta'],
        ['salt', 'namak', 'chumvi'],
        ['wheat flour', 'flour', 'atta', 'maida'],
        ['maize flour', 'posho', 'sembe', 'unga'],
        ['milk', 'maziwa', 'doodh', 'dudh'],
        ['tea', 'chai'],
        ['soap', 'sabuni', 'sabun'],
        ['beans', 'maharage', 'rajma'],
        ['eggs', 'egg', 'mayai', 'anda'],
        ['bread', 'mkate'],
        ['water', 'maji', 'pani'],
        ['onion', 'onions', 'kitunguu', 'dungri'],
        ['tomato', 'tomatoes', 'nyanya', 'tameta', 'tamatar'],
        ['potato', 'potatoes', 'viazi', 'bateta', 'aloo'],
        ['soda', 'soft drink'],
    ];

    /** Original query terms plus any synonyms of groups it touches (deduped, lowercased). */
    public static function expand(string $query): array
    {
        $q = trim(mb_strtolower($query));
        if ($q === '') return [];

        $out = [$q];
        foreach (self::GROUPS as $group) {
            foreach ($group as $term) {
                if (self::contains($q, $term)) {
                    foreach ($group as $syn) $out[$syn] = $syn;
                    break;
                }
            }
        }
        return array_values(array_unique(array_merge([$q], array_keys(array_filter($out, fn ($v) => $v !== $q && is_string($v))))));
    }

    /** Meilisearch synonyms setting: term => list of equivalents. */
    public static function meiliSynonyms(): array
    {
        $map = [];
        foreach (self::GROUPS as $group) {
            foreach ($group as $term) {
                $map[$term] = array_values(array_filter($group, fn ($x) => $x !== $term));
            }
        }
        return $map;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        if (str_contains($needle, ' ')) return str_contains($haystack, $needle);
        return (bool) preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $haystack);
    }
}
