<?php

namespace App\BOS\Projections;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Quotation;

/**
 * Today (canonical type: Today) — the day's work for the signed-in tenant: a greeting, four KPI tiles,
 * and the ranked activity list (Collections for now; follow-ups/deliveries added as those slices land).
 * Pure read; every figure traces to a real object query — no invented numbers (R23, ADR-006).
 * Today is NOT a new data source — it groups the same facts the timeline is built from.
 */
class DailyToday extends TodayProjection
{
    public function __construct(private int $tenantId) {}

    public function build(): array
    {
        $today = now()->toDateString();

        $toCollect = (float) Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereIn('status', ['issued', 'part_paid'])->sum('balance');
        $overdue = (int) Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->where('balance', '>', 0.5)->whereNotNull('due_at')->where('due_at', '<', $today)->count();
        $quotes = (int) Quotation::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereRaw("lower(coalesce(status,'')) in ('sent','accepted','pending')")->whereNull('order_id')->count();
        $collectedToday = (float) CustomerPayment::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->where('created_at', '>=', now()->startOfDay())->sum('usd_amount');

        $activities = (new CollectionsActivities($this->tenantId))->build();

        $target = round($toCollect + $collectedToday, 2);

        return [
            'greeting' => $this->greeting(),
            'date'     => now()->format('l, d M'),
            // Day-progress for the hero: remaining, collected today, and the day's starting target.
            'progress' => [
                'remaining' => (int) round($toCollect),
                'collected' => (int) round($collectedToday),
                'total'     => (int) round($target),
                'pct'       => $target > 0.5 ? min(100, (int) round($collectedToday / $target * 100)) : 0,
                'currency'  => 'USD',
            ],
            'overdue'  => $overdue,
            'quotes'   => $quotes,
            'kpis'     => [
                ['label' => 'To Collect',      'value' => 'USD ' . number_format($toCollect, 0), 'tone' => $toCollect > 0 ? 'warn' : 'ok'],
                ['label' => 'Overdue',         'value' => (string) $overdue,                     'tone' => $overdue > 0 ? 'danger' : 'ok'],
                ['label' => 'Quotes Waiting',  'value' => (string) $quotes,                      'tone' => 'default'],
                ['label' => 'Collected Today', 'value' => 'USD ' . number_format($collectedToday, 0), 'tone' => 'ok'],
            ],
            'activities' => array_slice($activities, 0, 20),
        ];
    }

    private function greeting(): string
    {
        $h = (int) now()->format('G');
        return $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
    }
}
