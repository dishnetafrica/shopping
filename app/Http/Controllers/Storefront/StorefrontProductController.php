<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductRecommendations;
use Illuminate\Http\JsonResponse;

/**
 * Public storefront product-page data for CloudBSS.
 *
 * Routes (add to routes/web.php or routes/api.php):
 *   Route::get('/s/{product}/page', [StorefrontProductController::class, 'show']);
 *   Route::get('/s/{product}/reco', [StorefrontProductController::class, 'recommendations']);
 *
 * {product} is route-model-bound; it must belong to the active tenant.
 */
class StorefrontProductController extends Controller
{
    /** Full product detail + the three rails + the editable "Why shop" band, in one call. */
    public function show(Product $product): JsonResponse
    {
        abort_if($product->archived_at !== null || ! $product->active, 404);

        return response()->json([
            'product' => [
                'id'        => $product->id,
                'name'      => $product->name,
                'brand'     => $product->brand ?? null,
                'category'  => $product->category,
                'price'     => (int) $product->price,
                'mrp'       => (int) ($product->base_price ?: $product->price),
                'image_url' => $product->image_url,
                'description' => $product->description ?? null,
                'attributes'  => $product->attributes ?? [], // optional JSON column for the spec table
            ],
            'why_shop'        => $product->tenant->whyShop(),
            'similar'         => ProductRecommendations::similar($product)->map([ProductRecommendations::class, 'card']),
            'top_in_category' => ProductRecommendations::topInCategory($product->tenant_id, (string) $product->category, 10, $product->id)
                                    ->map([ProductRecommendations::class, 'card']),
            'also_bought'     => ProductRecommendations::alsoBought($product)->map([ProductRecommendations::class, 'card']),
        ]);
    }

    /** Just the rails (for lazy-loading below the fold). */
    public function recommendations(Product $product): JsonResponse
    {
        abort_if($product->archived_at !== null || ! $product->active, 404);

        return response()->json([
            'similar'         => ProductRecommendations::similar($product)->map([ProductRecommendations::class, 'card']),
            'top_in_category' => ProductRecommendations::topInCategory($product->tenant_id, (string) $product->category, 10, $product->id)
                                    ->map([ProductRecommendations::class, 'card']),
            'also_bought'     => ProductRecommendations::alsoBought($product)->map([ProductRecommendations::class, 'card']),
        ]);
    }
}
