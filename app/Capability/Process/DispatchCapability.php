<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Delivery;

/** Dispatch board over the frozen Delivery engine. */
final class DispatchCapability implements ProcessCapability
{
    public function key(): string { return 'dispatch'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $rows = Delivery::where('tenant_id', $ctx->tenantId())
            ->whereNull('delivered_at')->whereNull('failed_at')
            ->orderByDesc('id')->limit(60)->get();

        $related = $rows->take(30)->map(fn ($d) => (new Related(
            'delivery', 'delivery', (string) $d->id, 'Order #' . $d->order_id,
            (string) $d->status . (optional($d->eta_at) ? ' · ETA ' . optional($d->eta_at)->format('d M') : ''), null, null,
        ))->toArray())->values()->all();

        return (new WorkspacePayload)
            ->object(['type' => 'process:dispatch', 'title' => 'Dispatch', 'status' => $rows->count() . ' active'])
            ->headline((string) $rows->count(), 'deliveries in progress', $rows->count() > 0 ? 'warn' : 'ok')
            ->related($related)
            ->meta(['capability' => 'dispatch', 'ttl' => 20, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $active = Delivery::where('tenant_id', $ctx->tenantId())->whereNull('delivered_at')->whereNull('failed_at')->count();

        return ['inTransit' => $active];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
