<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Support\Carbon;

/**
 * A single job (Asset Lifecycle · Phase 2 · S5b) — the V3 detail workspace for a Work Order. Shows the
 * job, its equipment, and the lifecycle actions (schedule / add equipment / start / complete / cancel).
 * Completing an installation marks its serial units installed and auto-activates their warranties (S3c).
 * Write actions post to /papi/work-orders, which re-enforce owner/manager server-side.
 */
final class WorkOrderCapability implements ObjectCapability
{
    public function key(): string { return 'workOrder'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'workOrder'])->meta(['capability' => 'workOrder']);
    }

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

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $wo = WorkOrder::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $st = (string) $wo->status;
        $items = WorkOrderItem::where('tenant_id', $ctx->tenantId())->where('work_order_id', $wo->id)
            ->with('product')->orderBy('id')->get();

        $stats = [
            ['key' => 'status', 'label' => 'Status', 'value' => $this->statusLabel($st), 'icon' => 'products', 'tone' => $this->tone($st)],
            ['key' => 'items', 'label' => 'Equipment', 'value' => $items->count() . ' item(s)', 'icon' => 'products'],
        ];

        $when = $wo->scheduled_for ? Carbon::parse($wo->scheduled_for)->format('D d M Y H:i') : '—';
        $answers = [
            ['q' => 'Type', 'value' => ucfirst((string) $wo->type), 'sub' => 'Job'],
            ['q' => 'Customer', 'value' => (string) ($wo->customer_name ?: '—'), 'sub' => 'Job'],
            ['q' => 'Phone', 'value' => (string) ($wo->customer_phone ?: '—'), 'sub' => 'Job'],
            ['q' => 'Site address', 'value' => (string) ($wo->site_address ?: '—'), 'sub' => 'Job'],
            ['q' => 'Scheduled', 'value' => $when, 'sub' => 'Job'],
            ['q' => 'Technician', 'value' => (string) ($wo->assigned_to ?: '—'), 'sub' => 'Job'],
        ];
        if ($wo->completed_at) {
            $answers[] = ['q' => 'Completed', 'value' => Carbon::parse($wo->completed_at)->format('D d M Y H:i')
                . ((string) $wo->completed_by !== '' ? ' · ' . (string) $wo->completed_by : ''), 'sub' => 'Job'];
        }

        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                'serial'  => ['value' => (string) ($it->serial ?: '—')],
                'product' => ['value' => (string) ($it->product->name ?? ('#' . $it->product_id))],
                'qty'     => ['value' => (string) (0 + (float) $it->qty), 'align' => 'right'],
            ];
        }

        $base = '/papi/work-orders/' . $wo->id;
        $actions = [];
        if (! in_array($st, ['completed', 'cancelled'], true)) {
            $actions[] = ['key' => 'schedule', 'label' => 'Schedule', 'method' => 'POST', 'endpoint' => $base . '/schedule',
                'actor' => 'human', 'schema' => ['scheduled_for' => 'datetime', 'assigned_to' => 'text'],
                'labels' => ['scheduled_for' => 'Scheduled (e.g. ' . now()->addDay()->format('Y-m-d') . ' 10:00)', 'assigned_to' => 'Technician']];
            $actions[] = ['key' => 'add_items', 'label' => 'Add equipment', 'method' => 'POST', 'endpoint' => $base . '/items',
                'actor' => 'human', 'schema' => ['serials' => 'text'],
                'labels' => ['serials' => 'Serial numbers — separate with commas or spaces']];
            if ($st !== 'in_progress') {
                $actions[] = ['key' => 'start', 'label' => 'Start job', 'method' => 'POST', 'endpoint' => $base . '/start', 'actor' => 'human'];
            }
            $actions[] = ['key' => 'complete', 'label' => 'Complete job', 'method' => 'POST', 'endpoint' => $base . '/complete',
                'actor' => 'human', 'tone' => 'primary', 'confirm' => 'Complete this job? Installed units will activate their warranties.'];
            $actions[] = ['key' => 'cancel', 'label' => 'Cancel', 'method' => 'POST', 'endpoint' => $base . '/cancel',
                'actor' => 'human', 'tone' => 'danger', 'confirm' => 'Cancel this job?'];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'workOrder', 'id' => (string) $wo->id, 'ref' => (string) $wo->number,
                'title' => (string) ($wo->number ?: ('Job #' . $wo->id)),
                'subtitle' => ucfirst((string) $wo->type) . (($wo->customer_name) ? ' · ' . (string) $wo->customer_name : ''),
                'status' => $st,
            ])
            ->headline($this->statusLabel($st), 'status', $this->tone($st))
            ->stats($stats)
            ->answers($answers)
            ->table(
                [
                    ['key' => 'serial', 'label' => 'Serial'],
                    ['key' => 'product', 'label' => 'Product'],
                    ['key' => 'qty', 'label' => 'Qty', 'align' => 'right', 'type' => 'num'],
                ],
                $rows, [], ['pageSize' => 50])
            ->actions($actions)
            ->meta(['capability' => 'workOrder', 'ttl' => 30, 'permissions' => []]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
