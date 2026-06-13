<?php
namespace App\Services\Catalogue;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Tenant-scoped catalogue search. Starts with Postgres ILIKE (fine to a few
 * thousand SKUs). Swap to Postgres full-text or Meilisearch for large/fast.
 */
class ProductSearch
{
    public function find(string $query, int $limit = 8): Collection
    {
        $q = trim($query);
        if ($q === '') return collect();

        return Product::query()
            ->where('active', true)
            ->where(function ($w) use ($q) {
                $w->where('name', 'ilike', "%{$q}%")
                  ->orWhere('keywords', 'ilike', "%{$q}%")
                  ->orWhere('barcode', $q);
            })
            ->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', ["{$q}%"])
            ->limit($limit)
            ->get();
    }
}
