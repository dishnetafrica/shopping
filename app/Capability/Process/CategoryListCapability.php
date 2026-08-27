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
                // Rename cascades to every product in the category (products.category), the category
                // record, and its image/order settings — it reuses the ONE canonical writer,
                // /papi/category-rename (same session+CSRF auth this SPA already uses), so V3 and the
                // classic panel can never drift. A form collects the new name; the old name rides in body.
                'rename'   => ['buttons' => [[
                    'icon'   => 'edit',
                    'title'  => 'Rename "' . $c->name . '"',
                    'action' => [
                        'label'       => 'Rename category',
                        'method'      => 'POST',
                        'endpoint'    => '/papi/category-rename',
                        'schema'      => ['new' => 'text'],
                        'labels'      => ['new' => 'New category name'],
                        'prefill'     => ['new' => (string) $c->name],
                        'body'        => ['old' => (string) $c->name],
                        'submitLabel' => 'Rename',
                        'actor'       => 'human',
                    ],
                ]]],
                'catalog'  => $n > 0
                    ? ['value' => 'Catalogue', 'href' => '/api/v1/doc/catalog?category=' . rawurlencode((string) $c->name), 'icon' => 'download']
                    : ['value' => ''],
                // v4.54 — the switch that takes a whole department off the shelf. It stays in stock,
                // in reports and in campaigns; it stops appearing anywhere a customer can browse.
                //
                // v4.54.2 — wrapped in `buttons`. An action cell carrying a bare post/body/confirm is
                // not a shape the table renderer knows: it looks for `buttons` first, then `href`, and
                // falls through to an em-dash. The first version of this rendered as a silent dash in
                // a column nobody could tell from empty, which is exactly how it was reported.
                'visible'  => ['buttons' => [[
                    'icon'  => $isActive ? 'ban' : 'check',
                    'title' => $isActive ? 'Hide "' . $c->name . '" from customers' : 'Show "' . $c->name . '" to customers',
                    'tone'  => $isActive ? 'danger' : 'default',
                    'post'  => '/api/v1/workspace/categories/_/toggle_visible',
                    'body'  => ['name' => (string) $c->name],
                    'confirm' => $isActive
                        ? 'Hide "' . $c->name . '" from customers? It stays in stock and in your reports, and you can still sell it by WhatsApp — it just stops showing on the website, in the catalogue and in the bot\'s suggestions.'
                        : 'Show "' . $c->name . '" to customers again?',
                ]]],
                // Clicking the row opens the Products list, server-filtered to this category (like the panel).
                'link'     => $n > 0 ? 'scope:products:category:' . rawurlencode((string) $c->name) : null,
            ];
        }

        $uncategorised = (int) ($counts[''] ?? 0);

        $columns = [
            ['key' => 'category', 'label' => 'Category', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'products', 'label' => 'Products', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge', 'sortable' => true],
            ['key' => 'rename',   'label' => 'Rename',   'type' => 'action', 'sortable' => false],
            ['key' => 'visible',  'label' => 'Customers', 'type' => 'action', 'sortable' => false],
            ['key' => 'catalog',  'label' => 'Catalogue', 'type' => 'action', 'sortable' => false],
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
            ->actions([
                ['key' => 'add_category', 'label' => 'New category', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/categories/_/add_category',
                 'actor' => 'human', 'tone' => 'primary', 'schema' => ['name' => 'text']],
                ['key' => 'catalog', 'label' => 'Catalogue PDF', 'href' => '/api/v1/doc/catalog', 'actor' => 'human', 'tone' => 'default'],
                ['key' => 'price_list', 'label' => 'Price list PDF', 'href' => '/api/v1/doc/price-list', 'actor' => 'human', 'tone' => 'default'],
            ])
            ->meta(['capability' => 'categories', 'ttl' => 120, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['categories' => Category::where('tenant_id', $ctx->tenantId())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action === 'add_category') {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                return ['ok' => false, 'error' => 'name_required', 'message' => 'Enter a category name.'];
            }
            $c = Category::firstOrCreate(
                ['tenant_id' => $ctx->tenantId(), 'name' => $name],
                ['active' => true, 'sort' => 0],
            );

            return ['ok' => true, 'id' => (int) $c->id, 'message' => 'Category "' . $c->name . '" ' . ($c->wasRecentlyCreated ? 'added' : 'already exists') . '.'];
        }

        if ($action === 'toggle_visible') {
            $name = trim((string) ($input['name'] ?? ''));
            $c = Category::where('tenant_id', $ctx->tenantId())->where('name', $name)->first();
            if (! $c) {
                return ['ok' => false, 'error' => 'not_found', 'message' => 'No category called "' . $name . '".'];
            }
            // The model's own saved() hook drops every cached product list, so the change is live on
            // the storefront and in the bot on the next request rather than up to ten minutes later.
            $c->active = ! ($c->active !== false);
            $c->save();

            return ['ok' => true, 'message' => '"' . $c->name . '" is now ' .
                ($c->active ? 'visible to customers.' : 'hidden from customers — still yours to sell by WhatsApp.')];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
