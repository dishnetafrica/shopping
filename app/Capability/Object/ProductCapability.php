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
use App\Services\Catalog\ProductSpecService;
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

        $photos = array_values(array_filter([(string) $p->image_url, (string) $p->gallery_1, (string) $p->gallery_2, (string) $p->gallery_3], fn ($u) => trim((string) $u) !== ''));

        $edit = ['key' => 'edit_product', 'label' => 'Edit', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/product/' . $p->id . '/update_product', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ProductListCapability::formSchema($ctx->tenantId()),
            'prefill' => ['name' => (string) $p->name, 'category' => (string) $p->category, 'price' => round($price, 2), 'cost' => $p->cost_price === null ? '' : round($cost, 2), 'stock' => (int) $stock, 'moq' => $p->moq !== null ? (int) $p->moq : '', 'pack_size' => $p->pack_size !== null ? (int) $p->pack_size : '', 'unit_label' => (string) ($p->unit_label ?? ''), 'photos' => $photos]];
        $archive = ['key' => 'archive_product', 'label' => 'Archive', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/product/' . $p->id . '/archive_product', 'actor' => 'human', 'tone' => 'danger', 'confirm' => 'Archive "' . $p->name . '"? It will be hidden from the catalogue but kept for order history.'];

        // Asset Lifecycle · Product Specifications (S1b) — show this product's specs on the detail page
        // and offer inline editing. Rendered via the generic 'answers' section, so the V3 front-end
        // needs no change. When the tenant has no spec catalogue, both are empty → the page is unchanged.
        $specSvc     = ProductSpecService::forTenant($ctx->tenant);
        $specRows    = $specSvc->specsFor((int) $p->id);
        $specAnswers = array_map(fn ($s) => [
            'q'     => $s['label'] . (! empty($s['unit']) ? ' (' . $s['unit'] . ')' : ''),
            'value' => ($s['value'] === null || $s['value'] === '') ? '—' : (string) $s['value'],
            'sub'   => $s['group'] ?? null,
        ], $specRows);
        $specSchema = self::specFormSchema($specSvc);
        $specPrefill = [];
        foreach ($specRows as $s) { $specPrefill[$s['key']] = $s['value']; }
        $editSpecs = $specSchema === [] ? null : [
            'key' => 'set_specs', 'label' => 'Edit specifications', 'method' => 'POST',
            'endpoint' => '/api/v1/workspace/product/' . $p->id . '/set_specs',
            'actor' => 'human', 'tone' => 'default', 'schema' => $specSchema, 'prefill' => $specPrefill,
        ];

        $fins = [
            new Financial('price', 'Price', round($price, 2), $cur),
            new Financial('cost', 'Cost', $p->cost_price === null ? '—' : round($cost, 2), $p->cost_price === null ? null : $cur),
            $marginFin,
        ];
        if ($p->moq !== null) {
            $fins[] = new Financial('moq', 'Min. order (MOQ)', (int) $p->moq . ' ' . (string) ($p->unit_label ?: 'units'));
        }
        if ($p->pack_size !== null) {
            $fins[] = new Financial('pack', 'Pack size', (int) $p->pack_size);
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'product', 'id' => (string) $p->id, 'ref' => (string) $p->sku, 'title' => (string) $p->name,
                'subtitle' => trim((string) $p->sku . ' · ' . (string) $p->category), 'status' => $status,
                'image' => (string) ($p->image_url ?? ''), 'gallery' => $photos,
            ])
            ->headline(number_format($stock) . ' ' . (string) ($p->unit_label ?: 'units'), 'on hand', $status === 'out' ? 'danger' : ($status === 'low' ? 'warn' : 'ok'))
            ->financials($fins)
            ->related($p->category ? $this->reco->topInCategory($ctx, (string) $p->category, 6, (int) $p->id) : [])
            ->answers($specAnswers)
            ->actions(array_values(array_filter([$edit, $archive, $editSpecs])))
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
            'set_specs' => $this->doSetSpecs($ctx, $p, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Build a spec-edit form schema (key => field type) from the tenant's active spec catalogue. */
    private static function specFormSchema(ProductSpecService $svc): array
    {
        $schema = [];
        foreach ($svc->definitions(true) as $def) {
            $schema[$def->key] = match ($def->data_type) {
                'number'  => 'number',
                'enum'    => 'combo:' . implode('|', array_values((array) ($def->options ?? []))),
                'boolean' => 'combo:Yes|No',
                default   => 'text',
            };
        }
        return $schema;
    }

    private function doSetSpecs(CapabilityContext $ctx, Product $p, array $input): array
    {
        $svc = ProductSpecService::forTenant($ctx->tenant);
        $pairs = [];
        foreach ($svc->definitions(true) as $def) {
            if (array_key_exists($def->key, $input)) {
                $pairs[$def->key] = $input[$def->key];
            }
        }
        $svc->setProductSpecs((int) $p->id, $pairs);

        return ['ok' => true, 'message' => 'Specifications updated.'];
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
