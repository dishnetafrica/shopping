<?php

namespace App\Services\Bot\Search;

/**
 * Supermarket Search — analytics math. Pure logic, no framework deps.
 *
 * Turns raw event tallies into the rates an owner cares about. Event types tracked elsewhere:
 * search, zero_result, click, add.
 */
class SearchAnalyticsCalc
{
    /** Share of searches that ended in a product being added to cart. */
    public static function conversionRate(int $searches, int $adds): float
    {
        return $searches > 0 ? round($adds / $searches * 100, 1) : 0.0;
    }

    /** Share of searches that returned nothing (a shopping-list / stock gap to fix). */
    public static function zeroResultRate(int $searches, int $zero): float
    {
        return $searches > 0 ? round($zero / $searches * 100, 1) : 0.0;
    }

    /** Share of searches where the customer tapped a result. */
    public static function clickThroughRate(int $searches, int $clicks): float
    {
        return $searches > 0 ? round($clicks / $searches * 100, 1) : 0.0;
    }

    /**
     * Roll a flat list of ['type'=>...] events into a summary with the three rates.
     * @param array<int,array{type:string}> $events
     */
    public static function summarize(array $events): array
    {
        $t = ['search' => 0, 'zero_result' => 0, 'click' => 0, 'add' => 0];
        foreach ($events as $e) {
            $k = (string) ($e['type'] ?? '');
            if (isset($t[$k])) $t[$k]++;
        }
        return [
            'searches'         => $t['search'],
            'zero_results'     => $t['zero_result'],
            'clicks'           => $t['click'],
            'adds'             => $t['add'],
            'conversion_rate'  => self::conversionRate($t['search'], $t['add']),
            'zero_result_rate' => self::zeroResultRate($t['search'], $t['zero_result']),
            'click_rate'       => self::clickThroughRate($t['search'], $t['click']),
        ];
    }
}
