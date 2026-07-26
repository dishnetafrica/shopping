<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Warranty;
use App\Services\Warranty\WarrantyService;
use Illuminate\Http\Request;

/**
 * Warranty API (Asset Lifecycle · S3a). Reads open to any tenant user; activating/voiding requires
 * owner/manager. Tenant scoping is automatic.
 */
class WarrantyController extends Controller
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

    private function present(Warranty $w): array
    {
        return $w->toArray() + ['effective_status' => WarrantyService::effectiveStatus($w)];
    }

    /** POST /papi/warranties — activate (create) a warranty. */
    public function activate(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $d = $request->only([
            'product_id', 'inventory_unit_id', 'invoice_id', 'invoice_item_id', 'customer_id',
            'customer_name', 'customer_phone', 'serial', 'manufacturer', 'installation_date',
            'warranty_start', 'warranty_end', 'manufacturer_months', 'company_months',
        ]);
        $d['created_by'] = $u->name ?? '';
        $w = WarrantyService::forTenant($u->tenant)->activate($d);

        return response()->json(['ok' => true, 'warranty' => $this->present($w)]);
    }

    /** GET /papi/warranties?product_id=&inventory_unit_id=&customer_phone= — list. */
    public function index(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $list = WarrantyService::forTenant($u->tenant)->forFilters($request->only(['product_id', 'inventory_unit_id', 'customer_phone']));
        return response()->json(['ok' => true, 'warranties' => $list->map(fn ($w) => $this->present($w))->all()]);
    }

    /** GET /papi/warranties/expiring?days=30 — active warranties ending soon. */
    public function expiring(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $days = max(1, min(365, (int) $request->query('days', 30)));
        $list = WarrantyService::forTenant($u->tenant)->expiringSoon($days);
        return response()->json(['ok' => true, 'days' => $days, 'warranties' => $list->map(fn ($w) => $this->present($w))->all()]);
    }

    /** POST /papi/warranties/{warranty}/void — void a warranty. */
    public function void(Request $request, int $warranty)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $w = Warranty::where('tenant_id', $u->tenant_id)->find($warranty);
        if (! $w) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        return response()->json(['ok' => true, 'warranty' => $this->present(WarrantyService::forTenant($u->tenant)->void($w, $u->name ?? ''))]);
    }
}
