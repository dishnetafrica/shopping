<?php

namespace App\Services\Bot\Search;

/**
 * Supermarket Search — popularity ranking. Pure logic, no framework deps.
 *
 * Ranks candidate rows by, in order: exact match, this customer's history, store popularity,
 * inventory availability. Out-of-stock rows are dropped entirely (never suggested).
 *
 * Row shape: ['id','name','price','stock','popularity'(opt)].
 * ctx:       ['query'=>string, 'history'=>[id=>count], 'popularity'=>[id=>score]]
 */
class SearchRanker
{
    public static function rank(array $rows, array $ctx): array
    {
        $q       = trim(mb_strtolower((string) ($ctx['query'] ?? '')));
        $history = (array) ($ctx['history'] ?? []);
        $popMap  = (array) ($ctx['popularity'] ?? []);

        $scored = [];
        foreach ($rows as $r) {
            if ((int) ($r['stock'] ?? 1) <= 0) continue;            // never suggest out-of-stock

            $id   = (int) ($r['id'] ?? 0);
            $name = trim(mb_strtolower((string) ($r['name'] ?? '')));

            $score = 0.0;
            // 1) exact / prefix match on name
            if ($q !== '' && $name === $q)               $score += 1000;
            elseif ($q !== '' && str_starts_with($name, $q)) $score += 500;
            elseif ($q !== '' && str_contains($name, $q))    $score += 200;

            // 2) this customer's purchase history
            $score += min(300, (int) ($history[$id] ?? 0) * 60);

            // 3) store popularity (global)
            $pop = (float) ($popMap[$id] ?? ($r['popularity'] ?? 0));
            $score += min(150, $pop * 3);

            // 4) inventory availability (a small nudge for healthy stock)
            $score += min(20, max(0, (int) ($r['stock'] ?? 0)));

            $scored[] = ['row' => $r, 'score' => $score];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_map(fn ($s) => $s['row'], $scored);
    }
}
