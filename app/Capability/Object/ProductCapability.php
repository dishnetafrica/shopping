<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Shared\RecommendationCapability;
use App\Models\Product;

final class ProductCapability implements ObjectCapability
{
    public function __construct(private RecommendationCapability $reco) {}

    public function key(): string { return 'product'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'product'])->meta(['capability' => 'product']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $p = Product::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $price = (float) $p->price;
        $cost = (float) $p->cost_price;
        $margin = $price > 0 ? round(($price - $cost) / $price * 100, 1) : 0.0;
        $stock = (float) $p->stock;
        $low = $stock <= max(5.0, (float) ($p->moq ?: 0));
        $status = $stock <= 0 ? 'out' : ($low ? 'low' : 'in');

        return (new WorkspacePayload)
            ->object([
                'type' => 'product', 'id' => (string) $p->id, 'ref' => (string) $p->sku, 'title' => (string) $p->name,
                'subtitle' => trim((string) $p->sku . ' · ' . (string) $p->category), 'status' => $status,
            ])
            ->headline(number_format($stock) . ' ' . (string) ($p->unit_label ?: 'units'), 'on hand', $status === 'out' ? 'danger' : ($status === 'low' ? 'warn' : 'ok'))
            ->financials([
                new Financial('price', 'Price', round($price, 2), 'USD'),
                new Financial('cost', 'Cost', round($cost, 2), 'USD'),
                new Financial('margin', 'Margin', $margin, null, $margin < 15 ? 'warn' : 'ok', 'pct'),
            ])
            ->related($p->category ? $this->reco->topInCategory($ctx, (string) $p->category, 6, (int) $p->id) : [])
            ->actions($low ? [['key' => 'reorder', 'label' => 'Reorder', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/product/' . $p->id . '/reorder', 'actor' => 'human', 'tone' => 'primary']] : [])
            ->meta(['capability' => 'product', 'ttl' => 60, 'permissions' => ['menu:products']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action:' . $action];
    }
}
