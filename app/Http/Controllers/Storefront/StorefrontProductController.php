<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\ProductRecommendations;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Public storefront product-page data for CloudBSS (multi-tenant safe).
 *
 * Product uses the BelongsToTenant global scope, which blanks all rows when there is no
 * tenant context (public route). So we DO NOT route-model-bind; we take {product} as an int,
 * resolve withoutGlobalScopes(), then pin every downstream query to THIS product's tenant_id.
 *
 * Route (inside the /{shop} storefront group, before the /{shop} landing catch-all):
 *   Route::get('/{shop}/product/{product}', [StorefrontProductController::class, 'show'])
 *        ->where('shop', $shopSlug)->where('product', '[0-9]+');
 */
class StorefrontProductController extends Controller
{
    /** Find a live product across all tenants. Returns null if missing or inactive.
     *  Matches the catalogue, which lists active products (it doesn't exclude archived),
     *  so anything visible in the grid will also open here. */
    private function resolve(int $id): ?Product
    {
        $product = Product::withoutGlobalScopes()->find($id);
        if (! $product || ! $product->active) return null;
        return $product;
    }

    /** Mirror StorefrontController::imageUrl so detail images resolve like the catalogue. */
    private function img(?string $value): string
    {
        if (! $value) return '';
        return Str::startsWith($value, ['http://', 'https://', '/']) ? $value : Storage::disk('public')->url($value);
    }

    private function gallery(Product $p): array
    {
        $imgs = [];
        foreach ([$p->image_url, $p->gallery_1, $p->gallery_2, $p->gallery_3] as $raw) {
            $u = $this->img($raw);
            if ($u !== '' && ! in_array($u, $imgs, true)) $imgs[] = $u;
        }
        return $imgs;
    }

    public function show(): JsonResponse
    {
        $id      = (int) request()->route('product');
        $product = $this->resolve($id);
        if (! $product) {
            if (request()->boolean('debug')) {
                $row = Product::withoutGlobalScopes()->find($id);
                return response()->json(['ok' => false, '_error' => $row
                    ? ('product ' . $id . ' is inactive (active=' . var_export((bool) $row->active, true) . ', archived=' . ($row->archived_at ?: 'null') . ')')
                    : ('no product row for id ' . $id)], 404);
            }
            abort(404);
        }
        $tenant  = Tenant::withoutGlobalScopes()->find($product->tenant_id);
        $images  = $this->gallery($product);

        // Core payload always returns; trust points + rails degrade independently so a
        // recommendation failure never blanks the whole page.
        $payload = [
            'product' => [
                'id'          => $product->id,
                'name'        => $product->name,
                'category'    => $product->category,
                // SINGLE SOURCE OF TRUTH: mirror the catalogue endpoint EXACTLY
                // (StorefrontController::catalogue -> Price_UGX = (float)(base_price ?? price)).
                // The old (int) cast truncated decimals ($2.74 -> $2.00) and ignored
                // base_price, so the detail page disagreed with the listing/cart. Do NOT
                // cast prices to int, and do NOT read a different field here.
                'price'       => (float) ($product->base_price ?? $product->price ?? 0),
                'mrp'         => (float) ($product->base_price ?? $product->price ?? 0),
                'image'       => $images[0] ?? '',
                'images'      => $images,
                'description' => $product->description ?? null,
                'keywords'    => $product->keywords ?? null,
                'unit'        => $product->unit_label ?? null,
                'pack_size'   => $product->pack_size !== null ? (int) $product->pack_size : null,
                'moq'         => $product->moq !== null ? (int) $product->moq : null,
                'stock'       => $product->stock === null ? null : (int) $product->stock,
            ],
            'why_shop'        => $this->safe(fn () => $tenant ? $tenant->whyShop() : [], []),
            'similar'         => $this->safe(fn () => ProductRecommendations::similar($product)->map([$this, 'recoCard'])->values(), []),
            'top_in_category' => $this->safe(fn () => ProductRecommendations::topInCategory($product->tenant_id, (string) $product->category, 10, $product->id)->map([$this, 'recoCard'])->values(), []),
            'also_bought'     => $this->safe(fn () => ProductRecommendations::alsoBought($product)->map([$this, 'recoCard'])->values(), []),
        ];
        if ($this->lastError && request()->boolean('debug')) {
            $payload['_error'] = $this->lastError;
        }

        return response()->json($payload)->header('Cache-Control', 'no-store')->header('X-Cb-Detail', 'v4');
    }

    private ?string $lastError = null;

    /** Run a producer; on any failure record the message and return the fallback. */
    private function safe(callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->lastError = $this->lastError ?: ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            \Illuminate\Support\Facades\Log::warning('storefront.product_detail', ['err' => $e->getMessage()]);
            return $fallback;
        }
    }

    /** Reco card with a storefront-resolved image. */
    public function recoCard(Product $p): array
    {
        return [
            'id'    => $p->id,
            'name'  => $p->name,
            // Same rule as above — float, base_price precedence, never (int).
            'price' => (float) ($p->base_price ?? $p->price ?? 0),
            'image' => $this->img($p->image_url),
        ];
    }
}
