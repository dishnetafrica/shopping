<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\ConsignmentStock;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentOrder;
use Illuminate\Support\Carbon;

/**
 * Consignment — one Kampala→Juba batch as an object workspace: the order MANIFEST,
 * the carried STOCK ledger (requested/sent/sold with live margin), and every
 * operation the classic tool had, each delegating to its proven /papi/consignment-*
 * endpoint. Delivery actions are open (Asish's end); loading/management actions
 * appear only for owner/manager (server re-enforces via priv()).
 */
final class ConsignmentCapability implements ObjectCapability
{
    public function key(): string { return 'consignment'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)
            ->object(['type' => 'consignment', 'title' => 'Consignments', 'subtitle' => 'Pick a consignment from the list'])
            ->meta(['capability' => 'consignment']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $s = Shipment::where('tenant_id', $t)->find((int) $id);
        if (! $s) {
            return (new WorkspacePayload)
                ->object(['type' => 'consignment', 'title' => 'Consignment not found'])
                ->meta(['capability' => 'consignment', 'error' => 'not_found']);
        }

        $canManage = $ctx->user->isOwnerLike() || $ctx->role === 'manager';
        $number = (string) ($s->shipment_number ?: '#'.$s->id);

        // ---- Manifest --------------------------------------------------------
        $lines = ShipmentOrder::where('tenant_id', $t)->where('shipment_id', $s->id)->orderBy('id')->get();
        $orders = $lines->count()
            ? Order::whereIn('id', $lines->pluck('order_id'))->get()->keyBy('id')
            : collect();

        $manifestRows = [];
        $onBoardCombo = [];
        $removableCombo = [];
        foreach ($lines as $ln) {
            $o = $orders->get($ln->order_id);
            $badge = ['on_board' => 'warn', 'delivered' => 'ok', 'returned' => 'danger'][$ln->status] ?? 'default';
            $manifestRows[] = [
                (string) ($o->order_no ?? '#'.$ln->order_id),
                (string) ($o->customer_name ?? '—'),
                (string) ($o->customer_phone ?? ''),
                mb_substr((string) ($o->items_text ?? ''), 0, 40),
                ['value' => ucfirst(str_replace('_', ' ', (string) $ln->status)), 'tone' => $badge],
                $ln->delivered_at ? Carbon::parse($ln->delivered_at)->format('d M H:i') : '—',
            ];
            $label = $ln->id.' — '.($o->order_no ?? '#'.$ln->order_id).' · '.($o->customer_name ?? '');
            if ($ln->status === ShipmentOrder::ON_BOARD) $onBoardCombo[] = $label;
            if ($ln->status !== ShipmentOrder::DELIVERED) $removableCombo[] = $label;
        }
        $delivered = $lines->where('status', ShipmentOrder::DELIVERED)->count();
        $onBoard = $lines->where('status', ShipmentOrder::ON_BOARD)->count();

        $manifestCard = ['kind' => 'table', 'title' => 'Order manifest', 'sub' => $delivered.' of '.$lines->count().' delivered', 'searchable' => true,
            'columns' => [['label' => 'Order'], ['label' => 'Customer'], ['label' => 'Phone'], ['label' => 'Items'], ['label' => 'Status'], ['label' => 'Delivered', 'align' => 'right']],
            'rows' => $manifestRows, 'empty' => 'No orders on this consignment yet — use "Add order" or "Add ALL ready orders".', 'pageSize' => 20];

        // ---- Stock ledger ----------------------------------------------------
        $stock = ConsignmentStock::where('tenant_id', $t)->where('shipment_id', $s->id)->orderBy('id')->get();
        $stockRows = [];
        $stockCombo = [];
        $sellableCombo = [];
        $rev = 0.0; $cost = 0.0;
        foreach ($stock as $k) {
            $rev += (float) $k->sold_amount;
            $cost += $k->costOfSold();
            $stockRows[] = [
                (string) $k->name,
                number_format((float) $k->qty_requested, 1),
                number_format((float) $k->qty_sent, 1),
                number_format((float) $k->qty_sold, 1),
                number_format($k->remaining(), 1),
                ['value' => ($k->currency ?: '').' '.number_format($k->margin(), 2), 'tone' => $k->margin() >= 0 ? 'ok' : 'danger'],
            ];
            $label = $k->id.' — '.$k->name.' (sent '.number_format((float) $k->qty_sent, 1).' / req '.number_format((float) $k->qty_requested, 1).')';
            $stockCombo[] = $label;
            if ($k->remaining() > 0.0001) $sellableCombo[] = $label;
        }
        $stockCard = ['kind' => 'table', 'title' => 'Carried stock', 'sub' => count($stockRows).' lines · margin '.number_format($rev - $cost, 2), 'searchable' => true,
            'columns' => [['label' => 'Item'], ['label' => 'Req', 'align' => 'right'], ['label' => 'Sent', 'align' => 'right'], ['label' => 'Sold', 'align' => 'right'], ['label' => 'Left', 'align' => 'right'], ['label' => 'Margin', 'align' => 'right']],
            'rows' => $stockRows, 'empty' => 'No stock lines — "Add stock line" requests or loads shop goods onto this consignment.'];

        // ---- Details ---------------------------------------------------------
        $detailsCard = ['kind' => 'dl', 'title' => 'Transport & contacts', 'rows' => [
            ['Route', trim((string) $s->origin_city.' → '.(string) $s->destination_city, ' →')],
            ['Transport', trim(((string) $s->transport_company).' · bus '.((string) ($s->bus_number ?: '—')))],
            ['Driver', (string) ($s->driver_phone ?: '—')],
            ['Receiving agent', trim(((string) ($s->destination_agent_name ?: '—')).' '.((string) ($s->destination_agent_phone ?: '')))],
            ['Boxes sent', (string) ($s->boxes_sent ?? '—')],
            ['Notes', (string) ($s->notes ?: '—')],
        ]];

        // ---- Actions ---------------------------------------------------------
        $body = ['id' => $s->id];
        $actions = [];

        // Delivery side — OPEN (Asish at the Juba end; server allows all roles here).
        if ($onBoard > 0) {
            $actions[] = ['key' => 'deliver_all', 'label' => 'Deliver ALL on-board ('.$onBoard.')', 'method' => 'POST',
                'endpoint' => '/papi/consignment-deliver-all', 'actor' => 'human', 'tone' => 'primary', 'body' => $body,
                'confirm' => 'Mark all '.$onBoard.' on-board orders of '.$number.' as delivered? Customers get their WhatsApp updates.'];
        }
        if ($onBoardCombo || $lines->count()) {
            $allLineCombo = [];
            foreach ($lines as $ln) {
                $o = $orders->get($ln->order_id);
                $allLineCombo[] = $ln->id.' — '.($o->order_no ?? '#'.$ln->order_id).' · '.($o->customer_name ?? '').' ['.$ln->status.']';
            }
            $actions[] = ['key' => 'line_status', 'label' => 'Set one order\'s status', 'method' => 'POST',
                'endpoint' => '/papi/consignment-line-status', 'actor' => 'human',
                'schema' => ['line_id' => 'combo:'.implode('|', array_slice($allLineCombo, 0, 30)),
                    'status' => 'enum:delivered,returned,on_board', 'note' => 'text'],
                'labels' => ['line_id' => 'Manifest line', 'status' => 'New status', 'note' => 'Note (optional)']];
        }

        // Management side — owner/manager only (server re-enforces via priv()).
        if ($canManage) {
            $candidates = Order::where('tenant_id', $t)
                ->whereNotIn('status', ['Delivered', 'Cancelled', 'Returned'])
                ->whereNotIn('id', $lines->pluck('order_id'))
                ->orderByDesc('id')->limit(30)->get(['order_no', 'customer_name']);
            if ($candidates->count()) {
                $actions[] = ['key' => 'add_order', 'label' => 'Add order', 'method' => 'POST',
                    'endpoint' => '/papi/consignment-add-order', 'actor' => 'human', 'body' => $body,
                    'schema' => ['order_no' => 'combo:'.$candidates->map(fn ($o) => $o->order_no.' — '.$o->customer_name)->implode('|')],
                    'labels' => ['order_no' => 'Ready order']];
                $actions[] = ['key' => 'add_all', 'label' => 'Add ALL ready orders', 'method' => 'POST',
                    'endpoint' => '/papi/consignment-add-all', 'actor' => 'human', 'body' => $body,
                    'confirm' => 'Attach every ready order to '.$number.'?'];
            }
            if ($removableCombo) {
                $actions[] = ['key' => 'remove_order', 'label' => 'Remove an order', 'method' => 'POST',
                    'endpoint' => '/papi/consignment-remove-order', 'actor' => 'human',
                    'schema' => ['line_id' => 'combo:'.implode('|', array_slice($removableCombo, 0, 30))],
                    'labels' => ['line_id' => 'Line to detach (delivered lines cannot be removed)']];
            }
            $actions[] = ['key' => 'add_stock', 'label' => 'Add stock line', 'method' => 'POST',
                'endpoint' => '/papi/consignment-stock-add', 'actor' => 'human', 'body' => $body,
                'schema' => ['name' => 'text', 'qty_requested' => 'number', 'qty_sent' => 'number',
                    'unit_cost' => 'number', 'unit_price' => 'number', 'currency' => 'enum:SSP,UGX,USD'],
                'labels' => ['name' => 'Item name', 'qty_requested' => 'Qty requested', 'qty_sent' => 'Qty loaded now (0 = request only)',
                    'unit_cost' => 'Unit cost', 'unit_price' => 'Selling price', 'currency' => 'Currency'],
                'prefill' => ['currency' => 'SSP', 'qty_sent' => 0]];
            if ($stockCombo) {
                $actions[] = ['key' => 'update_stock', 'label' => 'Update stock line (sent / received)', 'method' => 'POST',
                    'endpoint' => '/papi/consignment-stock-update', 'actor' => 'human',
                    'schema' => ['line_id' => 'combo:'.implode('|', array_slice($stockCombo, 0, 30)),
                        'qty_sent' => 'number', 'qty_received' => 'number'],
                    'labels' => ['line_id' => 'Stock line', 'qty_sent' => 'Qty sent (total)', 'qty_received' => 'Qty received at destination']];
            }
            if ($sellableCombo) {
                $actions[] = ['key' => 'sell_stock', 'label' => 'Sell from stock', 'method' => 'POST',
                    'endpoint' => '/papi/consignment-stock-sell', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['line_id' => 'combo:'.implode('|', array_slice($sellableCombo, 0, 30)),
                        'qty' => 'number', 'price' => 'number', 'buyer' => 'text', 'phone' => 'text'],
                    'labels' => ['line_id' => 'Stock line', 'qty' => 'Qty sold', 'price' => 'Price per unit (blank = list price)',
                        'buyer' => 'Buyer name', 'phone' => 'Buyer phone (optional)']];
            }
            $actions[] = ['key' => 'notify', 'label' => 'WhatsApp customers (arrival)', 'method' => 'POST',
                'endpoint' => '/papi/consignment-notify', 'actor' => 'human', 'body' => $body,
                'confirm' => 'Send the arrival WhatsApp to every customer on '.$number.'?'];
        }
        $actions[] = ['key' => 'sheet', 'label' => 'Manifest sheet (print)', 'href' => '/papi/consignment-sheet?id='.$s->id, 'actor' => 'human'];

        return (new WorkspacePayload)
            ->object([
                'type' => 'consignment', 'id' => (string) $s->id, 'title' => $number,
                'subtitle' => trim((string) $s->origin_city.' → '.(string) $s->destination_city, ' →')
                    .' · '.ucfirst(str_replace('_', ' ', (string) $s->status))
                    .' · agent '.($s->destination_agent_name ?: '—'),
                'status' => ($lines->count() > 0 && $delivered >= $lines->count()) ? 'active' : 'inactive',
            ])
            ->financials([
                ['key' => 'orders', 'label' => 'Orders', 'value' => $delivered.' / '.$lines->count(), 'tone' => ($lines->count() && $delivered >= $lines->count()) ? 'ok' : 'warn', 'foot' => 'delivered / total'],
                ['key' => 'onboard', 'label' => 'Still on board', 'value' => (string) $onBoard, 'tone' => $onBoard > 0 ? 'warn' : 'ok'],
                ['key' => 'stock', 'label' => 'Stock lines', 'value' => (string) count($stockRows), 'foot' => 'requested + carried'],
                ['key' => 'margin', 'label' => 'Stock margin', 'value' => number_format($rev - $cost, 2), 'tone' => ($rev - $cost) >= 0 ? 'ok' : 'danger', 'foot' => 'from market sales'],
            ])
            ->tabSections([
                'manifest' => [$manifestCard],
                'stock' => [$stockCard],
                'details' => [$detailsCard],
            ])
            ->tabs([
                ['key' => 'manifest', 'label' => 'Manifest', 'count' => $lines->count()],
                ['key' => 'stock', 'label' => 'Stock', 'count' => count($stockRows)],
                ['key' => 'details', 'label' => 'Details'],
            ])
            ->actions($actions)
            ->meta(['capability' => 'consignment', 'ttl' => 15, 'layout' => 'record', 'permissions' => []]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
