<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Product;

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

        $products = Product::where('tenant_id', $t)->orderBy('name')->limit(self::MAX_ROWS)->get();
        $rows = [];
        foreach ($products as $p) {
            $price = (float) $p->price;
            $cost = (float) $p->cost_price;
            $margin = $price > 0 ? round(($price - $cost) / $price * 100, 1) : 0.0;
            $stock = (float) $p->stock;
            $low = $stock <= max(5.0, (float) ($p->moq ?: 0));
            $status = $stock <= 0 ? 'Out of stock' : ($low ? 'Low' : 'In stock');
            $badge = $stock <= 0 ? 'danger' : ($low ? 'warn' : 'ok');
            $unit = (string) ($p->unit_label ?: 'units');

            $rows[] = [
                'product'  => ['value' => (string) $p->name, 'sub' => (string) $p->sku, 'avatar' => true],
                'category' => ['value' => (string) ($p->category ?: '—'), 'badge' => 'default'],
                'cost'     => ['value' => Money::fmt($cost, $cur), 'sort' => $cost],
                'price'    => ['value' => Money::fmt($price, $cur), 'sort' => $price],
                'margin'   => ['value' => $margin . '%', 'tone' => $margin < 15 ? 'warn' : 'ok', 'sort' => $margin],
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
                'type' => 'list:products', 'title' => 'Products',
                'subtitle' => number_format($totalSkus) . ' SKUs · ' . number_format($lowCount) . ' low · ' . number_format($outCount) . ' out of stock',
            ])
            ->financials([
                ['key' => 'total', 'label' => 'Total SKUs', 'value' => $totalSkus, 'tone' => 'ok', 'foot' => 'in catalogue'],
                ['key' => 'value', 'label' => 'Inventory value', 'value' => Money::fmt($invValue, $cur), 'foot' => 'stock × cost'],
                ['key' => 'low', 'label' => 'Low stock', 'value' => $lowCount, 'tone' => $lowCount > 0 ? 'warn' : 'ok', 'foot' => 'at or below reorder point'],
                ['key' => 'out', 'label' => 'Out of stock', 'value' => $outCount, 'tone' => $outCount > 0 ? 'danger' : 'ok', 'foot' => 'need reordering'],
            ])
            ->table($columns, $rows)
            ->actions([['key' => 'export', 'label' => 'Export', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/products', 'actor' => 'human', 'tone' => 'default']])
            ->meta(['capability' => 'products', 'ttl' => 60, 'permissions' => ['menu:products'], 'note' => $totalSkus > self::MAX_ROWS ? ('Showing first ' . self::MAX_ROWS . ' of ' . $totalSkus . ' SKUs; KPIs cover all.') : null]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['products' => Product::where('tenant_id', $ctx->tenantId())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
