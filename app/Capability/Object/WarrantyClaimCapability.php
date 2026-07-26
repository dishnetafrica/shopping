<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\Warranty\WarrantyService;
use Illuminate\Support\Carbon;

/**
 * A single warranty claim (Asset Lifecycle · Phase 2 · S7b) — the V3 detail workspace. Shows the claim,
 * whether the warranty is in coverage, and the decision actions (approve / reject / resolve). Writes
 * post to /papi/warranty-claims, which re-enforce owner/manager server-side.
 */
final class WarrantyClaimCapability implements ObjectCapability
{
    public function key(): string { return 'warrantyClaim'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'warrantyClaim'])->meta(['capability' => 'warrantyClaim']);
    }

    private function label(string $s): string
    {
        return ['open' => 'Open', 'approved' => 'Approved', 'rejected' => 'Rejected', 'resolved' => 'Resolved'][$s] ?? ucfirst($s);
    }

    private function tone(string $s): string
    {
        return ['open' => 'warn', 'approved' => 'ok', 'rejected' => 'danger', 'resolved' => 'info'][$s] ?? 'info';
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $c = WarrantyClaim::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $st = (string) $c->status;

        $w = $c->warranty_id ? Warranty::where('tenant_id', $ctx->tenantId())->find($c->warranty_id) : null;
        $coverage = $w ? WarrantyService::effectiveStatus($w) : null;

        $stats = [
            ['key' => 'state', 'label' => 'Status', 'value' => $this->label($st), 'icon' => 'shield', 'tone' => $this->tone($st)],
            ['key' => 'coverage', 'label' => 'Coverage', 'value' => $coverage ? ucfirst($coverage) : '—', 'icon' => 'shield',
                'tone' => $coverage === 'active' ? 'ok' : ($coverage ? 'warn' : 'default')],
        ];

        $answers = [
            ['q' => 'Customer', 'value' => (string) ($c->customer_name ?: '—'), 'sub' => 'Claim'],
            ['q' => 'Phone', 'value' => (string) ($c->customer_phone ?: '—'), 'sub' => 'Claim'],
            ['q' => 'Equipment', 'value' => (string) ($c->serial ?: ($c->product_id ? ('#' . $c->product_id) : '—')), 'sub' => 'Claim'],
            ['q' => 'Warranty', 'value' => $c->warranty_id ? ('#' . $c->warranty_id . ($coverage ? ' · ' . ucfirst($coverage) : '')) : '—', 'sub' => 'Claim'],
            ['q' => 'Fault', 'value' => (string) ($c->fault_description ?: '—'), 'sub' => 'Claim'],
        ];
        if ($st !== 'open') {
            $answers[] = ['q' => 'Resolution', 'value' => (string) ($c->resolution ? ucfirst($c->resolution) : ($st === 'rejected' ? 'Rejected' : '—')), 'sub' => 'Decision'];
            if ((string) $c->resolution_notes !== '') {
                $answers[] = ['q' => 'Notes', 'value' => (string) $c->resolution_notes, 'sub' => 'Decision'];
            }
            $answers[] = ['q' => 'Decided by', 'value' => (string) ($c->decided_by ?: '—')
                . ($c->decided_at ? ' · ' . Carbon::parse($c->decided_at)->format('d M Y') : ''), 'sub' => 'Decision'];
        }

        $base = '/papi/warranty-claims/' . $c->id;
        $actions = [];
        if ($st === 'open') {
            $actions[] = ['key' => 'approve', 'label' => 'Approve', 'method' => 'POST', 'endpoint' => $base . '/approve',
                'actor' => 'human', 'tone' => 'primary',
                'schema' => ['resolution' => 'combo:repair|replace|refund', 'resolution_notes' => 'text'],
                'labels' => ['resolution' => 'Resolution', 'resolution_notes' => 'Notes']];
            $actions[] = ['key' => 'reject', 'label' => 'Reject', 'method' => 'POST', 'endpoint' => $base . '/reject',
                'actor' => 'human', 'tone' => 'danger',
                'schema' => ['resolution_notes' => 'text'], 'labels' => ['resolution_notes' => 'Reason']];
        } elseif ($st === 'approved') {
            $actions[] = ['key' => 'resolve', 'label' => 'Mark resolved', 'method' => 'POST', 'endpoint' => $base . '/resolve',
                'actor' => 'human', 'tone' => 'primary',
                'schema' => ['resolution_notes' => 'text'], 'labels' => ['resolution_notes' => 'Notes (optional)']];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'warrantyClaim', 'id' => (string) $c->id,
                'title' => 'Claim #' . $c->id,
                'subtitle' => (string) ($c->customer_name ?: $c->customer_phone ?: '') . ($c->serial ? ' · ' . $c->serial : ''),
                'status' => $st,
            ])
            ->headline($this->label($st), 'claim', $this->tone($st))
            ->stats($stats)
            ->answers($answers)
            ->actions($actions)
            ->meta(['capability' => 'warrantyClaim', 'ttl' => 30, 'permissions' => []]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
