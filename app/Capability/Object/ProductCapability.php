<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Process\ProductListCapability;
use App\Capability\Shared\RecommendationCapability;
use App\Capability\Support\Money;
use App\Models\Product;
use App\Services\Catalog\ProductWriteService;

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
        $cur = $ctx->baseCurrency();

        // Margin that reads honestly when cost is missing or clearly wrong (data entry error).
        if ($cost <= 0) {
            $marginFin = new Financial('margin', 'Margin', '—');
        } elseif (abs($margin) > 500) {
            $marginFin = new Financial('margin', 'Margin', '⚠ check cost', null, 'danger');
        } else {
            $marginFin = new Financial('margin', 'Margin', $margin, null, $margin < 0 ? 'danger' : ($margin < 15 ? 'warn' : 'ok'), 'pct');
        }

        $edit = ['key' => 'edit_product', 'label' => 'Edit', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/product/' . $p->id . '/update_product', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ProductListCapability::formSchema($ctx->tenantId()),
            'prefill' => ['name' => (string) $p->name, 'category' => (string) $p->category, 'price' => round($price, 2), 'cost' => $p->cost_price === null ? '' : round($cost, 2), 'stock' => (int) $stock, 'unit_label' => (string) ($p->unit_label ?? ''), 'image' => (string) ($p->image_url ?? '')]];
        $archive = ['key' => 'archive_product', 'label' => 'Archive', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/product/' . $p->id . '/archive_product', 'actor' => 'human', 'tone' => 'danger', 'confirm' => 'Archive "' . $p->name . '"? It will be hidden from the catalogue but kept for order history.'];

        return (new WorkspacePayload)
            ->object([
                'type' => 'product', 'id' => (string) $p->id, 'ref' => (string) $p->sku, 'title' => (string) $p->name,
                'subtitle' => trim((string) $p->sku . ' · ' . (string) $p->category), 'status' => $status,
                'image' => (string) ($p->image_url ?? ''),
            ])
            ->headline(number_format($stock) . ' ' . (string) ($p->unit_label ?: 'units'), 'on hand', $status === 'out' ? 'danger' : ($status === 'low' ? 'warn' : 'ok'))
            ->financials([
                new Financial('price', 'Price', round($price, 2), $cur),
                new Financial('cost', 'Cost', $p->cost_price === null ? '—' : round($cost, 2), $p->cost_price === null ? null : $cur),
                $marginFin,
            ])
            ->related($p->category ? $this->reco->topInCategory($ctx, (string) $p->category, 6, (int) $p->id) : [])
            ->actions([$edit, $archive])
            ->meta(['capability' => 'product', 'ttl' => 60, 'permissions' => ['menu:products']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        $p = Product::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if (! $p) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        return match ($action) {
            'update_product' => $this->doUpdate($ctx, $p, $input),
            'archive_product' => $this->doArchive($ctx, $p),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    private function doUpdate(CapabilityContext $ctx, Product $p, array $input): array
    {
        app(ProductWriteService::class)->update($ctx->tenant, $p, $input);

        return ['ok' => true, 'message' => 'Product updated.'];
    }

    private function doArchive(CapabilityContext $ctx, Product $p): array
    {
        app(ProductWriteService::class)->archive($ctx->tenant, $p);

        return ['ok' => true, 'message' => 'Product archived.'];
    }
}
