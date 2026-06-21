<?php

namespace App\Services\Bot\Search;

use App\Models\Product;
use App\Models\Tenant;

/**
 * Supermarket Search — Meilisearch indexer. Pushes a tenant's products into its index with the
 * searchable / filterable / sortable attributes and synonyms configured. No-op when Meili is off.
 */
class SearchIndexer
{
    public function __construct(protected MeiliClient $meili) {}

    /** Re-index one tenant. Returns the number of documents pushed. */
    public function reindex(Tenant $tenant): int
    {
        if (! $this->meili->enabled()) return 0;

        $this->meili->configure($tenant->id);

        $count = 0;
        Product::where('tenant_id', $tenant->id)->orderBy('id')->chunk(500, function ($chunk) use ($tenant, &$count) {
            $docs = $chunk->map(fn (Product $p) => $this->docOf($p))->all();
            if ($this->meili->indexDocuments($tenant->id, $docs)) {
                $count += count($docs);
            }
        });

        return $count;
    }

    /** Push or refresh a single product (e.g. after an edit or stock change). */
    public function upsert(Tenant $tenant, Product $p): void
    {
        if (! $this->meili->enabled()) return;
        $this->meili->indexDocuments($tenant->id, [$this->docOf($p)]);
    }

    protected function docOf(Product $p): array
    {
        return [
            'id'         => (int) $p->id,
            'name'       => (string) $p->name,
            'brand'      => (string) ($p->brand ?? ''),
            'category'   => (string) ($p->category ?? ''),
            'keywords'   => (string) ($p->keywords ?? ''),
            'price'      => (float) $p->price,
            'stock'      => (int) ($p->stock ?? 0),
            'popularity' => (int) ($p->popularity ?? 0),
            'active'     => (bool) $p->active,
        ];
    }
}
