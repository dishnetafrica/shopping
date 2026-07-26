<?php declare(strict_types=1);

namespace App\Services\Field;

use App\BOS\Bos;
use App\Kernel\Events\WorkOrderEvents;
use App\Models\InventoryUnit;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\Inventory\SerialService;

/**
 * The ONE reader/writer for jobs / work orders (Asset Lifecycle · Phase 2 · S5).
 *
 * A job is field work (installation / service / repair / inspection) at a customer site. The
 * work_orders row is the source of truth (its `status`); each change also emits an AUDIT event. The
 * payoff of Phase 1 + Phase 2 together: COMPLETING an installation marks its serial units 'installed',
 * which auto-activates their warranties (S3c) — finishing the job starts the customer's coverage with
 * no extra data entry. Tenant scoping is automatic (BelongsToTenant).
 */
class WorkOrderService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /** Create a job. A scheduled_for date starts it as 'scheduled'; otherwise 'draft'. */
    public function create(array $d): WorkOrder
    {
        $type = strtolower(trim((string) ($d['type'] ?? 'installation')));
        if (! WorkOrderEvents::isValidType($type)) {
            $type = 'installation';
        }
        $scheduledFor = ! empty($d['scheduled_for']) ? $d['scheduled_for'] : null;
        $status = $scheduledFor ? 'scheduled' : 'draft';

        $seq = WorkOrder::where('tenant_id', $this->tenant->id)->count() + 1;

        $wo = WorkOrder::create([
            'tenant_id'        => $this->tenant->id,
            'number'           => 'JOB-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'type'             => $type,
            'status'           => $status,
            'customer_id'      => $d['customer_id'] ?? null,
            'customer_name'    => $d['customer_name'] ?? null,
            'customer_phone'   => $d['customer_phone'] ?? null,
            'site_address'     => $d['site_address'] ?? null,
            'location'         => $d['location'] ?? null,
            'order_id'         => $d['order_id'] ?? null,
            'invoice_id'       => $d['invoice_id'] ?? null,
            'quotation_id'     => $d['quotation_id'] ?? null,
            'assigned_to'      => $d['assigned_to'] ?? null,
            'assigned_user_id' => $d['assigned_user_id'] ?? null,
            'scheduled_for'    => $scheduledFor,
            'notes'            => $d['notes'] ?? null,
        ]);

        $this->emit(WorkOrderEvents::CREATED, $wo, (string) ($d['created_by'] ?? ''));

        if (! empty($d['items']) && is_array($d['items'])) {
            $this->addItems($wo, $d['items']);
        }

        return $wo;
    }

    /**
     * Attach equipment to a job. Each item: ['product_id'=>?, 'inventory_unit_id'=>?, 'serial'=>?,
     * 'qty'=>?]. A serial unit fills in its own product_id / serial. Returns the count added.
     */
    public function addItems(WorkOrder $wo, array $items): int
    {
        $n = 0;
        foreach ($items as $it) {
            $pid    = isset($it['product_id']) && $it['product_id'] ? (int) $it['product_id'] : null;
            $uid    = isset($it['inventory_unit_id']) && $it['inventory_unit_id'] ? (int) $it['inventory_unit_id'] : null;
            $serial = isset($it['serial']) && $it['serial'] !== '' ? (string) $it['serial'] : null;
            $qty    = isset($it['qty']) ? (float) $it['qty'] : 1.0;
            if ($qty <= 0) {
                $qty = 1.0;
            }
            if (! $pid && ! $uid && ! $serial) {
                continue;
            }
            if ($uid && (! $serial || ! $pid)) {
                $unit = InventoryUnit::where('tenant_id', $this->tenant->id)->find($uid);
                if ($unit) {
                    $serial = $serial ?: (string) $unit->serial;
                    $pid    = $pid ?: (int) $unit->product_id;
                }
            }
            WorkOrderItem::create([
                'tenant_id'         => $this->tenant->id,
                'work_order_id'     => $wo->id,
                'product_id'        => $pid,
                'inventory_unit_id' => $uid,
                'serial'            => $serial,
                'qty'               => $qty,
            ]);
            $n++;
        }
        return $n;
    }

    /** Schedule (and optionally assign) a job. Moves a draft to 'scheduled'. */
    public function schedule(WorkOrder $wo, $when = null, ?string $assignee = null, string $by = ''): WorkOrder
    {
        if ($when) {
            $wo->scheduled_for = $when;
        }
        if ($assignee !== null && $assignee !== '') {
            $wo->assigned_to = $assignee;
        }
        if ($wo->status === 'draft') {
            $wo->status = 'scheduled';
        }
        $wo->save();
        $this->emit(WorkOrderEvents::ON_STATUS['scheduled'], $wo, $by);

        return $wo;
    }

    /** Mark a job in progress (technician on site). Idempotent. */
    public function start(WorkOrder $wo, string $by = ''): WorkOrder
    {
        if ($wo->status !== 'in_progress' && $wo->status !== 'completed') {
            $wo->status = 'in_progress';
            if (! $wo->started_at) {
                $wo->started_at = now();
            }
            $wo->save();
            $this->emit(WorkOrderEvents::ON_STATUS['in_progress'], $wo, $by);
        }
        return $wo;
    }

    /**
     * Complete a job. Idempotent. For an installation, every serial unit on the job transitions to
     * 'installed' — which auto-activates its warranty (S3c). Emits the type-specific completion event
     * (InstallationCompleted for installs). The serial transition is guarded so it can never block the
     * completion itself.
     */
    public function complete(WorkOrder $wo, string $by = ''): WorkOrder
    {
        if ($wo->status === 'completed') {
            return $wo;
        }
        $wo->status = 'completed';
        $wo->completed_at = now();
        if (! $wo->started_at) {
            $wo->started_at = now();
        }
        if ($by !== '') {
            $wo->completed_by = $by;
        }
        $wo->save();

        try {
            $unitIds = WorkOrderItem::where('tenant_id', $this->tenant->id)
                ->where('work_order_id', $wo->id)->whereNotNull('inventory_unit_id')
                ->pluck('inventory_unit_id')->map(fn ($v) => (int) $v)->all();
            if ($unitIds !== []) {
                $serial = SerialService::forTenant($this->tenant);
                $units = InventoryUnit::where('tenant_id', $this->tenant->id)->whereIn('id', $unitIds)->get();
                foreach ($units as $unit) {
                    if ((string) $unit->status !== 'installed') {
                        $serial->transition($unit, 'installed', [
                            'customer_id' => $wo->customer_id, 'created_by' => $by,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('work order install transition failed: ' . $e->getMessage());
        }

        $this->emit(WorkOrderEvents::completedEventFor((string) $wo->type), $wo, $by);

        return $wo;
    }

    /** Cancel a job. Idempotent. */
    public function cancel(WorkOrder $wo, string $by = ''): WorkOrder
    {
        if ($wo->status !== 'cancelled') {
            $wo->status = 'cancelled';
            $wo->save();
            $this->emit(WorkOrderEvents::ON_STATUS['cancelled'], $wo, $by);
        }
        return $wo;
    }

    /**
     * List jobs by any of: type, status, customer_phone, customer_id. Newest first.
     * @return \Illuminate\Support\Collection<int,WorkOrder>
     */
    public function forFilters(array $f)
    {
        $q = WorkOrder::where('tenant_id', $this->tenant->id);
        if (! empty($f['type']))           $q->where('type', strtolower((string) $f['type']));
        if (! empty($f['status']))         $q->where('status', strtolower((string) $f['status']));
        if (! empty($f['customer_phone'])) $q->where('customer_phone', (string) $f['customer_phone']);
        if (! empty($f['customer_id']))    $q->where('customer_id', (int) $f['customer_id']);

        return $q->orderByDesc('id')->limit(500)->get();
    }

    /** Emit an AUDIT event (no idempotency_key — every lifecycle change is recorded). Guarded. */
    private function emit(string $name, WorkOrder $wo, string $by): void
    {
        try {
            Bos::emit($name, [
                'work_order_id' => (int) $wo->id, 'number' => (string) $wo->number,
                'type' => (string) $wo->type, 'status' => (string) $wo->status,
                'customer_id' => (int) ($wo->customer_id ?? 0),
            ], [
                'tenant_id'    => $this->tenant->id,
                'subject_type' => 'work_order',
                'subject_id'   => (int) $wo->id,
                'actor'        => ['type' => 'user', 'name' => $by],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('work order audit event failed (' . $name . '): ' . $e->getMessage());
        }
    }
}
