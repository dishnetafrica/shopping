<?php
namespace App\Services\Knowledge\Observatory;

use App\Models\KnowledgeMetric;
use Illuminate\Support\Facades\DB;

/** Read-side dashboards over knowledge_metrics. The numbers that should drive Phase-2 effort. */
class MetricsReport
{
    /** Top phrases the parser didn't understand (unknown/fallback). */
    public function topUnparsed(int $tenantId, int $limit = 20): array
    {
        return KnowledgeMetric::where('tenant_id', $tenantId)
            ->whereIn('result', ['unknown', 'fallback'])
            ->select('sample', DB::raw('COUNT(*) as n'))
            ->groupBy('sample')->orderByDesc('n')->limit($limit)->get()
            ->map(fn ($r) => ['phrase' => $r->sample, 'count' => (int) $r->n])->all();
    }

    /** Average confidence per capability. */
    public function avgConfidenceByCapability(int $tenantId): array
    {
        return KnowledgeMetric::where('tenant_id', $tenantId)->whereNotNull('confidence')
            ->select('capability', DB::raw('AVG(confidence) as c'), DB::raw('COUNT(*) as n'))
            ->groupBy('capability')->get()
            ->mapWithKeys(fn ($r) => [$r->capability ?? 'unknown' => ['avg' => round((float) $r->c, 3), 'n' => (int) $r->n]])->all();
    }

    /** Confirmation acceptance rate (of those that reached a confirm step). */
    public function acceptanceRate(int $tenantId): array
    {
        $total = KnowledgeMetric::where('tenant_id', $tenantId)->whereNotNull('confirmed')->count();
        $yes   = KnowledgeMetric::where('tenant_id', $tenantId)->where('confirmed', true)->count();
        return ['confirmed' => $total, 'accepted' => $yes, 'rate' => $total ? round($yes / $total, 3) : null];
    }

    /** Distribution of result buckets, and unknowns split by language. */
    public function health(int $tenantId): array
    {
        $byResult = KnowledgeMetric::where('tenant_id', $tenantId)
            ->select('result', DB::raw('COUNT(*) as n'))->groupBy('result')->pluck('n', 'result')->all();
        $unknownByLang = KnowledgeMetric::where('tenant_id', $tenantId)->whereIn('result', ['unknown', 'fallback'])
            ->select('language', DB::raw('COUNT(*) as n'))->groupBy('language')->pluck('n', 'language')->all();
        return ['by_result' => $byResult, 'unknown_by_language' => $unknownByLang];
    }
}
