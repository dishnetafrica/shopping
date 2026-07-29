<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\WorkOrder;
use App\Services\Field\WorkOrderService;
use Illuminate\Support\Carbon;

/**
 * Jobs — the V3 face of the Work Orders engine (Asset Lifecycle · Phase 2 · S5b). V3-native: no legacy
 * panel screen. Lists installations / service visits / repairs / inspections, with a "New job" action.
 * Rows deep-link to the workOrder object workspace. Reads open to any role (the FIELD team needs them);
 * write actions post to /papi/work-orders, which re-enforce owner/manager server-side.
 */
final class WorkOrderListCapability implements ProcessCapability
{
    public function key(): string { return 'jobs'; }

    public function permission(): string { return ''; }   // open (field team sees jobs); writes gated at /papi

    private function statusLabel(string $s): string
    {
        return ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'in_progress' => 'In progress',
            'completed' => 'Completed', 'cancelled' => 'Cancelled'][$s] ?? ucfirst($s);
    }

    private function tone(string $s): string
    {
        return ['draft' => 'info', 'scheduled' => 'warn', 'in_progress' => 'info',
            'completed' => 'ok', 'cancelled' => 'danger'][$s] ?? 'info';
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $jobs = WorkOrderService::forTenant($ctx->tenant)->forFilters([]);

        $open = 0; $scheduled = 0; $inProgress = 0; $doneToday = 0;
        $rows = [];
        foreach ($jobs as $wo) {
            $st = (string) $wo->status;
            if (in_array($st, ['draft', 'scheduled', 'in_progress'], true)) $open++;
            if ($st === 'scheduled')   $scheduled++;
            if ($st === 'in_progress') $inProgress++;
            if ($st === 'completed' && $wo->completed_at && Carbon::parse($wo->completed_at)->isToday()) $doneToday++;

            $when = $wo->scheduled_for ? Carbon::parse($wo->scheduled_for) : null;
            $rows[] = [
                'job'      => ['value' => (string) ($wo->number ?: ('#' . $wo->id)),
                    'sub' => (string) ($wo->customer_name ?: $wo->customer_phone ?: '—'), 'avatar' => true],
                'type'     => ['value' => ucfirst((string) $wo->type)],
                'status'   => ['value' => $this->statusLabel($st), 'badge' => $this->tone($st), 'sort' => $st],
                'when'     => ['value' => $when ? $when->format('D d M H:i') : '—', 'sort' => $when ? $when->timestamp : 0],
                'assigned' => ['value' => (string) ($wo->assigned_to ?: '—')],
                'link'     => '/api/v1/workspace/workOrder/' . $wo->id,
            ];
        }

        $actions = [[
            'key' => 'new_job', 'label' => 'New job', 'method' => 'POST', 'endpoint' => '/papi/work-orders',
            'actor' => 'human', 'tone' => 'primary',
            'schema' => [
                'type' => 'combo:installation|service|repair|inspection',
                'customer_name' => 'text', 'customer_phone' => 'text', 'site_address' => 'text',
                'scheduled_for' => 'datetime', 'assigned_to' => 'text', 'notes' => 'text',
            ],
            'labels' => [
                'type' => 'Job type', 'customer_name' => 'Customer name', 'customer_phone' => 'Customer WhatsApp',
                'site_address' => 'Site address', 'scheduled_for' => 'Scheduled (e.g. ' . now()->addDay()->format('Y-m-d') . ' 10:00)',
                'assigned_to' => 'Technician', 'notes' => 'Notes',
            ],
        ]];

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:jobs', 'title' => 'Jobs',
                'subtitle' => count($rows) . ' jobs · ' . $scheduled . ' scheduled · ' . $inProgress . ' in progress',
            ])
            ->financials([
                ['key' => 'open', 'label' => 'Open jobs', 'value' => (string) $open, 'foot' => 'not yet completed'],
                ['key' => 'scheduled', 'label' => 'Scheduled', 'value' => (string) $scheduled, 'tone' => $scheduled > 0 ? 'warn' : 'ok'],
                ['key' => 'inprog', 'label' => 'In progress', 'value' => (string) $inProgress],
                ['key' => 'doneToday', 'label' => 'Completed today', 'value' => (string) $doneToday, 'tone' => 'ok'],
            ])
            ->table(
                [
                    ['key' => 'job', 'label' => 'Job', 'type' => 'avatar', 'sortable' => true],
                    ['key' => 'type', 'label' => 'Type', 'sortable' => true],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'when', 'label' => 'Scheduled', 'type' => 'num', 'sortable' => true],
                    ['key' => 'assigned', 'label' => 'Technician'],
                ],
                $rows,
                [['key' => 'status', 'label' => 'Status'], ['key' => 'type', 'label' => 'Type']],
                ['pageSize' => 20])
            ->actions($actions)
            ->meta(['capability' => 'jobs', 'ttl' => 30, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $q = WorkOrder::where('tenant_id', $ctx->tenantId());

        return [
            'open'      => (clone $q)->whereIn('status', ['draft', 'scheduled', 'in_progress'])->count(),
            'scheduled' => (clone $q)->where('status', 'scheduled')->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
