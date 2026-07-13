<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Related;
use App\Capability\Shared\StockViewCapability;
use App\Capability\Dto\WorkspacePayload;

/** Warehouse — low-stock / reorder queue over the frozen stock cache. */
final class WarehouseCapability implements ProcessCapability
{
    public function __construct(private StockViewCapability $stock) {}

    public function key(): string { return 'warehouse'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $low = $this->stock->lowStock($ctx, 100);
        $related = $low->take(30)->map(fn ($p) => (new Related(
            'reorder', 'product', (string) $p->id, (string) $p->name,
            (float) $p->stock <= 0 ? 'out of stock' : (number_format((float) $p->stock) . ' left'), null,
            '/api/v1/workspace/product/' . $p->id,
        ))->toArray())->values()->all();

        return (new WorkspacePayload)
            ->object(['type' => 'process:warehouse', 'title' => 'Warehouse', 'status' => $low->count() . ' to reorder'])
            ->headline((string) $low->count(), 'SKUs below reorder point', $low->count() > 0 ? 'warn' : 'ok')
            ->related($related)
            ->meta(['capability' => 'warehouse', 'ttl' => 60, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['reorder' => $this->stock->reorderCount($ctx)];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
