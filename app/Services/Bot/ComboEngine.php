<?php

namespace App\Services\Bot;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Auto-learned "frequently bought together" engine.
 *
 * Learns from real order history (order_items co-occurrence) per tenant, cached.
 * Cold-start safe: when a product has too few learned pairs (a young shop), it fills
 * from same-category popular items, then overall popular items — all data-driven, no
 * hand-seeded rules. Returns plain rows: [['id','name','price'], ...] of ACTIVE products.
 */
class ComboEngine
{
    private const TTL_MIN = 360;   // 6h cache — order history changes slowly

    /** Products most often bought with $productId (then category/overall popular as filler). */
    public function recommendForProduct(int $tenantId, int $productId, int $limit = 3, array $excludeIds = []): array
    {
        if ($tenantId <= 0 || $productId <= 0 || $limit <= 0) return [];

        $exclude = $this->idSet($excludeIds);
        $exclude[$productId] = true;

        $ordered = array_keys($this->learnedFor($tenantId, $productId));   // pids by co-occurrence desc
        $picked  = $this->pickProducts($tenantId, $ordered, $exclude, $limit);

        if (count($picked) < $limit) {
            $this->fill($picked, $exclude, $limit, $this->categoryPeers($tenantId, $productId));
        }
        if (count($picked) < $limit) {
            $this->fill($picked, $exclude, $limit, $this->popularIds($tenantId));
        }
        return array_slice(array_values($picked), 0, $limit);
    }

    /** Add-ons for a whole cart: aggregate co-occurrence across all cart items. */
    public function recommendForCart(int $tenantId, array $productIds, int $limit = 3): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($tenantId <= 0 || ! $productIds || $limit <= 0) return [];

        $exclude = $this->idSet($productIds);
        $scores  = [];
        foreach ($productIds as $pid) {
            foreach ($this->learnedFor($tenantId, $pid) as $other => $n) {
                if (isset($exclude[$other])) continue;
                $scores[$other] = ($scores[$other] ?? 0) + $n;
            }
        }
        arsort($scores);
        $picked = $this->pickProducts($tenantId, array_keys($scores), $exclude, $limit);

        if (count($picked) < $limit) {
            $this->fill($picked, $exclude, $limit, $this->popularIds($tenantId));
        }
        return array_slice(array_values($picked), 0, $limit);
    }

    /** [pid => together_count] for one product, cached. */
    private function learnedFor(int $tenantId, int $productId): array
    {
        return Cache::remember("combo:learned:{$tenantId}:{$productId}", now()->addMinutes(self::TTL_MIN), function () use ($tenantId, $productId) {
            try {
                return DB::table('order_items as a')
                    ->join('order_items as b', 'a.order_id', '=', 'b.order_id')
                    ->where('a.tenant_id', $tenantId)
                    ->where('a.product_id', $productId)
                    ->whereColumn('b.product_id', '<>', 'a.product_id')
                    ->whereNotNull('b.product_id')
                    ->groupBy('b.product_id')
                    ->selectRaw('b.product_id as pid, COUNT(DISTINCT a.order_id) as together')
                    ->orderByDesc('together')
                    ->limit(50)
                    ->pluck('together', 'pid')
                    ->map(fn ($v) => (int) $v)
                    ->toArray();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /** Overall best-sellers (pids), cached. */
    private function popularIds(int $tenantId): array
    {
        return Cache::remember("combo:popular:{$tenantId}", now()->addMinutes(self::TTL_MIN), function () use ($tenantId) {
            try {
                return DB::table('order_items')
                    ->where('tenant_id', $tenantId)->whereNotNull('product_id')
                    ->groupBy('product_id')
                    ->selectRaw('product_id, COUNT(*) as c')
                    ->orderByDesc('c')->limit(50)
                    ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /** Active product ids in the same category as $productId (popularity-ordered). */
    private function categoryPeers(int $tenantId, int $productId): array
    {
        return Cache::remember("combo:catpeers:{$tenantId}:{$productId}", now()->addMinutes(self::TTL_MIN), function () use ($tenantId, $productId) {
            try {
                $cat = Product::where('id', $productId)->value('category');
                if (! $cat) return [];
                $ids = Product::where('active', true)
                    ->whereRaw('LOWER(TRIM(category)) = ?', [mb_strtolower(trim((string) $cat))])
                    ->pluck('id')->map(fn ($v) => (int) $v)->all();
                $pop = array_flip($this->popularIds($tenantId));
                usort($ids, fn ($a, $b) => ($pop[$a] ?? 9999) <=> ($pop[$b] ?? 9999));
                return $ids;
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /** Hydrate ordered pids into active product rows, honouring excludes + limit. */
    private function pickProducts(int $tenantId, array $orderedIds, array $exclude, int $limit): array
    {
        $orderedIds = array_values(array_filter(array_map('intval', $orderedIds), fn ($id) => $id > 0 && ! isset($exclude[$id])));
        if (! $orderedIds) return [];

        $byId = Product::where('active', true)->whereIn('id', $orderedIds)
            ->get(['id', 'name', 'base_price', 'price'])->keyBy('id');

        $out = [];
        foreach ($orderedIds as $id) {
            if (count($out) >= $limit) break;
            $p = $byId->get($id);
            if (! $p) continue;
            $out[$id] = ['id' => (int) $p->id, 'name' => (string) $p->name, 'price' => (float) ($p->base_price ?? $p->price ?? 0)];
        }
        return $out;
    }

    /** Append more rows from a fallback id list until $limit is reached. */
    private function fill(array &$picked, array $exclude, int $limit, array $fillerIds): void
    {
        if (count($picked) >= $limit || ! $fillerIds) return;
        $need = $limit - count($picked);
        $skip = $exclude;
        foreach (array_keys($picked) as $id) $skip[$id] = true;

        $rows = $this->pickProducts(0, $fillerIds, $skip, $need);
        foreach ($rows as $id => $row) {
            if (count($picked) >= $limit) break;
            $picked[$id] = $row;
        }
    }

    private function idSet(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) { $id = (int) $id; if ($id > 0) $set[$id] = true; }
        return $set;
    }
}
