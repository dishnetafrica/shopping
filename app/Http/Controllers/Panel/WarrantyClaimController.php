<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\Warranty\WarrantyClaimService;
use App\Services\Warranty\WarrantyService;
use Illuminate\Http\Request;

/**
 * Warranty Claims API (Asset Lifecycle · Phase 2 · S7). V3-native. Reads open to any tenant user;
 * opening and deciding a claim requires owner/manager. Each claim carries whether its warranty was in
 * coverage (active) at read time. Tenant-scoped.
 */
class WarrantyClaimController extends Controller
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

    private function present(WarrantyClaim $c): array
    {
        $out = $c->toArray();
        $status = null;
        if ($c->warranty_id) {
            $w = Warranty::where('tenant_id', $c->tenant_id)->find($c->warranty_id);
            $status = $w ? WarrantyService::effectiveStatus($w) : null;
        }
        $out['warranty_status'] = $status;
        $out['in_coverage'] = $status === 'active';
        return $out;
    }

    private function find(int $id, $u): ?WarrantyClaim
    {
        return WarrantyClaim::where('tenant_id', $u->tenant_id)->find($id);
    }

    /** GET /papi/warranty-claims?status=&warranty_id=&customer_phone= */
    public function index(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);

        $list = WarrantyClaimService::forTenant($u->tenant)
            ->forFilters($request->only(['status', 'warranty_id', 'customer_phone']));

        return response()->json(['ok' => true, 'claims' => $list->map(fn ($c) => $this->present($c))->all()]);
    }

    /** POST /papi/warranty-claims — open a claim. */
    public function open(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $d = $request->only([
            'warranty_id', 'product_id', 'inventory_unit_id', 'serial',
            'customer_id', 'customer_name', 'customer_phone', 'fault_description', 'fault',
        ]);
        $d['opened_by'] = $u->name ?? '';
        $claim = WarrantyClaimService::forTenant($u->tenant)->open($d);

        return response()->json(['ok' => true, 'claim' => $this->present($claim)]);
    }

    /** GET /papi/warranty-claims/{claim} */
    public function show(Request $request, int $claim)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $c = $this->find($claim, $u);
        if (! $c) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        return response()->json(['ok' => true, 'claim' => $this->present($c)]);
    }

    /** POST /papi/warranty-claims/{claim}/approve — resolution=repair|replace|refund. */
    public function approve(Request $request, int $claim)
    {
        return $this->decide($request, $claim, fn ($svc, $c, $u) => $svc->approve($c, [
            'resolution' => $request->input('resolution'),
            'resolution_notes' => $request->input('resolution_notes'),
            'decided_by' => $u->name ?? '',
        ]));
    }

    /** POST /papi/warranty-claims/{claim}/reject */
    public function reject(Request $request, int $claim)
    {
        return $this->decide($request, $claim, fn ($svc, $c, $u) => $svc->reject($c, [
            'resolution_notes' => $request->input('resolution_notes'),
            'decided_by' => $u->name ?? '',
        ]));
    }

    /** POST /papi/warranty-claims/{claim}/resolve — close out an approved claim. */
    public function resolve(Request $request, int $claim)
    {
        return $this->decide($request, $claim, fn ($svc, $c, $u) => $svc->resolve($c, [
            'resolution_notes' => $request->input('resolution_notes'),
        ]));
    }

    /** Shared write path: manager gate + tenant-scoped lookup + run the decision. */
    private function decide(Request $request, int $claim, callable $fn)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $c = $this->find($claim, $u);
        if (! $c) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $c = $fn(WarrantyClaimService::forTenant($u->tenant), $c, $u);

        return response()->json(['ok' => true, 'claim' => $this->present($c)]);
    }
}
