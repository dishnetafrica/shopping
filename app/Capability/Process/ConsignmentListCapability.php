<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\ConsignmentStock;
use App\Models\Shipment;
use App\Models\ShipmentOrder;
use Illuminate\Support\Carbon;

/**
 * Consignments — the v3 face of the classic /panel/consignment tool (the last
 * daily-driver item on the retire audit's honest tail).
 *
 * A consignment = a Kampala→Juba batch: customer-order manifest + carried shop
 * stock. Reads Shipment/ShipmentOrder/ConsignmentStock directly; EVERY write goes
 * through the proven /papi/consignment-* endpoints. Role split preserved exactly:
 * creating/loading a consignment is owner/manager work (server-enforced by priv()),
 * while marking deliveries is open — that is Asish's job at the Juba end, so this
 * workspace is open-permission with management actions gated in the payload.
 */
final class ConsignmentListCapability implements ProcessCapability
{
    public function key(): string { return 'consignments'; }

    public function permission(): string { return ''; }   // field staff must operate deliveries

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $canManage = $ctx->user->isOwnerLike() || $ctx->role === 'manager';

        $ships = Shipment::where('tenant_id', $t)->orderByDesc('id')->limit(100)->get();
        $counts = ShipmentOrder::where('tenant_id', $t)
            ->selectRaw("shipment_id, COUNT(*) as n, SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) as done")
            ->groupBy('shipment_id')->get()->keyBy('shipment_id');

        $rows = [];
        foreach ($ships as $s) {
            $c = $counts->get($s->id);
            $n = $c ? (int) $c->n : (($s->order_id) ? 1 : 0);
            $done = $c ? (int) $c->done : 0;
            $when = $s->created_at ? Carbon::parse($s->created_at) : null;
            $rows[] = [
                'number' => ['value' => (string) ($s->shipment_number ?: '#'.$s->id), 'sub' => trim((string) $s->origin_city.' → '.(string) $s->destination_city, ' →'), 'avatar' => true],
                'transport' => ['value' => trim(((string) $s->transport_company).' '.((string) $s->bus_number))] ,
                'agent' => ['value' => (string) ($s->destination_agent_name ?: '—')],
                'orders' => ['value' => $done.' / '.$n, 'sort' => $n],
                'status' => ['value' => ucfirst(str_replace('_', ' ', (string) $s->status)),
                    'badge' => ($n > 0 && $done >= $n) ? 'ok' : ($n > 0 ? 'warn' : 'default'),
                    'sort' => (string) $s->status],
                'when' => $when ? ['value' => $when->format('d M'), 'sort' => $when->timestamp] : ['value' => '—', 'sort' => 0],
                'link' => '/api/v1/workspace/consignment/'.$s->id,
            ];
        }

        $onBoard   = ShipmentOrder::where('tenant_id', $t)->where('status', ShipmentOrder::ON_BOARD)->count();
        $delivered = ShipmentOrder::where('tenant_id', $t)->where('status', ShipmentOrder::DELIVERED)->count();
        $shortItems = ConsignmentStock::where('tenant_id', $t)->whereColumn('qty_requested', '>', 'qty_sent')->count();

        $actions = [];
        if ($canManage) {
            // Carry-forward hint: the most recent consignment still holding unsent stock requests.
            $carry = ConsignmentStock::where('tenant_id', $t)
                ->whereColumn('qty_requested', '>', 'qty_sent')
                ->orderByDesc('shipment_id')->first();
            $carryLabel = 'Carry unsent stock from consignment id (0 = none)';
            $carryPrefill = 0;
            if ($carry) {
                $src = Shipment::where('tenant_id', $t)->find($carry->shipment_id);
                $carryLabel = 'Carry unsent stock from consignment id — '
                    .($src?->shipment_number ?: '#'.$carry->shipment_id).' still has unsent items';
                $carryPrefill = (int) $carry->shipment_id;
            }
            $actions[] = ['key' => 'new_consignment', 'label' => 'New consignment', 'method' => 'POST',
                'endpoint' => '/papi/consignment-create', 'actor' => 'human', 'tone' => 'primary',
                'schema' => [
                    'origin_city' => 'text', 'destination_city' => 'text',
                    'transport_company' => 'text', 'bus_number' => 'text', 'driver_phone' => 'text',
                    'destination_agent_name' => 'text', 'destination_agent_phone' => 'text',
                    'notes' => 'text', 'carry_from' => 'number',
                ],
                'labels' => [
                    'origin_city' => 'From', 'destination_city' => 'To',
                    'transport_company' => 'Transport company', 'bus_number' => 'Bus number',
                    'driver_phone' => 'Driver phone', 'destination_agent_name' => 'Receiving agent (e.g. Asish)',
                    'destination_agent_phone' => 'Agent phone', 'notes' => 'Notes',
                    'carry_from' => $carryLabel,
                ],
                'prefill' => ['origin_city' => 'Kampala', 'destination_city' => 'Juba', 'carry_from' => $carryPrefill]];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:consignments', 'title' => 'Consignments',
                'subtitle' => count($rows).' consignments · '.$onBoard.' orders on board',
            ])
            ->financials([
                ['key' => 'onboard', 'label' => 'On board', 'value' => (string) $onBoard, 'tone' => $onBoard > 0 ? 'warn' : 'ok', 'foot' => 'orders travelling now'],
                ['key' => 'delivered', 'label' => 'Delivered', 'value' => (string) $delivered, 'foot' => 'all time'],
                ['key' => 'short', 'label' => 'Unsent stock', 'value' => (string) $shortItems, 'tone' => $shortItems > 0 ? 'info' : 'default', 'foot' => 'requested items not yet loaded'],
                ['key' => 'total', 'label' => 'Consignments', 'value' => (string) count($rows), 'foot' => 'most recent 100'],
            ])
            ->table(
                [
                    ['key' => 'number', 'label' => 'Consignment', 'type' => 'avatar', 'sortable' => true],
                    ['key' => 'transport', 'label' => 'Transport'],
                    ['key' => 'agent', 'label' => 'Agent'],
                    ['key' => 'orders', 'label' => 'Delivered / total', 'align' => 'right', 'type' => 'num', 'sortable' => true],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'when', 'label' => 'Created', 'align' => 'right', 'type' => 'num', 'sortable' => true],
                ],
                $rows,
                [['key' => 'status', 'label' => 'Status']],
                ['pageSize' => 20])
            ->actions($actions)
            ->meta(['capability' => 'consignments', 'ttl' => 30, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $t = $ctx->tenantId();

        return [
            'on_board' => ShipmentOrder::where('tenant_id', $t)->where('status', ShipmentOrder::ON_BOARD)->count(),
            'unsent_stock' => ConsignmentStock::where('tenant_id', $t)->whereColumn('qty_requested', '>', 'qty_sent')->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
