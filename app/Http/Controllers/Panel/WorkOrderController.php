<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Kernel\Events\WorkOrderEvents;
use App\Models\WorkOrder;
use App\Services\Field\WorkOrderService;
use Illuminate\Http\Request;

/**
 * Jobs / Work Orders API (Asset Lifecycle · Phase 2 · S5). V3-native — this module has no legacy panel
 * equivalent. Reads open to any tenant user; creating and moving a job requires owner/manager. Tenant
 * scoping is automatic.
 */
class WorkOrderController extends Controller
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

    private function present(WorkOrder $wo, bool $withItems = false): array
    {
        $out = $wo->toArray();
        if ($withItems) {
            $out['items'] = $wo->items()->orderBy('id')->get()->map(fn ($it) => $it->toArray())->all();
        }
        return $out;
    }

    private function find(int $id, $u): ?WorkOrder
    {
        return WorkOrder::where('tenant_id', $u->tenant_id)->find($id);
    }

    /** GET /papi/work-orders?type=&status=&customer_phone= — list jobs. */
    public function index(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);

        $list = WorkOrderService::forTenant($u->tenant)
            ->forFilters($request->only(['type', 'status', 'customer_phone', 'customer_id']));

        return response()->json(['ok' => true, 'work_orders' => $list->map(fn ($w) => $this->present($w))->all()]);
    }

    /** POST /papi/work-orders — create a job. */
    public function create(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $d = $request->only([
            'type', 'customer_id', 'customer_name', 'customer_phone', 'site_address', 'location',
            'order_id', 'invoice_id', 'quotation_id', 'assigned_to', 'assigned_user_id',
            'scheduled_for', 'notes', 'items',
        ]);
        $d['created_by'] = $u->name ?? '';
        $wo = WorkOrderService::forTenant($u->tenant)->create($d);

        return response()->json(['ok' => true, 'work_order' => $this->present($wo, true)]);
    }

    /** GET /papi/work-orders/{workOrder} — one job with its equipment. */
    public function show(Request $request, int $workOrder)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        $wo = $this->find($workOrder, $u);
        if (! $wo) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        return response()->json(['ok' => true, 'work_order' => $this->present($wo, true)]);
    }

    /** POST /papi/work-orders/{workOrder}/schedule — set date/technician. */
    public function schedule(Request $request, int $workOrder)
    {
        return $this->mutate($request, $workOrder, function ($svc, $wo, $u, $request) {
            return $svc->schedule($wo, $request->input('scheduled_for'), $request->input('assigned_to'), $u->name ?? '');
        });
    }

    /** POST /papi/work-orders/{workOrder}/items — attach equipment. */
    public function items(Request $request, int $workOrder)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $wo = $this->find($workOrder, $u);
        if (! $wo) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $items = (array) $request->input('items', []);
        // Convenience for the V3 form: a free-text `serials` field (commas/spaces) → resolved to units.
        $serialsText = trim((string) $request->input('serials', ''));
        if ($serialsText !== '') {
            foreach (preg_split('/[\s,;]+/', $serialsText) ?: [] as $s) {
                $s = trim((string) $s);
                if ($s === '') continue;
                $unit = \App\Models\InventoryUnit::where('tenant_id', $u->tenant_id)->where('serial', $s)->first();
                $items[] = $unit ? ['inventory_unit_id' => $unit->id] : ['serial' => $s];
            }
        }
        $n = WorkOrderService::forTenant($u->tenant)->addItems($wo, $items);

        return response()->json(['ok' => true, 'added' => $n, 'work_order' => $this->present($wo->fresh(), true)]);
    }

    /** POST /papi/work-orders/{workOrder}/start */
    public function start(Request $request, int $workOrder)
    {
        return $this->mutate($request, $workOrder, fn ($svc, $wo, $u) => $svc->start($wo, $u->name ?? ''));
    }

    /** POST /papi/work-orders/{workOrder}/complete — marks installed serials & activates warranties. */
    public function complete(Request $request, int $workOrder)
    {
        return $this->mutate($request, $workOrder, fn ($svc, $wo, $u) => $svc->complete($wo, $u->name ?? ''));
    }

    /** POST /papi/work-orders/{workOrder}/cancel */
    public function cancel(Request $request, int $workOrder)
    {
        return $this->mutate($request, $workOrder, fn ($svc, $wo, $u) => $svc->cancel($wo, $u->name ?? ''));
    }

    /** Shared write path: manager gate + tenant-scoped lookup + run the mutation. */
    private function mutate(Request $request, int $workOrder, callable $fn)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $wo = $this->find($workOrder, $u);
        if (! $wo) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $result = $fn(WorkOrderService::forTenant($u->tenant), $wo, $u, $request);
        $wo = $result instanceof WorkOrder ? $result : $wo->fresh();

        return response()->json(['ok' => true, 'work_order' => $this->present($wo, true)]);
    }
}
