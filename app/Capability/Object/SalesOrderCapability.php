<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Invoice;
use App\Models\Order;

/**
 * Sales Order Workspace. NOTE: production stores shopping orders in `Order` and trade/margin lines in
 * `OrderCost`. This capability is the single adapter seam for that duality (M4 decision). For M1 it
 * reads the `Order` header; the OrderCost margin lines fold in here when the adapter lands.
 */
final class SalesOrderCapability implements ObjectCapability
{
    public function key(): string { return 'salesOrder'; }

    public function permission(): string { return 'menu:orders'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'salesOrder'])->meta(['capability' => 'salesOrder']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $o = Order::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $inv = Invoice::where('tenant_id', $ctx->tenantId())->where('order_id', $o->id)->first();
        $delivered = $o->delivered_at !== null;

        return (new WorkspacePayload)
            ->object([
                'type' => 'salesOrder', 'id' => (string) $o->id, 'ref' => $o->order_no, 'title' => 'Order ' . $o->order_no,
                'subtitle' => (string) $o->customer_name, 'status' => (string) $o->status,
            ])
            ->headline(Money::fmt((float) $o->total, (string) ($o->sell_currency ?: $ctx->baseCurrency())), $delivered ? 'delivered' : 'in progress', 'default')
            ->financials([
                new Financial('total', 'Total', round((float) $o->total, 2), (string) ($o->sell_currency ?: 'USD')),
                new Financial('paid', 'Paid', round((float) $o->amount_paid, 2), (string) ($o->sell_currency ?: 'USD'), 'ok'),
            ])
            ->related(array_values(array_filter([
                new Related('customer', 'customer', null, (string) $o->customer_name, null, null),
                $inv ? new Related('invoice', 'invoice', (string) $inv->id, 'Invoice ' . $inv->invoice_no, Money::fmt((float) $inv->balance, (string) ($inv->currency ?: $ctx->baseCurrency())) . ' due', null, '/api/v1/workspace/invoice/' . $inv->id) : null,
            ])))
            ->actions($delivered && ! $inv ? [['key' => 'create_invoice', 'label' => 'Create invoice', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/salesOrder/' . $o->id . '/create_invoice', 'actor' => 'human', 'tone' => 'primary']] : [])
            ->meta(['capability' => 'salesOrder', 'ttl' => 30, 'permissions' => ['menu:orders']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action:' . $action];
    }
}
