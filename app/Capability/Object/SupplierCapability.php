<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Models\OrderCost;
use App\Models\Supplier;

final class SupplierCapability implements ObjectCapability
{
    public function key(): string { return 'supplier'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'supplier'])->meta(['capability' => 'supplier']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $s = Supplier::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);

        $owed = (float) OrderCost::where('tenant_id', $ctx->tenantId())
            ->where('supplier_id', $s->id)->where('stage', 'purchase')->where('status', '!=', 'void')
            ->selectRaw('COALESCE(SUM(native_amount - COALESCE(paid_native,0)),0) as owed')->value('owed');

        $recent = OrderCost::where('tenant_id', $ctx->tenantId())
            ->where('supplier_id', $s->id)->where('stage', 'purchase')
            ->orderByDesc('id')->limit(8)->get()
            ->map(fn ($c) => new Related('po', 'purchaseOrder', (string) $c->id,
                'PO ' . ($c->order_no ?: $c->id), number_format((float) $c->native_amount) . ' ' . $c->currency,
                null, '/api/v1/workspace/purchaseOrder/' . $c->id))->all();

        return (new WorkspacePayload)
            ->object([
                'type' => 'supplier', 'id' => (string) $s->id, 'ref' => (string) $s->name, 'title' => (string) $s->name,
                'subtitle' => trim(($s->contact_name ? $s->contact_name . ' · ' : '') . (string) $s->phone),
                'status' => $s->active === false ? 'inactive' : 'active',
            ])
            ->headline('$' . number_format(max(0, $owed)), 'balance owed', $owed > 0 ? 'warn' : 'ok')
            ->financials([
                new Financial('owed', 'Owed', round(max(0, $owed), 2), (string) ($s->opening_currency ?: 'USD'), $owed > 0 ? 'warn' : 'ok'),
                new Financial('terms', 'Terms', $s->pay_term_days ? ('Net ' . $s->pay_term_days) : 'Cash'),
            ])
            ->related($recent)
            ->actions([['key' => 'new_po', 'label' => 'New purchase order', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/supplier/' . $s->id . '/new_po', 'actor' => 'human', 'tone' => 'primary']])
            ->meta(['capability' => 'supplier', 'ttl' => 120, 'permissions' => ['menu:purchases']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action:' . $action];
    }
}
