<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Category;
use App\Models\Product;

/**
 * Categories directory — list workspace. Shows each category with its image and how many products
 * sit in it (one grouped query over products). Read-only.
 */
final class CategoryListCapability implements ProcessCapability
{
    public function key(): string { return 'categories'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        $counts = Product::where('tenant_id', $t)
            ->selectRaw('category, count(*) as c')
            ->groupBy('category')
            ->pluck('c', 'category');

        $cats = Category::where('tenant_id', $t)->orderBy('sort')->orderBy('name')->get();

        $active = 0; $categorised = 0;
        $rows = [];
        foreach ($cats as $c) {
            $n = (int) ($counts[$c->name] ?? 0);
            $isActive = $c->active !== false;
            if ($isActive) { $active++; }
            $categorised += $n;
            $rows[] = [
                'category' => ['value' => (string) $c->name, 'image' => (string) ($c->image_url ?: ''), 'avatar' => true],
                'products' => $n,
                'status'   => ['value' => $isActive ? 'Active' : 'Hidden', 'badge' => $isActive ? 'ok' : 'default'],
            ];
        }

        $uncategorised = (int) ($counts[''] ?? 0);

        $columns = [
            ['key' => 'category', 'label' => 'Category', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'products', 'label' => 'Products', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge', 'sortable' => true],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:categories', 'title' => 'Categories',
                'subtitle' => number_format($cats->count()) . ' categories · ' . number_format($categorised) . ' products categorised',
            ])
            ->financials([
                ['key' => 'total', 'label' => 'Categories', 'value' => $cats->count(), 'foot' => number_format($active) . ' active'],
                ['key' => 'categorised', 'label' => 'Products categorised', 'value' => $categorised, 'tone' => 'ok', 'foot' => 'across categories'],
                ['key' => 'uncat', 'label' => 'Uncategorised', 'value' => $uncategorised, 'tone' => $uncategorised > 0 ? 'warn' : 'ok', 'foot' => 'products with no category'],
            ])
            ->table($columns, $rows)
            ->meta(['capability' => 'categories', 'ttl' => 120, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['categories' => Category::where('tenant_id', $ctx->tenantId())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
