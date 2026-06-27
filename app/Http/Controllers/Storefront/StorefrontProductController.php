<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductRecommendations;
use Illuminate\Http\JsonResponse;

/**
 * Public storefront product-page data for CloudBSS (multi-tenant safe).
 *
 * Product uses the BelongsToTenant global scope, which blanks all rows when
 * there is no tenant context (e.g. a public route). So we DO NOT route-model-bind;
 * we take {product} as an int and resolve it withoutGlobalScopes(), then pin every
 * downstream query to THIS product's tenant_id. No cross-tenant leak.
 *
 * Routes (routes/web.php — above any /{slug} catch-all if one exists):
 *   use App\Http\Controllers\Storefront\StorefrontProductController;
 *   Route::get('/s/{product}/page', [StorefrontProductController::class, 'show']);
 *   Route::get('/s/{product}/reco', [StorefrontProductController::class, 'recommendations']);
 */
class StorefrontProductController extends Controller
{
    /** Resolve a live product across all tenants, then guard active/archived. */
    private function resolve(int $id): Product
    {
        $product = Product::withoutGlobalScopes()->findOrFail($id);
        abort_if($product->archived_at !== null || ! $product->active, 404);

        return $product;
    }

    public function show(int $product): JsonResponse
    {
        $product = $this->resolve($product);

        return response()->json([
            'product' => [
                'id'          => $product->id,
                'name'        => $product->name,
                'brand'       => $product->brand ?? null,
                'category'    => $product->category,
                'price'       => (int) $product->price,
                'mrp'         => (int) ($product->base_price ?: $product->price),
                'image_url'   => $product->image_url,
                'description' => $product->description ?? null,
                'attributes'  => $product->attributes ?? [],
            ],
            'why_shop'        => optional($product->tenant()->withoutGlobalScopes()->first())->whyShop() ?? [],
            'similar'         => ProductRecommendations::similar($product)->map([ProductRecommendations::class, 'card']),
            'top_in_category' => ProductRecommendations::topInCategory($product->tenant_id, (string) $product->category, 10, $product->id)
                                    ->map([ProductRecommendations::class, 'card']),
            'also_bought'     => ProductRecommendations::alsoBought($product)->map([ProductRecommendations::class, 'card']),
        ]);
    }

    public function recommendations(int $product): JsonResponse
    {
        $product = $this->resolve($product);

        return response()->json([
            'similar'         => ProductRecommendations::similar($product)->map([ProductRecommendations::class, 'card']),
            'top_in_category' => ProductRecommendations::topInCategory($product->tenant_id, (string) $product->category, 10, $product->id)
                                    ->map([ProductRecommendations::class, 'card']),
            'also_bought'     => ProductRecommendations::alsoBought($product)->map([ProductRecommendations::class, 'card']),
        ]);
    }
}
