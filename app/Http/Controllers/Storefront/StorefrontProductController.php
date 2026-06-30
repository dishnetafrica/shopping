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
    private function resolve(int $id): Product
    {
        $product = Product::withoutGlobalScopes()->findOrFail($id);
        abort_if($product->archived_at !== null || ! $product->active, 404);
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

    public function show($product): JsonResponse
    {
        $product = $this->resolve((int) $product);
        $tenant  = Tenant::withoutGlobalScopes()->find($product->tenant_id);
        $images  = $this->gallery($product);

        // Core payload always returns; trust points + rails degrade independently so a
        // recommendation failure never blanks the whole page.
        $payload = [
            'product' => [
                'id'          => $product->id,
                'name'        => $product->name,
                'category'    => $product->category,
                'price'       => (int) $product->price,
                'mrp'         => (int) ($product->base_price ?: $product->price),
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

        return response()->json($payload)->header('Cache-Control', 'no-store')->header('X-Cb-Detail', 'v2');
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
            'price' => (int) $p->price,
            'image' => $this->img($p->image_url),
        ];
    }
}
