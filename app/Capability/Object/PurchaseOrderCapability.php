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

        // Currency-aware: the PO amount is in its OWN currency (native/UGX when untagged, NOT the USD
        // display base). Convert to the report currency so a UGX total never prints with a '$'.
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $ccur = (string) ($c->currency ?: $native);
        $cdate = $c->created_at ? \Illuminate\Support\Carbon::parse($c->created_at)->format('Y-m-d') : null;
        $cusd = (float) ($c->usd_amount ?? 0) ?: null;
        $dispCur = $rc->consolidationCurrency();
        $conv = fn ($v) => round((float) $rc->consolidate([['amount' => (float) $v, 'currency' => $ccur, 'date' => $cdate]])['amount'], 2);

        return (new WorkspacePayload)
            ->object([
                'type' => 'purchaseOrder', 'id' => (string) $c->id, 'ref' => (string) ($c->order_no ?: $c->id),
                'title' => 'PO ' . ($c->order_no ?: $c->id), 'subtitle' => (string) $c->supplier_name, 'status' => (string) $c->status,
            ])
            ->headline($rc->text((float) $c->native_amount, $ccur, $cusd, $cdate), 'PO total', 'default')
            ->financials([
                new Financial('total', 'Total', $conv((float) $c->native_amount), $dispCur),
                new Financial('paid', 'Paid', $conv((float) ($c->paid_native ?? 0)), $dispCur, 'ok'),
                new Financial('owed', 'Owed', $conv(max(0, $owed)), $dispCur, $owed > 0 ? 'warn' : 'ok'),
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
