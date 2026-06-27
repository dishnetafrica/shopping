<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Storefront product-page recommendations for CloudBSS.
 *
 * SCHEMA ASSUMPTIONS (adjust the 4 constants below if your columns differ):
 *   products      : id, tenant_id, category (string), price, active (bool), archived_at (nullable), image_url, name
 *   order_items   : order_id, product_id, quantity
 * Everything is scoped per tenant and cached. No new packages required.
 */
class ProductRecommendations
{
    private const OI_TABLE   = 'order_items';
    private const OI_ORDER   = 'order_id';
    private const OI_PRODUCT = 'product_id';
    private const OI_QTY     = 'quantity';

    private const TTL = 900; // seconds

    /** Base "buyable" filter: same tenant, live, priced, not archived. */
    private static function buyable($query, int $tenantId)
    {
        return $query->where('products.tenant_id', $tenantId)
            ->where('products.active', true)
            ->where('products.price', '>', 0)
            ->whereNull('products.archived_at');
    }

    /** Same category, excluding the current product. Ordered by units sold, then newest. */
    public static function similar(Product $product, int $limit = 12): Collection
    {
        return Cache::remember("reco:similar:{$product->id}:{$limit}", self::TTL, function () use ($product, $limit) {
            return self::topInCategory(
                $product->tenant_id,
                (string) $product->category,
                $limit,
                $product->id
            );
        });
    }

    /** Best-sellers in a category (by total units sold). Falls back to newest when no sales yet. */
    public static function topInCategory(int $tenantId, string $category, int $limit = 10, ?int $excludeId = null): Collection
    {
        $key = "reco:top:{$tenantId}:" . md5($category) . ":{$limit}:" . ($excludeId ?? 0);

        return Cache::remember($key, self::TTL, function () use ($tenantId, $category, $limit, $excludeId) {
            $oi = self::OI_TABLE;

            $q = Product::query()
                ->where('products.category', $category)
                ->when($excludeId, fn ($q) => $q->where('products.id', '!=', $excludeId))
                ->leftJoin($oi, "$oi." . self::OI_PRODUCT, '=', 'products.id')
                ->select('products.*', DB::raw("COALESCE(SUM($oi." . self::OI_QTY . '),0) as units_sold'))
                ->groupBy('products.id')
                ->orderByDesc('units_sold')
                ->orderByDesc('products.id')
                ->limit($limit);

            return self::buyable($q, $tenantId)->get();
        });
    }

    /**
     * "People also bought" — products that appear in the same orders as this one,
     * ranked by how often they co-occur. Until enough order history exists it
     * transparently fills up with category best-sellers (looks identical to the shopper).
     */
    public static function alsoBought(Product $product, int $limit = 12): Collection
    {
        return Cache::remember("reco:also:{$product->id}:{$limit}", self::TTL, function () use ($product, $limit) {
            $oi = self::OI_TABLE;

            $coIds = DB::table("$oi as a")
                ->join("$oi as b", "a." . self::OI_ORDER, '=', "b." . self::OI_ORDER)
                ->where('a.' . self::OI_PRODUCT, $product->id)
                ->where('b.' . self::OI_PRODUCT, '!=', $product->id)
                ->groupBy('b.' . self::OI_PRODUCT)
                ->orderByRaw('COUNT(*) desc')
                ->limit($limit * 3)
                ->pluck('b.' . self::OI_PRODUCT);

            $items = collect();
            if ($coIds->isNotEmpty()) {
                $byId = self::buyable(Product::query()->whereIn('products.id', $coIds), $product->tenant_id)
                    ->get()->keyBy('id');
                // keep the co-occurrence ranking order
                $items = $coIds->map(fn ($id) => $byId->get($id))->filter()->values();
            }

            // fallback: pad with category best-sellers the shopper hasn't been shown yet
            if ($items->count() < $limit) {
                $have = $items->pluck('id')->push($product->id)->all();
                $fill = self::topInCategory($product->tenant_id, (string) $product->category, $limit + count($have), $product->id)
                    ->reject(fn ($p) => in_array($p->id, $have, true));
                $items = $items->concat($fill);
            }

            return $items->take($limit)->values();
        });
    }

    /** Lightweight shape for JSON / Blade cards. */
    public static function card(Product $p): array
    {
        return [
            'id'        => $p->id,
            'name'      => $p->name,
            'category'  => $p->category,
            'price'     => (int) $p->price,
            'image_url' => $p->image_url,
        ];
    }
}
