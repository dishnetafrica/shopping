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
    /**
     * The shop this request is for: the {shop} slug, else the custom domain. Null when neither
     * resolves, which is the platform host with no slug — and on that host this endpoint has no
     * shop to answer for, so it must not answer at all.
     */
    private function shopTenant(): ?Tenant
    {
        $slug = trim((string) request()->route('shop'));
        if ($slug !== '') {
            $t = Tenant::where('slug', strtolower($slug))->first();
            return ($t && ($t->status ?? 'active') !== 'suspended') ? $t : null;
        }
        $t = Tenant::byCustomDomain(request()->getHost());

        return ($t && ($t->status ?? 'active') !== 'suspended') ? $t : null;
    }

    /**
     * Find a live product BELONGING TO THIS SHOP. Returns null if missing, inactive, or another
     * tenant's.
     *
     * v5.05 (launch audit P0-5). This used to be `Product::withoutGlobalScopes()->find($id)` with
     * only an `active` check and the docblock "find a live product across all tenants" — which is
     * exactly what it did: any shop's storefront served any other shop's product by integer id,
     * with price, stock and SKU, and the recommendation rails then returned more of that other
     * shop's catalogue. Product ids are sequential, so the whole platform was walkable from any
     * storefront. The scope is now pinned to the shop the URL resolved to.
     *
     * `withoutGlobalScopes()` is kept because this public route has no TenantContext when it starts
     * — the explicit tenant_id below is the guard, not the ambient one.
     */
    private function resolve(int $id): ?Product
    {
        $tenant = $this->shopTenant();
        if (! $tenant) return null;

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->whereKey($id)->first();
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
            // v5.05 — the ?debug branch used to look the id up across ALL tenants, which handed the
            // same cross-tenant oracle back to anyone who added &debug=1 after the fix above. Scoped
            // to this shop, so it can only ever describe a product this shop owns.
            if (request()->boolean('debug') && ($dbgTenant = $this->shopTenant())) {
                $row = Product::withoutGlobalScopes()
                    ->where('tenant_id', $dbgTenant->id)->whereKey($id)->first();
                return response()->json(['ok' => false, '_error' => $row
                    ? ('product ' . $id . ' is inactive (active=' . var_export((bool) $row->active, true) . ', archived=' . ($row->archived_at ?: 'null') . ')')
                    : ('no product row for id ' . $id . ' in this shop')], 404);
            }
            abort(404);
        }
        $tenant  = Tenant::withoutGlobalScopes()->find($product->tenant_id);
        // Pin the tenant context to THIS product's tenant so tenant-scoped reads below (product specs,
        // kit contents — both use the BelongsToTenant global scope) resolve on this public route, which
        // otherwise has no tenant context. Single-shop page, so this is correct and leak-free.
        if ($tenant) app(\App\Support\TenantContext::class)->set($tenant->id);
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
                // v5.05 — the comment above claimed to mirror the catalogue and had the precedence
                // BACKWARDS: the catalogue is `price ?? base_price` (StorefrontController ~1035).
                // On a row with price=0 and base_price=45000 the grid showed "Price on request"
                // while this endpoint showed 45,000 with a working Add button (audit P1-8).
                'price'       => (float) ($product->price ?? $product->base_price ?? 0),
                'mrp'         => (float) ($product->base_price ?? $product->price ?? 0),
                'image'       => $images[0] ?? '',
                'images'      => $images,
                'description' => $product->description ?? null,
                'keywords'    => $product->keywords ?? null,
                'unit'        => $product->unit_label ?? null,
                'pack_size'   => $product->pack_size !== null ? (int) $product->pack_size : null,
                'moq'         => $product->moq !== null ? (int) $product->moq : null,
                // v4.28 — the wholesale detail a buyer asks for before ordering a carton.
                // Null-coalesced rather than Schema-guarded: an unmigrated box returns null
                // from the model and the storefront simply does not render the row.
                'brand'       => $product->brand ?: null,
                'sku'         => $product->sku ?: null,
                'unit_size'   => $product->unit_size ?: null,
                'origin'      => $product->origin_country ?: null,
                'stock'       => $product->stock === null ? null : (int) $product->stock,
                // Solar / equipment vertical — how this product is sold on the storefront.
                'sell_mode'   => in_array($product->sell_mode, ['buy', 'quote'], true) ? $product->sell_mode : 'buy',
                'is_kit'      => (bool) $product->is_kit,
                'warranty'    => [
                    'manufacturer_months' => (int) ($product->warranty_manufacturer_months ?? 0),
                    'company_months'      => (int) ($product->warranty_company_months ?? 0),
                ],
            ],
            // Product specifications (Wattage, Voltage, Battery Chemistry, …) and kit contents. Both
            // degrade to [] on any failure so a solar-only feature can never blank the product page.
            'vertical' => $tenant ? \App\Support\Vertical::of($tenant) : 'grocery',
            'specs'    => $this->safe(fn () => $tenant
                ? \App\Services\Catalog\ProductSpecService::forTenant($tenant)->specsFor($product->id) : [], []),
            'kit'      => $this->safe(fn () => ($tenant && $product->is_kit)
                ? \App\Services\Catalog\KitService::forTenant($tenant)->componentsFor($product->id)
                    ->map(fn ($c) => ['name' => (string) ($c->component->name ?? ''), 'qty' => (float) $c->quantity])
                    ->filter(fn ($r) => $r['name'] !== '')->values()
                : [], []),
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
