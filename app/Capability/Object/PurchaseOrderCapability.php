<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\OrderCost;

/** Purchase Order Workspace over OrderCost (stage=purchase). */
final class PurchaseOrderCapability implements ObjectCapability
{
    public function key(): string { return 'purchaseOrder'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'purchaseOrder'])->meta(['capability' => 'purchaseOrder']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $c = OrderCost::where('tenant_id', $ctx->tenantId())->where('stage', 'purchase')->findOrFail((int) $id);
        $owed = (float) $c->native_amount - (float) ($c->paid_native ?? 0);

        return (new WorkspacePayload)
            ->object([
                'type' => 'purchaseOrder', 'id' => (string) $c->id, 'ref' => (string) ($c->order_no ?: $c->id),
                'title' => 'PO ' . ($c->order_no ?: $c->id), 'subtitle' => (string) $c->supplier_name, 'status' => (string) $c->status,
            ])
            ->headline(Money::fmt((float) $c->native_amount, (string) ($c->currency ?: $ctx->baseCurrency())), 'PO total', 'default')
            ->financials([
                new Financial('total', 'Total', round((float) $c->native_amount, 2), $c->currency),
                new Financial('paid', 'Paid', round((float) ($c->paid_native ?? 0), 2), $c->currency, 'ok'),
                new Financial('owed', 'Owed', round(max(0, $owed), 2), $c->currency, $owed > 0 ? 'warn' : 'ok'),
            ])
            ->related(array_values(array_filter([
                $c->supplier_id ? new Related('supplier', 'supplier', (string) $c->supplier_id, (string) $c->supplier_name, null, null, '/api/v1/workspace/supplier/' . $c->supplier_id) : null,
            ])))
            ->meta(['capability' => 'purchaseOrder', 'ttl' => 60, 'permissions' => ['menu:purchases']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action:' . $action];
    }
}
