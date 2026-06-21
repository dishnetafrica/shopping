<?php

namespace App\Services\Bot\Search;

use App\Models\Product;
use App\Models\Tenant;

/**
 * Supermarket Search — first-stage retrieval orchestrator.
 *
 * Prefers Meilisearch (in-stock, active products) when the tenant has supermarket_search on and
 * Meili is reachable; otherwise falls back to a synonym-expanded DB search — so behaviour is
 * identical (just slower) before Meili is provisioned. Results are ranked (exact > history >
 * popularity > inventory), every search is logged, and >20 results triggers a narrowing question.
 *
 * Returns ['rows' => [...], 'narrow' => ?['dimension','question','options'], 'source' => 'meili'|'db'].
 */
class ProductSearchService
{
    public function __construct(protected MeiliClient $meili, protected SearchAnalytics $analytics) {}

    public function enabledFor(Tenant $tenant): bool
    {
        return (bool) $tenant->setting('supermarket_search', false) && $this->meili->enabled();
    }

    public function search(Tenant $tenant, string $query, array $ctx = []): array
    {
        $query = trim($query);
        if ($query === '') return ['rows' => [], 'narrow' => null, 'source' => 'none'];

        $rows   = null;
        $source = 'db';

        if ($this->enabledFor($tenant)) {
            $hits = $this->meili->search($tenant->id, $query, [
                'limit'  => 50,
                'filter' => 'stock > 0 AND active = true',
            ]);
            if ($hits !== null) {
                $rows   = $this->fromHits($hits);
                $source = 'meili';
            }
        }

        if ($rows === null) {
            $rows = $this->dbFallback($tenant, $query);
        }

        $rows = SearchRanker::rank($rows, [
            'query'      => $query,
            'history'    => (array) ($ctx['history'] ?? []),
            'popularity' => (array) ($ctx['popularity'] ?? []),
        ]);

        $this->analytics->record($tenant, 'search', $query, count($rows));
        if (! $rows) $this->analytics->record($tenant, 'zero_result', $query, 0);

        $narrow = SearchNarrowing::shouldNarrow(count($rows)) ? SearchNarrowing::facets($rows) : null;

        return ['rows' => $rows, 'narrow' => $narrow, 'source' => $source];
    }

    /** Synonym-expanded DB search (the fallback first stage). In-stock + active only. */
    protected function dbFallback(Tenant $tenant, string $query): array
    {
        $terms = SearchSynonyms::expand($query);

        $rows = Product::where('tenant_id', $tenant->id)
            ->where('active', true)
            ->where(function ($w) use ($terms) {
                foreach ($terms as $t) {
                    $like = '%' . $t . '%';
                    $w->orWhere('name', 'like', $like)
                      ->orWhere('keywords', 'like', $like)
                      ->orWhere('category', 'like', $like);
                }
            })
            ->limit(100)->get();

        return $rows->map(fn (Product $p) => $this->rowOf($p))
            ->filter(fn ($r) => (int) $r['stock'] > 0)        // never suggest out-of-stock
            ->values()->all();
    }

    protected function fromHits(array $hits): array
    {
        return array_map(fn ($h) => [
            'id'         => (int) ($h['id'] ?? 0),
            'name'       => (string) ($h['name'] ?? ''),
            'category'   => (string) ($h['category'] ?? ''),
            'brand'      => (string) ($h['brand'] ?? ''),
            'price'      => (float) ($h['price'] ?? 0),
            'stock'      => (int) ($h['stock'] ?? 0),
            'popularity' => (float) ($h['popularity'] ?? 0),
        ], $hits);
    }

    protected function rowOf(Product $p): array
    {
        return [
            'id'         => (int) $p->id,
            'name'       => (string) $p->name,
            'category'   => (string) ($p->category ?? ''),
            'brand'      => (string) ($p->brand ?? ''),
            'price'      => (float) $p->price,
            'stock'      => (int) ($p->stock ?? 1),
            'popularity' => (float) ($p->popularity ?? 0),
        ];
    }
}
