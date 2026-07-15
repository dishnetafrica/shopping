<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\ImageUploadService;
use App\Services\Catalog\ProductWriteService;

/**
 * Products directory — list workspace. KPIs (total SKUs / inventory value / low / out of stock)
 * computed as aggregates over the whole catalogue; table shows the first page of SKUs.
 * Mirrors ProductCapability's margin/low/status logic exactly (no duplicated business rules).
 */
final class ProductListCapability implements ProcessCapability
{
    private const MAX_ROWS = 500;

    public function key(): string { return 'products'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $cur = $ctx->baseCurrency();

        // Aggregate KPIs over the whole catalogue (not just the shown page).
        $totalSkus = Product::where('tenant_id', $t)->count();
        $invValue = (float) Product::where('tenant_id', $t)->selectRaw('COALESCE(SUM(stock * cost_price),0) as v')->value('v');
        $lowCount = Product::where('tenant_id', $t)->whereRaw('stock > 0 AND stock <= GREATEST(5, COALESCE(moq,0))')->count();
        $outCount = Product::where('tenant_id', $t)->where('stock', '<=', 0)->count();

        // Category drill-through: /workspace/products?category=Beverages filters the list server-side
        // (exact, case-insensitive) — robust regardless of stored casing or catalogue size.
        $scope = trim((string) request()->query('category', ''));
        $prodQ = Product::where('tenant_id', $t);
        if ($scope !== '') {
            $prodQ->whereRaw("LOWER(COALESCE(category,'')) = ?", [mb_strtolower($scope)]);
        }
        $products = $prodQ->orderBy('name')->limit(self::MAX_ROWS)->get();
        $rows = [];
        foreach ($products as $p) {
            $price = (float) $p->price;
            $cost = (float) $p->cost_price;
            $margin = $price > 0 ? round(($price - $cost) / $price * 100, 1) : 0.0;
            // Cost not recorded -> margin is unknown, not 100%. Absurd margin (|.|>500%) -> the cost is
            // almost certainly a data-entry error, so flag it instead of printing e.g. -260581.8%.
            if ($cost <= 0) {
                $marginCell = ['value' => '—', 'tone' => '', 'sort' => -1];
            } elseif (abs($margin) > 500) {
                $marginCell = ['value' => '⚠ check cost', 'tone' => 'danger', 'sort' => $margin];
            } else {
                $marginCell = ['value' => $margin . '%', 'tone' => $margin < 0 ? 'danger' : ($margin < 15 ? 'warn' : 'ok'), 'sort' => $margin];
            }
            $stock = (float) $p->stock;
            $low = $stock <= max(5.0, (float) ($p->moq ?: 0));
            $status = $stock <= 0 ? 'Out of stock' : ($low ? 'Low' : 'In stock');
            $badge = $stock <= 0 ? 'danger' : ($low ? 'warn' : 'ok');
            $unit = (string) ($p->unit_label ?: 'units');

            $rows[] = [
                'product'  => ['value' => (string) $p->name, 'sub' => (string) $p->sku, 'avatar' => true, 'image' => (string) ($p->image_url ?: '')],
                'category' => ['value' => (string) ($p->category ?: '—'), 'badge' => 'default'],
                'cost'     => ['value' => Money::fmt($cost, $cur), 'sort' => $cost],
                'price'    => ['value' => Money::fmt($price, $cur), 'sort' => $price],
                'margin'   => $marginCell,
                'stock'    => ['value' => number_format($stock) . ' ' . $unit, 'tone' => $stock <= 0 ? 'danger' : '', 'sort' => $stock],
                'status'   => ['value' => $status, 'badge' => $badge],
                'link'     => '/api/v1/workspace/product/' . $p->id,
            ];
        }

        $columns = [
            ['key' => 'product',  'label' => 'Product',  'type' => 'avatar', 'sortable' => true],
            ['key' => 'category', 'label' => 'Category', 'type' => 'badge', 'sortable' => true],
            ['key' => 'cost',     'label' => 'Cost',     'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'price',    'label' => 'Price',    'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'margin',   'label' => 'Margin',   'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'stock',    'label' => 'Stock',    'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge', 'sortable' => true],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:products', 'title' => $scope !== '' ? 'Products · ' . $scope : 'Products',
                'subtitle' => $scope !== ''
                    ? number_format($products->count()) . ' in “' . $scope . '” · ' . number_format($totalSkus) . ' SKUs total'
                    : number_format($totalSkus) . ' SKUs · ' . number_format($lowCount) . ' low · ' . number_format($outCount) . ' out of stock',
            ])
            ->financials([
                ['key' => 'total', 'label' => 'Total SKUs', 'value' => $totalSkus, 'tone' => 'ok', 'foot' => 'in catalogue'],
                ['key' => 'value', 'label' => 'Inventory value', 'value' => Money::fmt($invValue, $cur), 'foot' => 'stock × cost'],
                ['key' => 'low', 'label' => 'Low stock', 'value' => $lowCount, 'tone' => $lowCount > 0 ? 'warn' : 'ok', 'foot' => 'at or below reorder point'],
                ['key' => 'out', 'label' => 'Out of stock', 'value' => $outCount, 'tone' => $outCount > 0 ? 'danger' : 'ok', 'foot' => 'need reordering'],
            ])
            ->table($columns, $rows, [
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'status', 'label' => 'Status'],
            ])
            ->actions(array_values(array_filter([
                $scope !== '' ? ['key' => 'all_products', 'label' => 'Show all products', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/products', 'actor' => 'human', 'tone' => 'default'] : null,
                ['key' => 'add_product', 'label' => 'New product', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/products/_/add_product', 'actor' => 'human', 'tone' => 'primary', 'schema' => $this->formSchema($t)],
                ['key' => 'catalog', 'label' => 'Catalogue PDF', 'href' => $scope !== '' ? '/api/v1/doc/catalog?category=' . rawurlencode($scope) : '/api/v1/doc/catalog', 'actor' => 'human', 'tone' => 'default'],
                ['key' => 'price_list', 'label' => 'Price list PDF', 'href' => '/api/v1/doc/price-list', 'actor' => 'human', 'tone' => 'default'],
                ['key' => 'import_catalog', 'label' => 'Import products', 'method' => 'POST', 'endpoint' => '/papi/products-import', 'actor' => 'human', 'tone' => 'default',
                    'schema' => ['zip' => 'file:.zip,.csv'],
                    'labels' => ['zip' => 'Catalogue file — .zip (CSV + photos) or plain .csv'],
                    'prefill' => [], 'body' => ['apply' => 1]],
                ['key' => 'export_catalog', 'label' => 'Export / download', 'icon' => 'download', 'tone' => 'default', 'chooser' => [
                    ['icon' => 'doc', 'title' => 'Products CSV', 'sub' => 'edit in Excel', 'action' => ['href' => '/api/v1/doc/products-export?format=csv']],
                    ['icon' => 'download', 'title' => 'Catalogue ZIP (CSV + photos)', 'sub' => 'full backup · re-importable', 'tone' => 'move', 'action' => ['href' => '/api/v1/doc/products-export?format=zip']],
                    ['icon' => 'products', 'title' => 'Product photos (ZIP)', 'sub' => 'all images, named by product', 'action' => ['href' => '/api/v1/doc/products-export?format=photos']],
                ]],
            ])))
            ->meta(['capability' => 'products', 'ttl' => 60, 'permissions' => ['menu:products'], 'note' => $totalSkus > self::MAX_ROWS ? ('Showing first ' . self::MAX_ROWS . ' of ' . $totalSkus . ' SKUs; KPIs cover all.') : null]);
    }

    /**
     * Schema for the add/edit product form. Category is a type-or-pick combo whose suggestions are the
     * UNION of the Category table and every category string actually used on products — so a product's
     * own category is never missing from the list, and a brand-new category can be typed in freely.
     */
    public static function formSchema(int $tenantId): array
    {
        $fromTable = Category::where('tenant_id', $tenantId)->pluck('name');
        $fromProducts = Product::where('tenant_id', $tenantId)->whereNotNull('category')->distinct()->pluck('category');
        $cats = $fromTable->merge($fromProducts)
            ->map(fn ($n) => trim((string) $n))
            ->filter(fn ($n) => $n !== '')
            ->unique()->sort()->values()->all();

        return array_filter([
            'name'       => 'text',
            'category'   => 'combo:' . implode('|', $cats),
            'price'      => 'money',
            'cost'       => 'money',
            'stock'      => 'number',
            'moq'        => 'number',
            'pack_size'  => 'number',
            'unit_label' => 'text',
            'photos'     => 'gallery',
        ]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['products' => Product::where('tenant_id', $ctx->tenantId())->count()];
    }

    /** Build the category-grouped price list and render it to a branded PDF (returned as base64). */
    private function priceList(CapabilityContext $ctx): array
    {
        $products = Product::where('tenant_id', $ctx->tenantId())->where('active', true)
            ->orderBy('category')->orderBy('name')->get();
        if ($products->isEmpty()) {
            return ['ok' => false, 'error' => 'empty', 'message' => 'No active products to build a price list from.'];
        }
        $cur = $ctx->baseCurrency();
        $grouped = [];
        foreach ($products as $p) {
            $cat = trim((string) $p->category) ?: 'Uncategorised';
            $grouped[$cat][] = ['name' => (string) $p->name, 'unit' => (string) ($p->unit_label ?: ''), 'moq' => $p->moq ? (int) $p->moq : null, 'price' => (float) $p->price];
        }
        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
        $groups = [];
        foreach ($grouped as $cat => $items) {
            $groups[] = ['category' => $cat, 'items' => $items];
        }
        $doc = app(\App\Services\Catalog\PriceListRenderer::class)->pdf($ctx->tenant, $groups, $cur);
        if (! $doc || ($doc['b64'] ?? '') === '') {
            return ['ok' => false, 'error' => 'pdf_unavailable', 'message' => 'The PDF engine is not available on this server.'];
        }

        return ['ok' => true, 'pdf_b64' => $doc['b64'], 'filename' => $doc['fileName'], 'message' => 'Price list (' . count($groups) . ' categories) downloaded.'];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action === 'price_list') {
            return $this->priceList($ctx);
        }

        if ($action === 'search') {
            $q = trim((string) ($input['q'] ?? ''));
            $rows = Product::where('tenant_id', $ctx->tenantId())
                ->where('active', true)
                ->when($q !== '', fn ($qq) => $qq->where('name', 'ilike', '%' . $q . '%'))
                ->orderBy('name')->limit(20)->get();
            $items = $rows->map(fn ($p) => [
                'name' => (string) $p->name,
                'price' => round((float) $p->price, 2),
                'unit' => (string) ($p->unit_label ?: ''),
                'image' => (string) ($p->image_url ?: ''),
            ])->all();

            return ['ok' => true, 'items' => $items];
        }

        if ($action === 'upload_image') {
            try {
                $url = app(ImageUploadService::class)->store($ctx->tenant, (string) ($input['data'] ?? ''), (string) ($input['name'] ?? 'upload'));
            } catch (\InvalidArgumentException $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'message' => 'Could not read that image.'];
            }

            return ['ok' => true, 'url' => $url];
        }

        if ($action === 'add_product') {
            try {
                $p = app(ProductWriteService::class)->create($ctx->tenant, $input);
            } catch (\InvalidArgumentException $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'message' => 'A name and a price above zero are required.'];
            }

            return ['ok' => true, 'id' => (int) $p->id, 'message' => 'Product "' . $p->name . '" added.'];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
