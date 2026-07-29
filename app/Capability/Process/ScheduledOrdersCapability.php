<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Scheduled orders — the v3 face of classic /panel/scheduled (last workflow on the
 * retire audit's honest tail). NOT marketing: these are customer ORDERS parked for a
 * future delivery time; the ProcessScheduled cron advances stages and reminders.
 *
 * Reads Orders directly; both writes delegate to the proven POST /papi/schedule-order
 * (schedule = {order_id, when}; release = {order_id, mode:'now'}). Rows deep-link to
 * the salesOrder workspace, so a scheduled order is one tap from its full record.
 */
final class ScheduledOrdersCapability implements ProcessCapability
{
    public function key(): string { return 'scheduled'; }

    public function permission(): string { return 'menu:orders'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        $queue = Order::where('tenant_id', $t)
            ->whereNotNull('scheduled_for')->whereNull('delivered_at')
            ->orderBy('scheduled_for')->limit(200)->get();

        $now = now();
        $rows = [];
        $queueCombo = [];
        $due24 = 0; $overdue = 0;
        foreach ($queue as $o) {
            $when = Carbon::parse($o->scheduled_for);
            $isOver = $when->lessThan($now);
            $isSoon = ! $isOver && $when->lessThan($now->copy()->addDay());
            if ($isOver) $overdue++;
            if ($isSoon) $due24++;
            $rows[] = [
                'order' => ['value' => (string) $o->order_no, 'sub' => (string) $o->customer_name, 'avatar' => true],
                'location' => ['value' => (string) ($o->location ?: '—')],
                'total' => ['value' => number_format((float) $o->total, 0), 'sort' => (float) $o->total],
                'when' => ['value' => $when->format('D d M H:i').' · '.$when->diffForHumans(),
                    'sort' => $when->timestamp],
                'stage' => ['value' => (string) ($o->sched_stage ?: 'Scheduled'),
                    'badge' => $isOver ? 'danger' : ($isSoon ? 'warn' : 'info'),
                    'sort' => $isOver ? 'overdue' : ($isSoon ? 'due_24h' : 'later')],
                'link' => '/api/v1/workspace/salesOrder/'.$o->id,
            ];
            $queueCombo[] = $o->id.' — '.$o->order_no.' · '.$o->customer_name
                .' ('.$when->format('d M H:i').')';
        }

        $candidates = Order::where('tenant_id', $t)
            ->whereNull('scheduled_for')->whereNull('delivered_at')
            ->orderByDesc('id')->limit(30)->get(['id', 'order_no', 'customer_name']);

        $actions = [];
        if ($candidates->count()) {
            $actions[] = ['key' => 'schedule', 'label' => 'Schedule an order', 'method' => 'POST',
                'endpoint' => '/papi/schedule-order', 'actor' => 'human', 'tone' => 'primary',
                'schema' => [
                    'order_id' => 'combo:'.$candidates->map(fn ($o) => $o->id.' — '.$o->order_no.' · '.$o->customer_name)->implode('|'),
                    'when' => 'datetime',
                ],
                'labels' => [
                    'order_id' => 'Order to schedule',
                    'when' => 'Deliver at (e.g. '.$now->copy()->addDay()->format('Y-m-d').' 14:00)',
                ]];
        }
        if ($queueCombo) {
            $actions[] = ['key' => 'reschedule', 'label' => 'Change a delivery time', 'method' => 'POST',
                'endpoint' => '/papi/schedule-order', 'actor' => 'human',
                'schema' => ['order_id' => 'combo:'.implode('|', array_slice($queueCombo, 0, 30)), 'when' => 'datetime'],
                'labels' => ['order_id' => 'Scheduled order', 'when' => 'New time (e.g. '.$now->copy()->addDay()->format('Y-m-d').' 14:00)']];
            $actions[] = ['key' => 'release', 'label' => 'Send now (unschedule)', 'method' => 'POST',
                'endpoint' => '/papi/schedule-order', 'actor' => 'human',
                'body' => ['mode' => 'now'],
                'schema' => ['order_id' => 'combo:'.implode('|', array_slice($queueCombo, 0, 30))],
                'labels' => ['order_id' => 'Order to release into the normal flow now']];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:scheduled', 'title' => 'Scheduled orders',
                'subtitle' => count($rows).' parked for later · '.$due24.' due in 24h',
            ])
            ->financials([
                ['key' => 'queued', 'label' => 'Scheduled', 'value' => (string) count($rows), 'foot' => 'waiting for their time'],
                ['key' => 'due24', 'label' => 'Due in 24h', 'value' => (string) $due24, 'tone' => $due24 > 0 ? 'warn' : 'ok', 'foot' => 'the cron moves these automatically'],
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => (string) $overdue, 'tone' => $overdue > 0 ? 'danger' : 'ok', 'foot' => 'past their time, not delivered'],
            ])
            ->table(
                [
                    ['key' => 'order', 'label' => 'Order', 'type' => 'avatar', 'sortable' => true],
                    ['key' => 'location', 'label' => 'Location'],
                    ['key' => 'total', 'label' => 'Total', 'align' => 'right', 'type' => 'num', 'sortable' => true],
                    ['key' => 'when', 'label' => 'Delivery time', 'type' => 'num', 'sortable' => true],
                    ['key' => 'stage', 'label' => 'Stage', 'type' => 'badge', 'sortable' => true],
                ],
                $rows,
                [['key' => 'stage', 'label' => 'Stage']],
                ['pageSize' => 20])
            ->actions($actions)
            ->meta(['capability' => 'scheduled', 'ttl' => 30, 'permissions' => ['menu:orders']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $t = $ctx->tenantId();
        $q = Order::where('tenant_id', $t)->whereNotNull('scheduled_for')->whereNull('delivered_at');

        return [
            'scheduled' => (clone $q)->count(),
            'due_24h' => (clone $q)->where('scheduled_for', '<=', now()->addDay())->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
