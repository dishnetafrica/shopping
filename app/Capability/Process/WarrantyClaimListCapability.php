<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\Warranty\WarrantyClaimService;
use App\Services\Warranty\WarrantyService;

/**
 * Warranty Claims — the V3 face of the claims engine (Asset Lifecycle · Phase 2 · S7b). V3-native.
 * Lists claims by status, with a "New claim" action (pick a warranty). Rows deep-link to the claim
 * detail. Reads open; writes post to /papi/warranty-claims, which re-enforce owner/manager server-side.
 */
final class WarrantyClaimListCapability implements ProcessCapability
{
    public function key(): string { return 'claims'; }

    public function permission(): string { return ''; }

    private function label(string $s): string
    {
        return ['open' => 'Open', 'approved' => 'Approved', 'rejected' => 'Rejected', 'resolved' => 'Resolved'][$s] ?? ucfirst($s);
    }

    private function tone(string $s): string
    {
        return ['open' => 'warn', 'approved' => 'ok', 'rejected' => 'danger', 'resolved' => 'info'][$s] ?? 'info';
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $claims = WarrantyClaimService::forTenant($ctx->tenant)->forFilters([]);

        // Coverage lookup for the listed claims in one query.
        $wids = $claims->pluck('warranty_id')->filter()->unique()->all();
        $warr = $wids ? Warranty::where('tenant_id', $ctx->tenantId())->whereIn('id', $wids)->get()->keyBy('id') : collect();

        $open = 0; $approved = 0; $rejected = 0;
        $rows = [];
        foreach ($claims as $c) {
            $st = (string) $c->status;
            if ($st === 'open')     $open++;
            if ($st === 'approved') $approved++;
            if ($st === 'rejected') $rejected++;

            $w = $c->warranty_id ? $warr->get($c->warranty_id) : null;
            $coverage = $w ? WarrantyService::effectiveStatus($w) : null;

            $rows[] = [
                'claim'    => ['value' => '#' . $c->id, 'sub' => (string) ($c->customer_name ?: $c->customer_phone ?: '—'), 'avatar' => true],
                'item'     => ['value' => (string) ($c->serial ?: ($c->product_id ? ('#' . $c->product_id) : '—'))],
                'fault'    => ['value' => (string) (\Illuminate\Support\Str::limit((string) $c->fault_description, 40) ?: '—')],
                'coverage' => ['value' => $coverage ? ucfirst($coverage) : '—',
                    'badge' => $coverage === 'active' ? 'ok' : ($coverage ? 'warn' : 'default'), 'sort' => (string) $coverage],
                'status'   => ['value' => $this->label($st), 'badge' => $this->tone($st), 'sort' => $st],
                'link'     => '/api/v1/workspace/warrantyClaim/' . $c->id,
            ];
        }

        // "New claim" — pick from recent warranties (the leading id is what the endpoint reads).
        $warranties = Warranty::where('tenant_id', $ctx->tenantId())->orderByDesc('id')->limit(50)
            ->get(['id', 'serial', 'customer_name', 'customer_phone']);
        if ($warranties->isNotEmpty()) {
            $combo = $warranties->map(fn ($w) => $w->id . ' — ' . ($w->serial ?: 'no serial')
                . ' · ' . ($w->customer_name ?: $w->customer_phone ?: '—'))->implode('|');
            $actions = [[
                'key' => 'new_claim', 'label' => 'New claim', 'method' => 'POST', 'endpoint' => '/papi/warranty-claims',
                'actor' => 'human', 'tone' => 'primary',
                'schema' => ['warranty_id' => 'combo:' . $combo, 'fault_description' => 'text'],
                'labels' => ['warranty_id' => 'Warranty', 'fault_description' => 'What went wrong'],
            ]];
        } else {
            $actions = [[
                'key' => 'new_claim', 'label' => 'New claim', 'method' => 'POST', 'endpoint' => '/papi/warranty-claims',
                'actor' => 'human', 'tone' => 'primary',
                'schema' => ['customer_phone' => 'text', 'serial' => 'text', 'fault_description' => 'text'],
                'labels' => ['customer_phone' => 'Customer WhatsApp', 'serial' => 'Serial (optional)', 'fault_description' => 'What went wrong'],
            ]];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:claims', 'title' => 'Warranty Claims',
                'subtitle' => count($rows) . ' claims · ' . $open . ' open',
            ])
            ->financials([
                ['key' => 'open', 'label' => 'Open', 'value' => (string) $open, 'tone' => $open > 0 ? 'warn' : 'ok', 'foot' => 'awaiting a decision'],
                ['key' => 'approved', 'label' => 'Approved', 'value' => (string) $approved, 'tone' => 'ok'],
                ['key' => 'rejected', 'label' => 'Rejected', 'value' => (string) $rejected],
            ])
            ->table(
                [
                    ['key' => 'claim', 'label' => 'Claim', 'type' => 'avatar', 'sortable' => true],
                    ['key' => 'item', 'label' => 'Equipment'],
                    ['key' => 'fault', 'label' => 'Fault'],
                    ['key' => 'coverage', 'label' => 'Coverage', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'sortable' => true],
                ],
                $rows,
                [['key' => 'status', 'label' => 'Status'], ['key' => 'coverage', 'label' => 'Coverage']],
                ['pageSize' => 20])
            ->actions($actions)
            ->meta(['capability' => 'claims', 'ttl' => 30, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['open' => WarrantyClaim::where('tenant_id', $ctx->tenantId())->where('status', 'open')->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
