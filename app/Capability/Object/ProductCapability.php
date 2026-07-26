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
use App\Models\Warranty;
use App\Services\Catalog\ProductSpecService;
use App\Services\Catalog\ProductWriteService;
use App\Services\Inventory\SerialService;

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

        // Asset Lifecycle · Serialized Inventory (S2c) — show this product's units by status (via the
        // generic 'stats' tiles, no front-end change) and offer inline serial registration. Empty for
        // non-serialized products, so their page is unchanged.
        $serialStats = [];
        if ((bool) $p->serialized) {
            $counts = SerialService::forTenant($ctx->tenant)->counts((int) $p->id);
            $total  = array_sum($counts);
            if ($total > 0) {
                $serialStats[] = ['key' => 'u_total', 'label' => 'Tracked units', 'value' => (string) $total, 'icon' => 'products', 'tone' => 'ok'];
                foreach (['in_stock' => 'In stock', 'reserved' => 'Reserved', 'sold' => 'Sold', 'installed' => 'Installed', 'returned' => 'Returned', 'replaced' => 'Replaced', 'scrapped' => 'Scrapped'] as $k => $lbl) {
                    if (! empty($counts[$k])) {
                        $serialStats[] = ['key' => 'u_' . $k, 'label' => $lbl, 'value' => (string) $counts[$k], 'icon' => 'products',
                            'tone' => $k === 'in_stock' ? 'ok' : (in_array($k, ['returned', 'scrapped'], true) ? 'warn' : 'default')];
                    }
                }
            }
        }
        $addSerials = [
            'key' => 'add_serials', 'label' => 'Add serials', 'method' => 'POST',
            'endpoint' => '/api/v1/workspace/product/' . $p->id . '/add_serials',
            'actor' => 'human', 'tone' => 'default', 'schema' => ['serials' => 'text'],
            'labels' => ['serials' => 'Serial numbers — separate with commas or new lines'], 'prefill' => [],
        ];

        // Asset Lifecycle · Warranty (S3b) — a product's DEFAULT warranty policy (manufacturer + company
        // months) shown on the detail page and editable inline, plus a live count of active warranties on
        // this product. All rendered via the generic 'answers'/'stats'/'actions' sections (no front-end
        // change). Kept off ordinary consumer goods: the terms and the editor only appear for serialized
        // products or products that already carry a policy — so a grocery item's page is unchanged.
        $mfrM  = $p->warranty_manufacturer_months;
        $compM = $p->warranty_company_months;
        $warrantyRelevant = (bool) $p->serialized || $mfrM !== null || $compM !== null;

        $warrantyAnswers = [];
        if ($warrantyRelevant) {
            $warrantyAnswers[] = ['q' => 'Manufacturer warranty', 'value' => $mfrM ? $mfrM . ' months' : '—', 'sub' => 'Warranty'];
            $warrantyAnswers[] = ['q' => 'Company warranty', 'value' => $compM ? $compM . ' months' : '—', 'sub' => 'Warranty'];
        }

        $warrantyStats = [];
        $activeWarranties = Warranty::where('tenant_id', $ctx->tenantId())
            ->where('product_id', (int) $p->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('warranty_end')->orWhere('warranty_end', '>=', now()->toDateString());
            })
            ->count();
        if ($activeWarranties > 0) {
            $warrantyStats[] = ['key' => 'w_active', 'label' => 'Active warranties', 'value' => (string) $activeWarranties, 'icon' => 'shield', 'tone' => 'ok'];
        }

        $editWarranty = null;
        if ($warrantyRelevant) {
            $editWarranty = [
                'key' => 'set_warranty', 'label' => 'Set warranty terms', 'method' => 'POST',
                'endpoint' => '/api/v1/workspace/product/' . $p->id . '/set_warranty',
                'actor' => 'human', 'tone' => 'default',
                'schema' => ['warranty_manufacturer_months' => 'number', 'warranty_company_months' => 'number'],
                'labels' => [
                    'warranty_manufacturer_months' => 'Manufacturer warranty (months)',
                    'warranty_company_months'      => 'Company warranty (months)',
                ],
                'prefill' => [
                    'warranty_manufacturer_months' => $mfrM ?? '',
                    'warranty_company_months'      => $compM ?? '',
                ],
            ];
        }

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
            ->stats(array_merge($serialStats, $warrantyStats))
            ->answers(array_merge($specAnswers, $warrantyAnswers))
            ->actions(array_values(array_filter([$edit, $archive, $editSpecs, $addSerials, $editWarranty])))
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
            'add_serials' => $this->doAddSerials($ctx, $p, $input),
            'set_warranty' => $this->doSetWarranty($ctx, $p, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Split a free-text serials field (commas / new lines / spaces) into a clean, unique list. */
    private static function parseSerials(string $s): array
    {
        $parts = preg_split('/[\s,;]+/', trim($s)) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($x) => $x !== '')));
    }

    /** Coerce a months field to a positive int, or null (blank / zero / non-numeric → no policy). */
    private static function parseMonths($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }

    /**
     * Set this product's DEFAULT warranty policy (S3b). A plain product-attribute update — the terms a
     * new warranty inherits when a unit is sold/installed (auto-activation lands in S3c). Owner/manager
     * gating is enforced upstream by the workspace command route.
     */
    private function doSetWarranty(CapabilityContext $ctx, Product $p, array $input): array
    {
        $p->warranty_manufacturer_months = self::parseMonths($input['warranty_manufacturer_months'] ?? null);
        $p->warranty_company_months      = self::parseMonths($input['warranty_company_months'] ?? null);
        $p->save();

        return ['ok' => true, 'message' => 'Warranty terms updated.'];
    }

    private function doAddSerials(CapabilityContext $ctx, Product $p, array $input): array
    {
        $serials = self::parseSerials((string) ($input['serials'] ?? ''));
        if ($serials === []) {
            return ['ok' => false, 'error' => 'No serial numbers provided.'];
        }
        $units = SerialService::forTenant($ctx->tenant)->bulkRegister((int) $p->id, $serials);

        return ['ok' => true, 'message' => count($units) . ' serial(s) registered.'];
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
