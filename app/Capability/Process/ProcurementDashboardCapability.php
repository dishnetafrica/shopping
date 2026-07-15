<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\DemandPlan;
use App\Models\DemandPlanLine;
use App\Models\Shipment;
use Illuminate\Support\Carbon;

/**
 * Procurement Hub — the one board tying demand plans, pending purchases and in-transit shipments
 * together (the "eliminate WhatsApp coordination" dashboard). Read-only projection over the Phase-1
 * demand plans + the existing Shipment platform. No writes.
 */
final class ProcurementDashboardCapability implements ProcessCapability
{
    public function key(): string { return 'procurement.hub'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        // --- demand plans / procurement requests ---
        $plans = DemandPlan::where('tenant_id', $t)->orderByDesc('id')->limit(200)->get();
        $openStatuses = ['draft', 'submitted', 'in_procurement'];
        $openPlanIds = $plans->whereIn('status', $openStatuses)->pluck('id')->all();

        $lineAgg = DemandPlanLine::where('tenant_id', $t)
            ->when(count($openPlanIds) > 0, fn ($q) => $q->whereIn('demand_plan_id', $openPlanIds))
            ->when(count($openPlanIds) === 0, fn ($q) => $q->whereRaw('1=0'))
            ->selectRaw('demand_plan_id')
            ->selectRaw('COALESCE(SUM(requested_qty),0) as req')
            ->selectRaw('COALESCE(SUM(LEAST(requested_qty, qty_purchased)),0) as got')
            ->selectRaw("SUM(CASE WHEN line_status='pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN line_status='partial' THEN 1 ELSE 0 END) as partial")
            ->selectRaw("SUM(CASE WHEN line_status='not_available' THEN 1 ELSE 0 END) as notavail")
            ->selectRaw('COUNT(*) as lines')
            ->groupBy('demand_plan_id')->get()->keyBy('demand_plan_id');

        $openPRs = count($openPlanIds);
        $pending = 0; $partial = 0; $notAvail = 0; $sumReq = 0.0; $sumGot = 0.0;
        foreach ($lineAgg as $a) {
            $pending += (int) $a->pending;
            $partial += (int) $a->partial;
            $notAvail += (int) $a->notavail;
            $sumReq += (float) $a->req;
            $sumGot += (float) $a->got;
        }
        $procPct = $sumReq > 0 ? (int) round(min(100, max(0, $sumGot / $sumReq * 100))) : 0;

        $prRows = [];
        foreach ($plans->whereIn('status', $openStatuses)->take(50) as $p) {
            $a = $lineAgg[$p->id] ?? null;
            $req = (float) ($a->req ?? 0);
            $got = (float) ($a->got ?? 0);
            $pct = $req > 0 ? (int) round(min(100, max(0, $got / $req * 100))) : 0;
            $toBuy = (int) (($a->pending ?? 0) + ($a->partial ?? 0));
            $prRows[] = ['cells' => [
                ['value' => (string) ($p->plan_no ?: ('DP-' . $p->id))],
                ['value' => (string) ($p->title ?: '—')],
                ['value' => (string) ($p->shipment_ref ?: '—')],
                ['value' => (string) (int) ($a->lines ?? 0)],
                ['value' => $toBuy > 0 ? (string) $toBuy : '—', 'tone' => $toBuy > 0 ? 'warn' : null],
                ['value' => $pct . '%', 'tone' => $pct >= 100 ? 'ok' : ($pct > 0 ? 'warn' : null)],
                ['value' => $this->planLabel((string) $p->status), 'badge' => $this->planBadge((string) $p->status)],
            ], 'link' => '/api/v1/workspace/demandPlan/' . $p->id];
        }

        // --- shipments (Kampala → Juba leg) ---
        $ships = Shipment::where('tenant_id', $t)
            ->whereNull('cancelled_at')->whereNull('arrived_at')
            ->orderByDesc('id')->limit(100)->get();

        $preparing = 0; $inTransit = 0; $boxesInTransit = 0; $sentBoxes = 0; $recvBoxes = 0;
        $shipRows = [];
        foreach ($ships as $s) {
            $transit = $s->departed_at !== null;
            if ($transit) { $inTransit++; $boxesInTransit += (int) ($s->boxes_sent ?? 0); }
            else { $preparing++; }
            $sentBoxes += (int) ($s->boxes_sent ?? 0);
            $recvBoxes += (int) ($s->boxes_received ?? 0);

            $shipRows[] = ['cells' => [
                ['value' => (string) ($s->shipment_number ?: ('SH-' . $s->id))],
                ['value' => (string) ($s->transport_company ?: '—')],
                ['value' => (string) ($s->destination_city ?: 'Juba')],
                ['value' => $transit ? 'In transit' : $this->prepLabel($s), 'badge' => $transit ? 'warn' : 'default'],
                ['value' => (int) ($s->boxes_sent ?? 0) . ($transit && $s->boxes_received ? (' · ' . (int) $s->boxes_received . ' recv') : '')],
                ['value' => $s->departed_at ? Carbon::parse($s->departed_at)->format('d M') : '—'],
            ]];
        }
        $shipPct = $sentBoxes > 0 ? (int) round(min(100, max(0, $recvBoxes / $sentBoxes * 100))) : 0;

        $prCols = [
            ['label' => 'Request'],
            ['label' => 'Title'],
            ['label' => 'Shipment'],
            ['label' => 'Lines', 'align' => 'right'],
            ['label' => 'To buy', 'align' => 'right'],
            ['label' => 'Purchased', 'align' => 'right'],
            ['label' => 'Status'],
        ];
        $shipCols = [
            ['label' => 'Shipment'],
            ['label' => 'Transport'],
            ['label' => 'Destination'],
            ['label' => 'Status'],
            ['label' => 'Boxes', 'align' => 'right'],
            ['label' => 'Departed', 'align' => 'right'],
        ];

        return (new WorkspacePayload)
            ->object(['type' => 'process:procurement.hub', 'title' => 'Procurement Hub', 'subtitle' => $openPRs . ' open requests · ' . $inTransit . ' in transit'])
            ->headline($procPct . '% purchased', $pending + $partial . ' lines still to buy', $procPct >= 100 ? 'ok' : (($pending + $partial) > 0 ? 'warn' : 'ok'))
            ->financials([
                ['key' => 'prs', 'label' => 'Open requests', 'value' => $openPRs, 'tone' => $openPRs > 0 ? 'warn' : 'ok', 'foot' => 'demand plans / PRs'],
                ['key' => 'pending', 'label' => 'Pending purchases', 'value' => $pending, 'tone' => $pending > 0 ? 'warn' : 'ok', 'foot' => 'lines not started'],
                ['key' => 'partial', 'label' => 'Partially purchased', 'value' => $partial, 'tone' => $partial > 0 ? 'warn' : 'ok', 'foot' => 'lines part-bought'],
                ['key' => 'notavail', 'label' => 'Not available', 'value' => $notAvail, 'tone' => $notAvail > 0 ? 'danger' : 'ok', 'foot' => 'sourcing gaps'],
                ['key' => 'procpct', 'label' => 'Procurement %', 'value' => $procPct . '%', 'tone' => $procPct >= 100 ? 'ok' : 'warn', 'foot' => 'across open requests'],
                ['key' => 'prep', 'label' => 'Preparing', 'value' => $preparing, 'foot' => 'shipments being packed'],
                ['key' => 'transit', 'label' => 'In transit', 'value' => $inTransit, 'tone' => $inTransit > 0 ? 'warn' : 'ok', 'foot' => $boxesInTransit . ' boxes to Juba'],
                ['key' => 'shippct', 'label' => 'Shipment %', 'value' => $shipPct . '%', 'foot' => 'boxes received vs sent'],
            ])
            ->tabs([
                ['key' => 'requests', 'label' => 'Open requests', 'count' => count($prRows)],
                ['key' => 'shipments', 'label' => 'Shipments', 'count' => count($shipRows)],
            ])
            ->tabSections([
                'requests' => [[
                    'kind' => 'table', 'title' => 'Open procurement requests', 'sub' => 'plans still being purchased',
                    'searchable' => true, 'columns' => $prCols, 'rows' => $prRows,
                    'empty' => 'No open requests — create a demand plan under Procurement → Demand Plans.',
                ]],
                'shipments' => [[
                    'kind' => 'table', 'title' => 'Shipments preparing / in transit', 'sub' => 'Kampala → Juba',
                    'searchable' => true, 'columns' => $shipCols, 'rows' => $shipRows,
                    'empty' => 'No shipments being prepared or in transit right now.',
                ]],
            ])
            ->meta(['capability' => 'procurement.hub', 'ttl' => 30, 'layout' => 'record', 'permissions' => ['menu:purchases']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $t = $ctx->tenantId();

        return [
            'openRequests' => DemandPlan::where('tenant_id', $t)->whereIn('status', ['draft', 'submitted', 'in_procurement'])->count(),
            'inTransit' => Shipment::where('tenant_id', $t)->whereNull('cancelled_at')->whereNull('arrived_at')->whereNotNull('departed_at')->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    private function prepLabel(Shipment $s): string
    {
        if ($s->transport_confirmed_at !== null) { return 'Loaded'; }
        if ($s->sent_at !== null) { return 'Ready'; }

        return 'Preparing';
    }

    private function planLabel(string $s): string
    {
        return match ($s) {
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'in_procurement' => 'In procurement',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst($s ?: 'draft'),
        };
    }

    private function planBadge(string $s): string
    {
        return match ($s) {
            'completed' => 'ok',
            'in_procurement', 'submitted' => 'warn',
            'cancelled' => 'danger',
            default => 'default',
        };
    }
}
