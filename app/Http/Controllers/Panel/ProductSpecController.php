<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Catalog\ProductSpecService;
use App\Support\Presets\SolarPreset;
use Illuminate\Http\Request;

/**
 * Product Specifications API (Asset Lifecycle · core capability). Reads are open to any tenant user;
 * catalogue edits and setting a product's values require owner/manager. Tenant scoping is automatic.
 */
class ProductSpecController extends Controller
{
    private function tenantUser(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return null;
        return $u;
    }

    private function isManager($u): bool
    {
        return $u->isOwnerLike() || in_array((string) $u->role, ['manager'], true);
    }

    /** GET /papi/spec-definitions — the catalogue. */
    public function definitions(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $defs = ProductSpecService::forTenant($u->tenant)->definitions(! $request->boolean('all'));
        return response()->json(['ok' => true, 'definitions' => $defs]);
    }

    /** POST /papi/spec-definitions — create/update a spec field (owner/manager). */
    public function defineDefinition(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $label = trim((string) $request->input('label', ''));
        if ($label === '') return response()->json(['ok' => false, 'error' => 'label_required'], 422);

        $def = ProductSpecService::forTenant($u->tenant)->defineDefinition([
            'key'           => $request->input('key'),
            'label'         => $label,
            'unit'          => $request->input('unit'),
            'data_type'     => $request->input('data_type', 'text'),
            'options'       => $request->input('options', []),
            'group'         => $request->input('group'),
            'filterable'    => $request->boolean('filterable', true),
            'searchable'    => $request->boolean('searchable', true),
            'display_order' => (int) $request->input('display_order', 0),
            'active'        => $request->boolean('active', true),
        ]);
        return response()->json(['ok' => true, 'definition' => $def]);
    }

    /** GET /papi/products/{product}/specs — a product's specs, display-ready. */
    public function productSpecs(Request $request, int $product)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $specs = ProductSpecService::forTenant($u->tenant)->specsFor($product);
        return response()->json(['ok' => true, 'product_id' => $product, 'specs' => $specs]);
    }

    /** PUT /papi/products/{product}/specs — set a product's spec values (owner/manager).
     *  Body: { specs: { wattage: 550, battery_chemistry: "LiFePO4" } }  (empty value clears a spec). */
    public function setProductSpecs(Request $request, int $product)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $pairs = (array) $request->input('specs', []);
        $written = ProductSpecService::forTenant($u->tenant)->setProductSpecs($product, $pairs);
        return response()->json([
            'ok' => true, 'product_id' => $product, 'written' => $written,
            'specs' => ProductSpecService::forTenant($u->tenant)->specsFor($product),
        ]);
    }

    /** GET /papi/product-spec-filter — product IDs matching spec filters.
     *  ?filters[wattage][min]=400&filters[battery_chemistry][eq]=LiFePO4 */
    public function filter(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $filters = (array) $request->input('filters', []);
        $ids = ProductSpecService::forTenant($u->tenant)->filterProductIds($filters);
        return response()->json(['ok' => true, 'product_ids' => $ids, 'count' => count($ids)]);
    }

    /** POST /papi/spec-definitions/seed-solar — seed the solar starter catalogue (owner/manager). */
    public function seedSolar(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $n = SolarPreset::seedSpecCatalogue($u->tenant);
        return response()->json(['ok' => true, 'seeded' => $n]);
    }
}
