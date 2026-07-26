<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\MaintenancePlan;
use App\Services\Field\MaintenancePlanService;
use Illuminate\Http\Request;

/**
 * Maintenance / Service Plans API (Asset Lifecycle · Phase 2 · S6). V3-native. Reads open to any tenant
 * user; creating a plan, logging a visit, and lifecycle changes require owner/manager. Tenant-scoped.
 */
class MaintenancePlanController extends Controller
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

    private function present(MaintenancePlan $p): array
    {
        return $p->toArray() + ['overdue' => MaintenancePlanService::isOverdue($p)];
    }

    private function find(int $id, $u): ?MaintenancePlan
    {
        return MaintenancePlan::where('tenant_id', $u->tenant_id)->find($id);
    }

    /** GET /papi/maintenance-plans?status=&customer_phone=&due_within=30 */
    public function index(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);

        $list = MaintenancePlanService::forTenant($u->tenant)
            ->forFilters($request->only(['status', 'customer_phone', 'due_within']));

        return response()->json(['ok' => true, 'plans' => $list->map(fn ($p) => $this->present($p))->all()]);
    }

    /** GET /papi/maintenance-plans/due?days=30 */
    public function due(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);

        $days = max(1, min(365, (int) $request->query('days', 30)));
        $list = MaintenancePlanService::forTenant($u->tenant)->dueSoon($days);

        return response()->json(['ok' => true, 'days' => $days, 'plans' => $list->map(fn ($p) => $this->present($p))->all()]);
    }

    /** POST /papi/maintenance-plans — create a plan. */
    public function create(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $d = $request->only([
            'customer_id', 'customer_name', 'customer_phone', 'product_id', 'inventory_unit_id', 'serial',
            'source_work_order_id', 'interval_months', 'last_service_at', 'next_due', 'start_date', 'notes',
        ]);
        $plan = MaintenancePlanService::forTenant($u->tenant)->create($d);

        return response()->json(['ok' => true, 'plan' => $this->present($plan)]);
    }

    /** GET /papi/maintenance-plans/{plan} */
    public function show(Request $request, int $plan)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $p = $this->find($plan, $u);
        if (! $p) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        return response()->json(['ok' => true, 'plan' => $this->present($p)]);
    }

    /** POST /papi/maintenance-plans/{plan}/log-visit — record a service visit; rolls next_due forward. */
    public function logVisit(Request $request, int $plan)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $p = $this->find($plan, $u);
        if (! $p) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $res = MaintenancePlanService::forTenant($u->tenant)->logVisit($p, [
            'date' => $request->input('date'), 'notes' => $request->input('notes'), 'created_by' => $u->name ?? '',
        ]);

        return response()->json([
            'ok' => true, 'plan' => $this->present($res['plan']),
            'work_order' => ['id' => $res['work_order']->id, 'number' => (string) $res['work_order']->number],
        ]);
    }

    /** POST /papi/maintenance-plans/{plan}/{action} — pause|resume|end. */
    public function transition(Request $request, int $plan, string $action)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $p = $this->find($plan, $u);
        if (! $p) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $svc = MaintenancePlanService::forTenant($u->tenant);
        $p = match ($action) {
            'pause'  => $svc->pause($p),
            'resume' => $svc->resume($p),
            'end'    => $svc->end($p),
            default  => null,
        };
        if ($p === null) return response()->json(['ok' => false, 'error' => 'unknown_action'], 422);

        return response()->json(['ok' => true, 'plan' => $this->present($p)]);
    }
}
