<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\MaintenancePlan;
use App\Services\Field\MaintenancePlanService;
use Illuminate\Support\Carbon;

/**
 * Maintenance — the V3 face of recurring service plans (Asset Lifecycle · Phase 2 · S6b). V3-native.
 * A due-for-service list (overdue / due-soon / scheduled) with a "New plan" action; rows deep-link to
 * the plan detail, where a visit is logged. Reads open (the field team needs them); writes post to
 * /papi/maintenance-plans, which re-enforce owner/manager server-side.
 */
final class MaintenancePlanListCapability implements ProcessCapability
{
    public function key(): string { return 'maintenance'; }

    public function permission(): string { return ''; }

    /** @return array{0:string,1:string} [label, tone] for a plan's due state. */
    private function derive(MaintenancePlan $p): array
    {
        $st = (string) $p->status;
        if ($st === 'ended')  return ['Ended', 'default'];
        if ($st === 'paused') return ['Paused', 'warn'];
        if ($p->next_due) {
            $due = Carbon::parse($p->next_due);
            if ($due->endOfDay()->isPast())                     return ['Overdue', 'danger'];
            if ($due->lessThanOrEqualTo(now()->addDays(30)))    return ['Due soon', 'warn'];
        }
        return ['Scheduled', 'ok'];
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $plans = MaintenancePlanService::forTenant($ctx->tenant)->forFilters([]);

        $overdue = 0; $dueSoon = 0; $active = 0;
        $rows = [];
        foreach ($plans as $p) {
            [$label, $tone] = $this->derive($p);
            if ($p->status === 'active') $active++;
            if ($tone === 'danger')      $overdue++;
            if ($label === 'Due soon')   $dueSoon++;

            $due = $p->next_due ? Carbon::parse($p->next_due) : null;
            $equip = $p->serial ?: ($p->product_id ? ('#' . $p->product_id) : '—');
            $rows[] = [
                'customer'  => ['value' => (string) ($p->customer_name ?: $p->customer_phone ?: '—'),
                    'sub' => (string) ($p->customer_phone ?: ''), 'avatar' => true],
                'equipment' => ['value' => (string) $equip],
                'interval'  => ['value' => 'every ' . (int) $p->interval_months . ' mo'],
                'due'       => ['value' => $due ? ($due->format('d M Y') . ' · ' . $due->diffForHumans()) : '—',
                    'sort' => $due ? $due->timestamp : 0],
                'status'    => ['value' => $label, 'badge' => $tone, 'sort' => $tone],
                'link'      => '/api/v1/workspace/maintenancePlan/' . $p->id,
            ];
        }

        $actions = [[
            'key' => 'new_plan', 'label' => 'New plan', 'method' => 'POST', 'endpoint' => '/papi/maintenance-plans',
            'actor' => 'human', 'tone' => 'primary',
            'schema' => ['customer_name' => 'text', 'customer_phone' => 'text', 'interval_months' => 'number', 'next_due' => 'text', 'notes' => 'text'],
            'labels' => [
                'customer_name' => 'Customer name', 'customer_phone' => 'Customer WhatsApp',
                'interval_months' => 'Service every (months)',
                'next_due' => 'First service due (e.g. ' . now()->addMonths(6)->format('Y-m-d') . ')',
                'notes' => 'Notes',
            ],
        ]];

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:maintenance', 'title' => 'Maintenance',
                'subtitle' => count($rows) . ' plans · ' . $overdue . ' overdue · ' . $dueSoon . ' due soon',
            ])
            ->financials([
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => (string) $overdue, 'tone' => $overdue > 0 ? 'danger' : 'ok', 'foot' => 'past due — schedule now'],
                ['key' => 'dueSoon', 'label' => 'Due in 30 days', 'value' => (string) $dueSoon, 'tone' => $dueSoon > 0 ? 'warn' : 'ok'],
                ['key' => 'active', 'label' => 'Active plans', 'value' => (string) $active],
            ])
            ->table(
                [
                    ['key' => 'customer', 'label' => 'Customer', 'type' => 'avatar', 'sortable' => true],
                    ['key' => 'equipment', 'label' => 'Equipment'],
                    ['key' => 'interval', 'label' => 'Interval'],
                    ['key' => 'due', 'label' => 'Next due', 'type' => 'num', 'sortable' => true],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'sortable' => true],
                ],
                $rows,
                [['key' => 'status', 'label' => 'Status']],
                ['pageSize' => 20])
            ->actions($actions)
            ->meta(['capability' => 'maintenance', 'ttl' => 30, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['due' => MaintenancePlanService::forTenant($ctx->tenant)->dueSoon(30)->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
