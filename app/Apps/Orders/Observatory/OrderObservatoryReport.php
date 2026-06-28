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

    /** Customer delivery-confirmation metrics (the pull-based receipt signal) for a day. */
    public function deliveryConfirmation(int $tenantId, ?string $date = null): array
    {
        $date ??= date('Y-m-d', strtotime('yesterday'));
        $base = \App\Models\Order::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereDate('delivered_at', $date);

        $delivered = (clone $base)->count();
        $confirmed = (clone $base)->whereNotNull('customer_confirmed_at')->count();
        $reminded  = (clone $base)->whereNotNull('confirm_reminder_at')->count();
        $rated     = (clone $base)->whereNotNull('rating');

        $latencies = (clone $base)->whereNotNull('customer_confirmed_at')->get(['delivered_at', 'customer_confirmed_at'])
            ->map(fn ($o) => max(0, $o->customer_confirmed_at->getTimestamp() - $o->delivered_at->getTimestamp()))->all();
        $avgMin = $latencies ? round(array_sum($latencies) / count($latencies) / 60, 1) : null;

        return [
            'delivered' => $delivered, 'confirmed' => $confirmed,
            'confirmation_pct' => $delivered ? round(100 * $confirmed / $delivered) : null,
            'avg_confirm_latency_min' => $avgMin,
            'reminder_required' => $reminded,
            'avg_rating' => (clone $rated)->avg('rating') ? round((float) (clone $rated)->avg('rating'), 2) : null,
            'ratings' => (clone $rated)->count(),
        ];
    }

    /** Orders delivered but never confirmed by the customer — a follow-up / service-quality signal. */
    public function unconfirmedDeliveries(int $tenantId, int $olderThanHours = 24): int
    {
        return \App\Models\Order::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereNotNull('delivered_at')->whereNull('customer_confirmed_at')
            ->where('delivered_at', '<=', now()->subHours($olderThanHours))->count();
    }

    /** Seller data-discipline: how often staff had to be nudged, and how often the customer closed the loop. */
    public function sellerDiscipline(int $tenantId, ?string $date = null): array
    {
        $date ??= date('Y-m-d', strtotime('yesterday'));
        $base = \App\Models\Order::withoutGlobalScopes()->where('tenant_id', $tenantId);
        return [
            'stale_nudges' => (clone $base)->whereDate('stale_nudged_at', $date)->count(),
            'self_closed'  => (clone $base)->whereDate('delivered_at', $date)->where('delivered_by', 'customer')->count(),
            'stuck_now'    => (clone $base)->where('stale_level', '>', 0)
                ->whereNotIn('status', ['Delivered', 'Customer confirmed', 'Cancelled', 'Rejected'])->count(),
        ];
    }

    /** Who completed orders — seller vs customer — the intuitive process-quality KPI. */
    public function completionBreakdown(int $tenantId, ?string $date = null): array
    {
        $date ??= date('Y-m-d', strtotime('yesterday'));
        $rows = \App\Models\Order::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereDate('delivered_at', $date)->orWhereDate('customer_confirmed_at', $date))
            ->get(['delivered_at', 'customer_confirmed_at']);

        $seller = 0; $customer = 0;
        foreach ($rows as $o) {
            $by = $o->completedBy();
            if ($by === 'seller') $seller++;
            elseif ($by === 'customer') $customer++;
        }
        $total = $seller + $customer;
        return [
            'total' => $total, 'seller' => $seller, 'customer' => $customer,
            'seller_pct' => $total ? round(100 * $seller / $total) : null,
            'customer_pct' => $total ? round(100 * $customer / $total) : null,
        ];
    }

    /** Avg minutes the seller took to mark Delivered AFTER the customer had already confirmed. */
    public function sellerResponseTime(int $tenantId, ?string $date = null): ?float
    {
        $date ??= date('Y-m-d', strtotime('yesterday'));
        $rows = \App\Models\Order::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereNotNull('customer_confirmed_at')->whereNotNull('delivered_at')
            ->whereColumn('customer_confirmed_at', '<', 'delivered_at')
            ->whereDate('delivered_at', $date)
            ->get(['customer_confirmed_at', 'delivered_at']);
        if ($rows->isEmpty()) return null;
        $mins = $rows->map(fn ($o) => max(0, $o->delivered_at->getTimestamp() - $o->customer_confirmed_at->getTimestamp()) / 60);
        return round($mins->avg(), 1);
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


    /** Confidence Ladder breakdown — your engineering Kanban (unexplained = observed+reproduced). */
    public function ladderBreakdown(int $tenantId, ?string $date = null): array
    {
        $q = \App\Models\ShadowDiff::where('tenant_id', $tenantId);
        if ($date) $q->whereDate('created_at', $date);
        $counts = $q->select('stage', DB::raw('COUNT(*) as n'))->groupBy('stage')->pluck('n', 'stage')->all();
        $unexplained = ($counts['observed'] ?? 0) + ($counts['reproduced'] ?? 0);
        return ['by_stage' => $counts, 'unexplained' => $unexplained];
    }

    /** BUSINESS metrics (kept separate from engineering): completion, clarification turns, latency. */
    public function businessMetrics(int $tenantId, ?string $date = null): array
    {
        $date ??= date('Y-m-d', strtotime('yesterday'));
        $rows = OrderFlowMetric::where('tenant_id', $tenantId)->whereDate('created_at', $date)
            ->whereNotNull('conversation_id')
            ->orderBy('conversation_id')->orderBy('created_at')
            ->get(['conversation_id', 'draft_parsed', 'clarification', 'outcome', 'created_at']);

        $byConv = [];
        foreach ($rows as $r) { $byConv[$r->conversation_id][] = $r; }

        $orders = 0; $completed = 0; $clarTurns = []; $latencies = []; $interventions = 0;
        foreach ($byConv as $msgs) {
            $orderMsgs = array_values(array_filter($msgs, fn ($m) => $m->draft_parsed));
            if (! $orderMsgs) continue;
            $orders++;
            $clar = count(array_filter($msgs, fn ($m) => $m->clarification));
            $clarTurns[] = $clar;
            if ($clar > 0) $interventions++;
            $checkout = array_values(array_filter($msgs, fn ($m) => $m->outcome === 'checkout'));
            if ($checkout) {
                $completed++;
                $start = $orderMsgs[0]->created_at; $end = end($checkout)->created_at;
                if ($start && $end) $latencies[] = max(0, $end->getTimestamp() - $start->getTimestamp());
            }
        }
        $avg = fn ($a) => $a ? round(array_sum($a) / count($a), 1) : null;
        return [
            'orders' => $orders, 'completed' => $completed, 'abandoned' => $orders - $completed,
            'avg_clarification_turns' => $avg($clarTurns),
            'avg_time_to_confirmation_s' => $avg($latencies),
            'manual_interventions' => $interventions,
        ];
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
        $ladder = $this->ladderBreakdown($tenantId, $date);
        $biz = $this->businessMetrics($tenantId, $date);
        $dc = $this->deliveryConfirmation($tenantId, $date);
        $sd = $this->sellerDiscipline($tenantId, $date);
        $cb = $this->completionBreakdown($tenantId, $date);
        $srt = $this->sellerResponseTime($tenantId, $date);
        $intents = implode(' · ', array_map(fn ($i, $n) => "$i $n", array_keys($k['by_intent']), $k['by_intent']));
        $reasonLine = $reasons ? implode(' · ', array_map(fn ($r, $n) => "$r $n", array_keys($reasons), $reasons)) : 'none';
        $stageLine = $ladder['by_stage'] ? implode(' · ', array_map(fn ($st, $n) => "$st $n", array_keys($ladder['by_stage']), $ladder['by_stage'])) : 'none';

        return "OKE Order Observatory — {$k['date']} (tenant {$tenantId})\n"
            . "ENGINEERING\n"
            . "  messages {$k['messages']} · orders {$k['orders']} · parser-success " . ($k['parser_success_pct'] ?? '—') . "%\n"
            . "  intents: {$intents}\n"
            . "  disagreement reasons: {$reasonLine}\n"
            . "  confidence ladder: {$stageLine}  (unexplained {$ladder['unexplained']})\n"
            . "BUSINESS\n"
            . "  orders completed {$biz['completed']}/{$biz['orders']} · abandoned {$biz['abandoned']}\n"
            . "  clarification turns/order " . ($biz['avg_clarification_turns'] ?? '—')
            . " · time-to-confirmation " . ($biz['avg_time_to_confirmation_s'] ?? '—') . "s"
            . " · manual interventions {$biz['manual_interventions']}\n"
            . "  delivery confirmed " . ($dc['confirmation_pct'] ?? '—') . "% ({$dc['confirmed']}/{$dc['delivered']})"
            . " · confirm-latency " . ($dc['avg_confirm_latency_min'] ?? '—') . "min"
            . " · avg rating " . ($dc['avg_rating'] ?? '—') . " (" . $dc['ratings'] . ")\n"
            . "  seller discipline: stale-nudges {$sd['stale_nudges']} · self-closed {$sd['self_closed']} · stuck now {$sd['stuck_now']}\n"
            . "  completed by: seller " . ($cb['seller_pct'] ?? '—') . "% · customer " . ($cb['customer_pct'] ?? '—') . "%"
            . " · seller catch-up " . ($srt ?? '—') . "min\n"
            . "  intervention avoided: " . ($ia['pct'] ?? '—') . "% ({$ia['no_intervention']}/{$ia['orders']})";
    }
}
