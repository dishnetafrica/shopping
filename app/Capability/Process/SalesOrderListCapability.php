<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Sales Orders directory — list workspace (the full order book). KPIs (open / to-deliver / delivered /
 * order value) as aggregates; table shows the most recent orders. Read-only; rows open the order 360.
 */
final class SalesOrderListCapability implements ProcessCapability
{
    private const MAX_ROWS = 500;
    private const CLOSED = ['cancelled', 'rejected'];

    public function key(): string { return 'salesOrders'; }

    public function permission(): string { return 'menu:orders'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $cur = $ctx->baseCurrency();

        $total = Order::where('tenant_id', $t)->count();
        $open = Order::where('tenant_id', $t)->whereNull('delivered_at')->whereNotIn('status', self::CLOSED)->count();
        $delivered = Order::where('tenant_id', $t)->whereNotNull('delivered_at')->count();
        $value = (float) Order::where('tenant_id', $t)->whereNotIn('status', self::CLOSED)->selectRaw('COALESCE(SUM(total),0) as v')->value('v');

        $orders = Order::where('tenant_id', $t)->orderByDesc('created_at')->limit(self::MAX_ROWS)->get();
        $rows = [];
        foreach ($orders as $o) {
            $status = strtolower((string) $o->status);
            $items = is_array($o->items_json) ? count($o->items_json) : 0;
            $created = $o->created_at ? Carbon::parse($o->created_at) : null;
            $isDelivered = $o->delivered_at !== null;
            $label = $isDelivered ? 'Delivered' : ucfirst($status ?: 'Open');
            $badge = $isDelivered ? 'ok' : (in_array($status, self::CLOSED, true) ? 'danger' : (in_array($status, ['new', 'pending'], true) ? 'info' : 'warn'));

            $rows[] = [
                'order'    => (string) $o->order_no,
                'customer' => ['value' => (string) ($o->customer_name ?: 'Customer'), 'sub' => (string) ($o->customer_phone ?: ''), 'avatar' => true],
                'items'    => $items,
                'total'    => ['value' => Money::fmt((float) $o->total, (string) ($o->sell_currency ?: $cur)), 'sort' => (float) $o->total],
                'status'   => ['value' => $label, 'badge' => $badge],
                'created'  => ['value' => $created ? $created->format('d M Y') : '—', 'sort' => $created ? $created->timestamp : 0],
                'link'     => '/api/v1/workspace/salesOrder/' . $o->id,
            ];
        }

        $columns = [
            ['key' => 'order',    'label' => 'Order #',  'type' => 'strong', 'sortable' => true],
            ['key' => 'customer', 'label' => 'Customer', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'items',    'label' => 'Items',    'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'total',    'label' => 'Total',    'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge', 'sortable' => true],
            ['key' => 'created',  'label' => 'Created',  'sortable' => true],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:salesOrders', 'title' => 'Sales Orders',
                'subtitle' => number_format($total) . ' orders · ' . number_format($open) . ' open · ' . Money::fmt($value, $cur) . ' in the book',
            ])
            ->financials([
                ['key' => 'total', 'label' => 'Total orders', 'value' => $total, 'foot' => 'all time'],
                ['key' => 'open', 'label' => 'Open', 'value' => $open, 'tone' => $open > 0 ? 'warn' : 'ok', 'foot' => 'not yet delivered'],
                ['key' => 'delivered', 'label' => 'Delivered', 'value' => $delivered, 'tone' => 'ok', 'foot' => 'fulfilled'],
                ['key' => 'value', 'label' => 'Order value', 'value' => Money::fmt($value, $cur), 'foot' => 'open book'],
            ])
            ->table($columns, $rows)
            ->actions([['key' => 'export', 'label' => 'Export', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesOrders', 'actor' => 'human', 'tone' => 'default']])
            ->meta(['capability' => 'salesOrders', 'ttl' => 30, 'permissions' => ['menu:orders'], 'note' => $total > self::MAX_ROWS ? ('Showing most recent ' . self::MAX_ROWS . ' of ' . $total . '; KPIs cover all.') : null]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['orders' => Order::where('tenant_id', $ctx->tenantId())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
