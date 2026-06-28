<?php
namespace App\Apps\Orders\Observatory;

use App\Models\OrderFlowMetric;
use App\Models\ResolutionClarification;
use Illuminate\Support\Facades\DB;

/** The daily KPI line + the per-query clarification table that shows WHERE aliases cost the most. */
class OrderObservatoryReport
{
    public function kpi(int $tenantId, ?string $date = null): array
    {
        $date ??= date('Y-m-d', strtotime('yesterday'));
        $base = OrderFlowMetric::where('tenant_id', $tenantId)->whereDate('created_at', $date);

        $total   = (clone $base)->count();
        $orders  = (clone $base)->where('draft_parsed', true)->count();
        $parsed  = (clone $base)->where('draft_parsed', true)->where('products_found', '>', 0)->count();
        $clarify = (clone $base)->where('clarification', true)->count();
        $byIntent = (clone $base)->select('intent', DB::raw('COUNT(*) as n'))->groupBy('intent')->pluck('n', 'intent')->all();

        return [
            'date' => $date, 'messages' => $total, 'orders' => $orders,
            'parser_success_pct' => $orders ? round(100 * $parsed / $orders) : null,
            'clarification_pct'  => $total ? round(100 * $clarify / $total) : null,
            'by_intent' => $byIntent,
        ];
    }

    /** Disagreement reasons over a day — tells you exactly where engineering time belongs. */
    public function reasonBreakdown(int $tenantId, ?string $date = null): array
    {
        $date ??= date('Y-m-d', strtotime('yesterday'));
        $rows = \App\Models\ShadowDiff::where('tenant_id', $tenantId)->whereDate('created_at', $date)->pluck('reasons');
        $counts = [];
        foreach ($rows as $csv) {
            foreach (array_filter(explode(',', (string) $csv)) as $reason) $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    /** The business metric: orders handled with NO clarification and a clean parse. */
    public function interventionAvoided(int $tenantId, ?string $date = null): array
    {
        $date ??= date('Y-m-d', strtotime('yesterday'));
        $base = OrderFlowMetric::where('tenant_id', $tenantId)->whereDate('created_at', $date)->where('draft_parsed', true);
        $orders = (clone $base)->count();
        $clean  = (clone $base)->where('clarification', false)->where('products_found', '>', 0)->count();
        return ['orders' => $orders, 'no_intervention' => $clean, 'pct' => $orders ? round(100 * $clean / $orders) : null];
    }

    /** Query → clarification count (rate becomes available once the resolver logs all resolutions). */
    public function aliasCost(int $tenantId, int $limit = 20): array
    {
        return ResolutionClarification::where('tenant_id', $tenantId)
            ->select(DB::raw('LOWER(query) as q'), DB::raw('COUNT(*) as n'),
                     DB::raw('SUM(CASE WHEN chosen_product_id IS NULL THEN 1 ELSE 0 END) as unresolved'))
            ->groupBy('q')->orderByDesc('n')->limit($limit)->get()
            ->map(fn ($r) => ['query' => $r->q, 'count' => (int) $r->n, 'unresolved' => (int) $r->unresolved])->all();
    }

    public function render(int $tenantId, ?string $date = null): string
    {
        $k = $this->kpi($tenantId, $date);
        $ia = $this->interventionAvoided($tenantId, $date);
        $reasons = $this->reasonBreakdown($tenantId, $date);
        $intents = implode(' · ', array_map(fn ($i, $n) => "$i $n", array_keys($k['by_intent']), $k['by_intent']));
        $reasonLine = $reasons ? implode(' · ', array_map(fn ($r, $n) => "$r $n", array_keys($reasons), $reasons)) : 'none';
        return "OKE Order Observatory — {$k['date']} (tenant {$tenantId})\n"
            . "  messages {$k['messages']} · orders {$k['orders']} · parser-success "
            . ($k['parser_success_pct'] ?? '—') . "% · clarifications " . ($k['clarification_pct'] ?? '—') . "%\n"
            . "  intervention avoided: " . ($ia['pct'] ?? '—') . "% ({$ia['no_intervention']}/{$ia['orders']} orders)\n"
            . "  intents: {$intents}\n"
            . "  disagreement reasons: {$reasonLine}";
    }
}
