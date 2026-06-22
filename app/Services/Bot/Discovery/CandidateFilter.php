<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — product-candidate filtering. Pure logic (no framework).
 *
 * The ProductMiner emits `unverified_products` — frequent chat terms that matched NO catalogue
 * product (e.g. an ISP whose customers keep typing "Starlink" but it isn't in the catalogue yet).
 * Those are candidate products the owner can approve into the catalogue. This class decides which
 * candidates are still worth showing: it drops any term that already maps to a real product (so an
 * approved draft never re-appears) and any term the owner already decided on (approved/dismissed).
 *
 * normalize() MUST stay byte-compatible with ProductMiner::keyNorm so a term equal to a product
 * name de-dupes correctly.
 */
class CandidateFilter
{
    /** Lowercase, non-alnum → space, collapse whitespace. Mirrors ProductMiner::keyNorm. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }

    /**
     * @param array $unverified       rows ['term'=>string,'count'=>int] from the discovery report
     * @param array $productNamesNorm  normalized names of products that already exist (active OR draft)
     * @param array $decidedNorm       normalized terms the owner already approved/dismissed
     * @param int   $limit
     * @return list<array{term:string,count:int}> sorted by count desc
     */
    public static function filter(array $unverified, array $productNamesNorm, array $decidedNorm, int $limit = 20): array
    {
        $skip = array_fill_keys(array_values($productNamesNorm), true)
              + array_fill_keys(array_values($decidedNorm), true);

        $seen = [];
        $out  = [];
        foreach ($unverified as $row) {
            $term  = trim((string) ($row['term'] ?? ''));
            $count = (int) ($row['count'] ?? 0);
            if ($term === '') continue;
            $norm = self::normalize($term);
            if ($norm === '' || isset($skip[$norm]) || isset($seen[$norm])) continue;
            $seen[$norm] = true;
            $out[] = ['term' => $term, 'count' => $count];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($out, 0, max(1, $limit));
    }
}
