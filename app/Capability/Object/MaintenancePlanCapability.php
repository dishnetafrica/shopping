<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\MaintenancePlan;
use Illuminate\Support\Carbon;

/**
 * A single maintenance plan (Asset Lifecycle · Phase 2 · S6b) — the V3 detail workspace. Shows the
 * plan, its due state, and the actions (log a visit, pause, resume, end). Writes post to
 * /papi/maintenance-plans, which re-enforce owner/manager server-side. Logging a visit spawns a real
 * service job (S5) and rolls next_due forward.
 */
final class MaintenancePlanCapability implements ObjectCapability
{
    public function key(): string { return 'maintenancePlan'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'maintenancePlan'])->meta(['capability' => 'maintenancePlan']);
    }

    /** @return array{0:string,1:string} [label, tone] */
    private function derive(MaintenancePlan $p): array
    {
        $st = (string) $p->status;
        if ($st === 'ended')  return ['Ended', 'default'];
        if ($st === 'paused') return ['Paused', 'warn'];
        if ($p->next_due) {
            $due = Carbon::parse($p->next_due);
            if ($due->endOfDay()->isPast())                  return ['Overdue', 'danger'];
            if ($due->lessThanOrEqualTo(now()->addDays(30))) return ['Due soon', 'warn'];
        }
        return ['Scheduled', 'ok'];
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $p = MaintenancePlan::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        [$label, $tone] = $this->derive($p);

        $due  = $p->next_due ? Carbon::parse($p->next_due) : null;
        $last = $p->last_service_at ? Carbon::parse($p->last_service_at) : null;

        $stats = [
            ['key' => 'state', 'label' => 'Status', 'value' => $label, 'icon' => 'scheduled', 'tone' => $tone],
            ['key' => 'due', 'label' => 'Next due', 'value' => $due ? $due->format('d M Y') : '—', 'icon' => 'scheduled', 'tone' => $tone],
            ['key' => 'interval', 'label' => 'Interval', 'value' => 'every ' . (int) $p->interval_months . ' mo', 'icon' => 'scheduled'],
        ];

        $answers = [
            ['q' => 'Customer', 'value' => (string) ($p->customer_name ?: '—'), 'sub' => 'Plan'],
            ['q' => 'Phone', 'value' => (string) ($p->customer_phone ?: '—'), 'sub' => 'Plan'],
            ['q' => 'Equipment', 'value' => (string) ($p->serial ?: ($p->product_id ? ('#' . $p->product_id) : '—')), 'sub' => 'Plan'],
            ['q' => 'Interval', 'value' => 'every ' . (int) $p->interval_months . ' months', 'sub' => 'Plan'],
            ['q' => 'Last service', 'value' => $last ? $last->format('d M Y') : '—', 'sub' => 'Plan'],
            ['q' => 'Next due', 'value' => $due ? ($due->format('d M Y') . ' · ' . $due->diffForHumans()) : '—', 'sub' => 'Plan'],
        ];
        if ((string) $p->notes !== '') {
            $answers[] = ['q' => 'Notes', 'value' => (string) $p->notes, 'sub' => 'Plan'];
        }

        $base = '/papi/maintenance-plans/' . $p->id;
        $actions = [];
        if ((string) $p->status !== 'ended') {
            $actions[] = ['key' => 'log_visit', 'label' => 'Log a visit', 'method' => 'POST', 'endpoint' => $base . '/log-visit',
                'actor' => 'human', 'tone' => 'primary',
                'schema' => ['date' => 'text', 'notes' => 'text'],
                'labels' => ['date' => 'Visit date (blank = today)', 'notes' => 'What was done']];
            if ((string) $p->status === 'active') {
                $actions[] = ['key' => 'pause', 'label' => 'Pause', 'method' => 'POST', 'endpoint' => $base . '/pause', 'actor' => 'human'];
            }
            if ((string) $p->status === 'paused') {
                $actions[] = ['key' => 'resume', 'label' => 'Resume', 'method' => 'POST', 'endpoint' => $base . '/resume', 'actor' => 'human'];
            }
            $actions[] = ['key' => 'end', 'label' => 'End plan', 'method' => 'POST', 'endpoint' => $base . '/end',
                'actor' => 'human', 'tone' => 'danger', 'confirm' => 'End this maintenance plan?'];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'maintenancePlan', 'id' => (string) $p->id,
                'title' => (string) ($p->customer_name ?: ('Plan #' . $p->id)),
                'subtitle' => 'Maintenance · every ' . (int) $p->interval_months . ' months',
                'status' => (string) $p->status,
            ])
            ->headline($label, 'next service', $tone)
            ->stats($stats)
            ->answers($answers)
            ->actions($actions)
            ->meta(['capability' => 'maintenancePlan', 'ttl' => 30, 'permissions' => []]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
