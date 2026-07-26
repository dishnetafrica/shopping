<?php declare(strict_types=1);

namespace App\Services\Field;

use App\BOS\Bos;
use App\Kernel\Events\MaintenancePlanEvents;
use App\Models\InventoryUnit;
use App\Models\MaintenancePlan;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * The ONE reader/writer for recurring maintenance plans (Asset Lifecycle · Phase 2 · S6).
 *
 * A plan tracks when a customer's system is next due for service. Logging a visit spawns a real service
 * Work Order (reusing the S5 Jobs engine, so the visit shows in the Jobs list) and rolls `next_due`
 * forward by the interval. Due-list-driven: `dueSoon()` surfaces what needs attention — no cron. The
 * maintenance_plans row is the source of truth; each change also emits an AUDIT event. Tenant-scoped.
 */
class MaintenancePlanService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /** Is a plan overdue? (active + next_due in the past). Pure. */
    public static function isOverdue(MaintenancePlan $p): bool
    {
        return $p->status === 'active' && $p->next_due && Carbon::parse($p->next_due)->endOfDay()->isPast();
    }

    /** Create a plan. next_due = explicit, else last_service + interval, else start + interval. */
    public function create(array $d): MaintenancePlan
    {
        $interval = max(1, (int) ($d['interval_months'] ?? 6));
        $start = ! empty($d['start_date']) ? Carbon::parse($d['start_date']) : Carbon::now();

        if (! empty($d['next_due'])) {
            $nextDue = Carbon::parse($d['next_due']);
        } elseif (! empty($d['last_service_at'])) {
            $nextDue = Carbon::parse($d['last_service_at'])->addMonths($interval);
        } else {
            $nextDue = $start->copy()->addMonths($interval);
        }

        $unitId = ! empty($d['inventory_unit_id']) ? (int) $d['inventory_unit_id'] : null;
        $serial = $d['serial'] ?? null;
        $pid    = ! empty($d['product_id']) ? (int) $d['product_id'] : null;
        if ($unitId && (! $serial || ! $pid)) {
            $unit = InventoryUnit::where('tenant_id', $this->tenant->id)->find($unitId);
            if ($unit) {
                $serial = $serial ?: (string) $unit->serial;
                $pid    = $pid ?: (int) $unit->product_id;
            }
        }

        $plan = MaintenancePlan::create([
            'tenant_id'            => $this->tenant->id,
            'customer_id'          => $d['customer_id'] ?? null,
            'customer_name'        => $d['customer_name'] ?? null,
            'customer_phone'       => $d['customer_phone'] ?? null,
            'product_id'           => $pid,
            'inventory_unit_id'    => $unitId,
            'serial'               => $serial,
            'source_work_order_id' => $d['source_work_order_id'] ?? null,
            'interval_months'      => $interval,
            'last_service_at'      => ! empty($d['last_service_at']) ? Carbon::parse($d['last_service_at'])->toDateString() : null,
            'next_due'             => $nextDue->toDateString(),
            'status'               => 'active',
            'notes'                => $d['notes'] ?? null,
        ]);

        $this->emit(MaintenancePlanEvents::CREATED, $plan);

        return $plan;
    }

    /**
     * Log a completed service visit: spawn a real service Work Order (S5 Jobs engine), then advance
     * next_due by the interval from the visit date. Returns ['plan'=>…, 'work_order'=>…].
     */
    public function logVisit(MaintenancePlan $plan, array $d = []): array
    {
        $visitDate = ! empty($d['date']) ? Carbon::parse($d['date']) : Carbon::now();
        $by = (string) ($d['created_by'] ?? '');

        $svc = WorkOrderService::forTenant($this->tenant);
        $wo = $svc->create([
            'type'           => 'service',
            'customer_id'    => $plan->customer_id,
            'customer_name'  => $plan->customer_name,
            'customer_phone' => $plan->customer_phone,
            'notes'          => (string) ($d['notes'] ?? ('Scheduled maintenance · plan #' . $plan->id)),
            'created_by'     => $by,
        ]);
        if ($plan->inventory_unit_id) {
            // Links the serviced unit for history. complete() below is a no-op on an already-installed
            // unit (it only installs units on installation jobs), so no warranty is re-touched.
            $svc->addItems($wo, [['inventory_unit_id' => (int) $plan->inventory_unit_id]]);
        }
        $svc->complete($wo, $by);

        $interval = max(1, (int) $plan->interval_months);
        $plan->last_service_at = $visitDate->toDateString();
        $plan->next_due        = $visitDate->copy()->addMonths($interval)->toDateString();
        $plan->work_order_id   = $wo->id;
        $plan->save();

        $this->emit(MaintenancePlanEvents::VISIT_LOGGED, $plan);

        return ['plan' => $plan, 'work_order' => $wo];
    }

    /** Active plans due within the next $days (soonest first). @return \Illuminate\Support\Collection<int,MaintenancePlan> */
    public function dueSoon(int $days = 30)
    {
        $limit = Carbon::now()->addDays($days)->toDateString();
        return MaintenancePlan::where('tenant_id', $this->tenant->id)->where('status', 'active')
            ->whereNotNull('next_due')->where('next_due', '<=', $limit)
            ->orderBy('next_due')->get();
    }

    public function pause(MaintenancePlan $p): MaintenancePlan
    {
        if ($p->status !== 'ended' && $p->status !== 'paused') { $p->status = 'paused'; $p->save(); }
        return $p;
    }

    public function resume(MaintenancePlan $p): MaintenancePlan
    {
        if ($p->status === 'paused') { $p->status = 'active'; $p->save(); }
        return $p;
    }

    public function end(MaintenancePlan $p): MaintenancePlan
    {
        if ($p->status !== 'ended') { $p->status = 'ended'; $p->save(); $this->emit(MaintenancePlanEvents::ENDED, $p); }
        return $p;
    }

    /**
     * List plans by status / customer_phone / due_within (days). Soonest-due first.
     * @return \Illuminate\Support\Collection<int,MaintenancePlan>
     */
    public function forFilters(array $f)
    {
        $q = MaintenancePlan::where('tenant_id', $this->tenant->id);
        if (! empty($f['status']))         $q->where('status', strtolower((string) $f['status']));
        if (! empty($f['customer_phone'])) $q->where('customer_phone', (string) $f['customer_phone']);
        if (! empty($f['due_within'])) {
            $q->where('status', 'active')->whereNotNull('next_due')
              ->where('next_due', '<=', Carbon::now()->addDays((int) $f['due_within'])->toDateString());
        }
        return $q->orderBy('next_due')->limit(500)->get();
    }

    /** Emit an AUDIT event. Guarded. */
    private function emit(string $name, MaintenancePlan $p): void
    {
        try {
            Bos::emit($name, [
                'plan_id' => (int) $p->id, 'customer_id' => (int) ($p->customer_id ?? 0),
                'inventory_unit_id' => (int) ($p->inventory_unit_id ?? 0),
                'interval_months' => (int) $p->interval_months, 'next_due' => $p->next_due?->toDateString(),
                'status' => (string) $p->status,
            ], [
                'tenant_id'    => $this->tenant->id,
                'subject_type' => 'maintenance_plan',
                'subject_id'   => (int) $p->id,
                'actor'        => ['type' => 'user', 'name' => ''],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('maintenance plan event failed (' . $name . '): ' . $e->getMessage());
        }
    }
}
