<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Kernel\Events\SerialEvents;
use App\Models\InventoryUnit;
use App\Services\Inventory\SerialService;
use Illuminate\Http\Request;

/**
 * Serialized inventory API (Asset Lifecycle · S2a). Reads open to any tenant user; registering serials
 * and changing a unit's status require owner/manager. Tenant scoping is automatic.
 */
class SerialController extends Controller
{
    private function tenantUser(Request $request)
    {
        $u = $request->user();
        return ($u && $u->tenant_id) ? $u : null;
    }

    private function isManager($u): bool
    {
        return $u->isOwnerLike() || in_array((string) $u->role, ['manager'], true);
    }

    /** GET /papi/products/{product}/serials — a product's units + status counts. */
    public function index(Request $request, int $product)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $svc = SerialService::forTenant($u->tenant);
        return response()->json([
            'ok' => true, 'product_id' => $product,
            'units' => $svc->unitsFor($product), 'counts' => $svc->counts($product),
        ]);
    }

    /** POST /papi/products/{product}/serials — register one ('serial') or many ('serials'[]). */
    public function register(Request $request, int $product)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $svc = SerialService::forTenant($u->tenant);
        $common = [
            'manufacturer_serial' => $request->input('manufacturer_serial'),
            'batch'               => $request->input('batch'),
            'location'            => $request->input('location'),
            'supplier'            => $request->input('supplier'),
            'purchase_date'       => $request->input('purchase_date'),
            'created_by'          => $u->name ?? '',
        ];

        $serials = $request->input('serials');
        if (is_array($serials) && $serials !== []) {
            $units = $svc->bulkRegister($product, $serials, $common);
        } else {
            $one = trim((string) $request->input('serial', ''));
            if ($one === '') return response()->json(['ok' => false, 'error' => 'no_serial'], 422);
            $units = [$svc->register($product, $one, $common)];
        }

        return response()->json(['ok' => true, 'registered' => count($units), 'units' => $units]);
    }

    /** POST /papi/serials/{unit}/status — move a unit to a new lifecycle status. */
    public function status(Request $request, int $unit)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $iu = InventoryUnit::where('tenant_id', $u->tenant_id)->find($unit);
        if (! $iu) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $to = strtolower(trim((string) $request->input('status', '')));
        if (! SerialEvents::isValidStatus($to)) {
            return response()->json(['ok' => false, 'error' => 'bad_status'], 422);
        }

        $ctx = ['created_by' => $u->name ?? ''];
        foreach (['location', 'order_id', 'customer_id'] as $k) {
            if ($request->has($k)) $ctx[$k] = $request->input($k);
        }

        return response()->json(['ok' => true, 'unit' => SerialService::forTenant($u->tenant)->transition($iu, $to, $ctx)]);
    }
}
