<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\DemandPlan;
use App\Models\DemandPlanLine;
use App\Services\Procurement\DemandSuggestion;
use Illuminate\Support\Carbon;

/**
 * Demand Planning & Procurement — the list of plans / procurement requests. A plan starts as a draft
 * worksheet (Ashish), is submitted to become a Procurement Request (Jayesh works it line-by-line), and
 * completes when everything is purchased. Read-only projection; the only write is creating a new plan
 * (+ its auto-suggested lines) — no money or stock moves here.
 */
final class DemandPlanListCapability implements ProcessCapability
{
    private const MAX_ROWS = 300;

    public function key(): string { return 'demand.plans'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        $plans = DemandPlan::where('tenant_id', $t)
            ->withCount('lines')
            ->orderByRaw('plan_date DESC NULLS LAST')->orderByDesc('id')->limit(self::MAX_ROWS)->get();

        // one grouped query for per-plan procurement progress (requested vs purchased)
        $progress = DemandPlanLine::where('tenant_id', $t)
            ->selectRaw('demand_plan_id')
            ->selectRaw('COALESCE(SUM(requested_qty),0) as req')
            ->selectRaw('COALESCE(SUM(LEAST(requested_qty, qty_purchased)),0) as got')
            ->selectRaw("SUM(CASE WHEN line_status IN ('pending','partial') THEN 1 ELSE 0 END) as open_lines")
            ->groupBy('demand_plan_id')->get()->keyBy('demand_plan_id');

        $openPlans = 0; $inProc = 0; $completed = 0; $openLines = 0;
        $rows = [];
        foreach ($plans as $p) {
            $pr = $progress[$p->id] ?? null;
            $req = (float) ($pr->req ?? 0);
            $got = (float) ($pr->got ?? 0);
            $pct = $req > 0 ? (int) round(min(100, max(0, $got / $req * 100))) : 0;
            $openLines += (int) ($pr->open_lines ?? 0);

            $status = (string) $p->status;
            if (in_array($status, ['draft', 'submitted'], true)) { $openPlans++; }
            if ($status === 'in_procurement') { $inProc++; }
            if ($status === 'completed') { $completed++; }

            $pdate = $p->plan_date ? Carbon::parse($p->plan_date) : null;
            $rows[] = [
                'day'      => ['value' => $pdate ? $pdate->format('D, d M') : ($p->created_at ? Carbon::parse($p->created_at)->format('d M Y') : '—'), 'sort' => $pdate ? $pdate->timestamp : ($p->created_at ? Carbon::parse($p->created_at)->timestamp : 0)],
                'plan'     => ['value' => (string) ($p->plan_no ?: ('DP-' . $p->id)), 'sub' => (string) ($p->title ?: ''), 'avatar' => false],
                'transport'=> ['value' => $this->transportLabel((string) $p->transport_mode), 'badge' => $p->transport_mode ? 'accent' : 'default'],
                'shipment' => (string) ($p->shipment_ref ?: '—'),
                'lines'    => ['value' => (string) $p->lines_count, 'sort' => (int) $p->lines_count],
                'progress' => ['value' => $pct . '%', 'sort' => $pct, 'tone' => $pct >= 100 ? 'ok' : ($pct > 0 ? 'warn' : 'default')],
                'status'   => ['value' => $this->statusLabel($status), 'badge' => $this->statusBadge($status)],
                'link'     => '/api/v1/workspace/demandPlan/' . $p->id,
            ];
        }

        $columns = [
            ['key' => 'day',      'label' => 'Day',      'sortable' => true],
            ['key' => 'plan',     'label' => 'Plan',     'type' => 'avatar', 'sortable' => true],
            ['key' => 'transport','label' => 'Transport','type' => 'badge', 'sortable' => true],
            ['key' => 'shipment', 'label' => 'Shipment', 'sortable' => true],
            ['key' => 'lines',    'label' => 'Lines',    'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'progress', 'label' => 'Purchased', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge', 'sortable' => true],
        ];

        return (new WorkspacePayload)
            ->object(['type' => 'list:demand.plans', 'title' => 'Procurement', 'subtitle' => $plans->count() . ' demand plans'])
            ->financials([
                ['key' => 'open', 'label' => 'Open plans', 'value' => $openPlans, 'tone' => $openPlans > 0 ? 'warn' : 'ok', 'foot' => 'draft or submitted'],
                ['key' => 'inproc', 'label' => 'In procurement', 'value' => $inProc, 'tone' => $inProc > 0 ? 'warn' : 'ok', 'foot' => 'being purchased'],
                ['key' => 'openlines', 'label' => 'Lines to buy', 'value' => $openLines, 'tone' => $openLines > 0 ? 'warn' : 'ok', 'foot' => 'pending / partial'],
                ['key' => 'done', 'label' => 'Completed', 'value' => $completed, 'tone' => 'ok', 'foot' => 'fully purchased'],
            ])
            ->table($columns, $rows, [['key' => 'status', 'label' => 'Status']])
            ->actions([[
                'key' => 'create_plan', 'label' => 'New daily request', 'method' => 'POST',
                'endpoint' => '/api/v1/workspace/demand.plans/_/create_plan', 'actor' => 'human', 'tone' => 'primary',
                'schema' => ['plan_date' => 'date', 'transport_mode' => 'enum:bus,lorry', 'cover_days' => 'number', 'title' => 'text', 'shipment_ref' => 'text', 'autofill' => 'enum:Yes — suggest quantities,No — start empty'],
                'labels' => ['plan_date' => 'Dispatch date (YYYY-MM-DD)', 'transport_mode' => 'Sending by', 'cover_days' => 'Days of demand to cover (1 = today only)', 'title' => 'Title (optional — auto from date & transport)', 'shipment_ref' => 'Shipment ref (optional, e.g. SJ-2026-015)', 'autofill' => 'Auto-suggest quantities from stock & sales?'],
                'prefill' => ['plan_date' => now()->format('Y-m-d'), 'transport_mode' => 'bus', 'cover_days' => 1, 'autofill' => 'Yes — suggest quantities'],
            ]])
            ->meta(['capability' => 'demand.plans', 'ttl' => 30, 'permissions' => ['menu:purchases']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $t = $ctx->tenantId();

        return [
            'openPlans' => DemandPlan::where('tenant_id', $t)->whereIn('status', ['draft', 'submitted', 'in_procurement'])->count(),
            'linesToBuy' => DemandPlanLine::where('tenant_id', $t)->whereIn('line_status', ['pending', 'partial'])->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'create_plan' => $this->createPlan($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    private function createPlan(CapabilityContext $ctx, array $in): array
    {
        if (! $ctx->can('menu:purchases')) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $t = $ctx->tenantId();
        $autofill = stripos((string) ($in['autofill'] ?? 'Yes'), 'yes') !== false;

        // Day-wise: dispatch date, transport mode, and how many days of demand to cover.
        $planDate = now();
        $rawDate = trim((string) ($in['plan_date'] ?? ''));
        if ($rawDate !== '') {
            try { $planDate = Carbon::parse($rawDate); } catch (\Throwable $e) { $planDate = now(); }
        }
        $mode = strtolower(trim((string) ($in['transport_mode'] ?? 'bus')));
        $mode = in_array($mode, ['bus', 'lorry'], true) ? $mode : 'bus';
        $coverDays = max(1, (int) round((float) ($in['cover_days'] ?? 1)));

        $plan = new DemandPlan();
        $plan->tenant_id = $t;
        $plan->plan_date = $planDate->toDateString();
        $plan->transport_mode = $mode;
        $plan->cover_days = $coverDays;
        $plan->title = trim((string) ($in['title'] ?? '')) ?: ('Juba — ' . $planDate->format('D d M') . ' (' . $this->transportLabel($mode) . ')');
        $plan->shipment_ref = trim((string) ($in['shipment_ref'] ?? '')) ?: null;
        $plan->status = 'draft';
        $plan->created_by = (string) ($ctx->user->name ?? '');
        $plan->save();
        $plan->plan_no = 'DP-' . $planDate->format('Y') . '-' . str_pad((string) $plan->id, 4, '0', STR_PAD_LEFT);
        $plan->save();

        $added = 0;
        if ($autofill) {
            try {
                foreach (DemandSuggestion::forTenant($ctx->tenant, 30, $coverDays)->suggestLines(60) as $l) {
                    DemandPlanLine::create([
                        'tenant_id'      => $t,
                        'demand_plan_id' => $plan->id,
                        'product_id'     => $l['product_id'] ?? null,
                        'name'           => $l['name'],
                        'current_stock'  => $l['current_stock'],
                        'in_transit'     => $l['in_transit'],
                        'expected_sales' => $l['expected_sales'],
                        'suggested_qty'  => $l['suggested_qty'],
                        'requested_qty'  => $l['requested_qty'],
                        'line_status'    => 'pending',
                    ]);
                    $added++;
                }
            } catch (\Throwable $e) {
                // A suggestion failure must not lose the plan — it's created empty and Ashish adds lines.
            }
        }

        return ['ok' => true, 'id' => $plan->id, 'message' => 'Created ' . $plan->plan_no . ($added ? (' with ' . $added . ' suggested lines.') : '. Add products to it.')];
    }

    private function transportLabel(string $m): string
    {
        return match (strtolower($m)) {
            'bus' => 'Bus',
            'lorry' => 'Lorry',
            default => $m !== '' ? ucfirst($m) : '—',
        };
    }

    private function statusLabel(string $s): string
    {
        return match ($s) {
            'draft' => 'Draft',
            'submitted' => 'Submitted (PR)',
            'in_procurement' => 'In procurement',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst($s ?: 'draft'),
        };
    }

    private function statusBadge(string $s): string
    {
        return match ($s) {
            'completed' => 'ok',
            'in_procurement', 'submitted' => 'warn',
            'cancelled' => 'danger',
            default => 'default',
        };
    }
}
