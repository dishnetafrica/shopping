<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Models\OrderCost;

/** Purchase pipeline: open purchase orders and what is payable. */
final class PurchaseProcessCapability implements ProcessCapability
{
    public function key(): string { return 'purchase.process'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $rows = $this->payable($ctx);
        $related = $rows->take(20)->map(fn ($c) => (new Related(
            'payable', 'purchaseOrder', (string) $c->id, (string) $c->supplier_name,
            'PO ' . ($c->order_no ?: $c->id) . ' · $' . number_format((float) $c->native_amount - (float) ($c->paid_native ?? 0)), null,
            '/api/v1/workspace/purchaseOrder/' . $c->id,
        ))->toArray())->values()->all();

        return (new WorkspacePayload)
            ->object(['type' => 'process:purchase', 'title' => 'Purchasing', 'status' => $rows->count() . ' payable'])
            ->headline('$' . number_format((float) $rows->sum(fn ($c) => (float) $c->native_amount - (float) ($c->paid_native ?? 0))), 'payable', 'warn')
            ->related($related)
            ->meta(['capability' => 'purchase.process', 'ttl' => 60, 'permissions' => ['menu:purchases']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $rows = $this->payable($ctx);

        return ['payableCount' => $rows->count(), 'payable' => round((float) $rows->sum(fn ($c) => (float) $c->native_amount - (float) ($c->paid_native ?? 0)), 2)];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    private function payable(CapabilityContext $ctx)
    {
        return OrderCost::where('tenant_id', $ctx->tenantId())
            ->where('stage', 'purchase')->where('status', '!=', 'void')
            ->orderByDesc('id')->limit(200)->get()
            ->filter(fn ($c) => ((float) $c->native_amount - (float) ($c->paid_native ?? 0)) > 0)
            ->values();
    }
}
