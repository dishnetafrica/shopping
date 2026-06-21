<?php

namespace App\Services\Bot\Search;

use App\Models\SearchEvent;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Supermarket Search — analytics recorder. Writes search_events and summarizes them.
 */
class SearchAnalytics
{
    public function record(Tenant $tenant, string $type, string $query = '', int $results = 0, ?int $productId = null): void
    {
        try {
            SearchEvent::create([
                'type'       => $type,
                'query'      => $query !== '' ? mb_substr($query, 0, 180) : null,
                'results'    => $results,
                'product_id' => $productId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // analytics are best-effort
        }
    }

    /** Rates + the worst zero-result queries for the last N days. */
    public function summary(Tenant $tenant, int $days = 7): array
    {
        $since = Carbon::now()->subDays(max(1, $days));

        $counts = SearchEvent::where('created_at', '>=', $since)
            ->selectRaw('type, count(*) as c')->groupBy('type')->pluck('c', 'type')->all();

        $searches = (int) ($counts['search'] ?? 0);
        $zero     = (int) ($counts['zero_result'] ?? 0);
        $clicks   = (int) ($counts['click'] ?? 0);
        $adds     = (int) ($counts['add'] ?? 0);

        $topZero = SearchEvent::where('created_at', '>=', $since)->where('type', 'zero_result')
            ->selectRaw('query, count(*) as c')->whereNotNull('query')
            ->groupBy('query')->orderByDesc('c')->limit(10)->pluck('c', 'query')->all();

        return [
            'searches'         => $searches,
            'zero_results'     => $zero,
            'clicks'           => $clicks,
            'adds'             => $adds,
            'conversion_rate'  => SearchAnalyticsCalc::conversionRate($searches, $adds),
            'zero_result_rate' => SearchAnalyticsCalc::zeroResultRate($searches, $zero),
            'click_rate'       => SearchAnalyticsCalc::clickThroughRate($searches, $clicks),
            'top_zero_result'  => $topZero,
        ];
    }
}
