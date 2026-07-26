<?php declare(strict_types=1);

namespace App\Services\Warranty;

use App\BOS\Bos;
use App\Kernel\Events\WarrantyEvents;
use App\Models\InventoryUnit;
use App\Models\Tenant;
use App\Models\Warranty;
use Illuminate\Support\Carbon;

/**
 * The ONE reader/writer for warranties (Asset Lifecycle · S3a).
 *
 * The warranties row is the source of truth (its `status` column); each change also emits an AUDIT
 * event. A warranty attaches to a serial OR a non-serialized invoice line. "Expired" is derived from
 * warranty_end (no nightly job). Tenant scoping is automatic (BelongsToTenant).
 */
class WarrantyService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /** The effective status of a warranty: void > expired (past end) > active. Pure. */
    public static function effectiveStatus(Warranty $w): string
    {
        if ($w->status === 'void') {
            return 'void';
        }
        if ($w->warranty_end && Carbon::parse($w->warranty_end)->endOfDay()->isPast()) {
            return 'expired';
        }
        return 'active';
    }

    /**
     * Activate (create) a warranty. Computes warranty_end from start + the longer of the manufacturer
     * / company terms when an explicit end isn't given. Links the serial unit back to this warranty.
     */
    public function activate(array $d): Warranty
    {
        $start = ! empty($d['warranty_start']) ? Carbon::parse($d['warranty_start']) : Carbon::now();
        $mfr     = isset($d['manufacturer_months']) && $d['manufacturer_months'] !== '' ? (int) $d['manufacturer_months'] : null;
        $company = isset($d['company_months']) && $d['company_months'] !== '' ? (int) $d['company_months'] : null;

        if (! empty($d['warranty_end'])) {
            $end = Carbon::parse($d['warranty_end']);
        } elseif ($mfr || $company) {
            $end = (clone $start)->addMonths(max((int) $mfr, (int) $company));
        } else {
            $end = null;
        }

        $unitId = isset($d['inventory_unit_id']) && $d['inventory_unit_id'] ? (int) $d['inventory_unit_id'] : null;
        $serial = $d['serial'] ?? null;
        if ($unitId && ! $serial) {
            $serial = InventoryUnit::where('tenant_id', $this->tenant->id)->where('id', $unitId)->value('serial');
        }

        $w = Warranty::create([
            'tenant_id'           => $this->tenant->id,
            'product_id'          => $d['product_id'] ?? null,
            'inventory_unit_id'   => $unitId,
            'invoice_id'          => $d['invoice_id'] ?? null,
            'invoice_item_id'     => $d['invoice_item_id'] ?? null,
            'customer_id'         => $d['customer_id'] ?? null,
            'customer_name'       => $d['customer_name'] ?? null,
            'customer_phone'      => $d['customer_phone'] ?? null,
            'serial'              => $serial,
            'manufacturer'        => $d['manufacturer'] ?? null,
            'installation_date'   => ! empty($d['installation_date']) ? $d['installation_date'] : null,
            'warranty_start'      => $start->toDateString(),
            'warranty_end'        => $end?->toDateString(),
            'manufacturer_months' => $mfr,
            'company_months'      => $company,
            'status'              => 'active',
        ]);

        // Link the serial unit back to its warranty (S2a left warranty_id nullable for exactly this).
        if ($unitId) {
            InventoryUnit::where('tenant_id', $this->tenant->id)->where('id', $unitId)
                ->update(['warranty_id' => $w->id]);
        }

        $this->emit(WarrantyEvents::ACTIVATED, $w, (string) ($d['created_by'] ?? ''));

        return $w;
    }

    /** Void a warranty (e.g. wrong data, returned unit). Idempotent. */
    public function void(Warranty $w, string $by = ''): Warranty
    {
        if ($w->status !== 'void') {
            $w->status = 'void';
            $w->save();
            $this->emit(WarrantyEvents::VOIDED, $w, $by);
        }
        return $w;
    }

    /**
     * List warranties by any of: product_id, inventory_unit_id, customer_phone. Newest first.
     * @return \Illuminate\Support\Collection<int,Warranty>
     */
    public function forFilters(array $f)
    {
        $q = Warranty::where('tenant_id', $this->tenant->id);
        if (! empty($f['product_id']))        $q->where('product_id', (int) $f['product_id']);
        if (! empty($f['inventory_unit_id'])) $q->where('inventory_unit_id', (int) $f['inventory_unit_id']);
        if (! empty($f['customer_phone']))    $q->where('customer_phone', (string) $f['customer_phone']);
        return $q->orderByDesc('id')->limit(500)->get();
    }

    /** Active warranties whose coverage ends within the next $days. @return \Illuminate\Support\Collection<int,Warranty> */
    public function expiringSoon(int $days = 30)
    {
        $today = Carbon::now()->toDateString();
        $limit = Carbon::now()->addDays($days)->toDateString();
        return Warranty::where('tenant_id', $this->tenant->id)->where('status', 'active')
            ->whereNotNull('warranty_end')->whereBetween('warranty_end', [$today, $limit])
            ->orderBy('warranty_end')->get();
    }

    /** Emit an AUDIT event (no idempotency_key — every lifecycle change is recorded). Guarded. */
    private function emit(string $name, Warranty $w, string $by): void
    {
        try {
            Bos::emit($name, [
                'warranty_id' => (int) $w->id, 'product_id' => (int) ($w->product_id ?? 0),
                'serial' => (string) ($w->serial ?? ''), 'status' => (string) $w->status,
                'warranty_end' => $w->warranty_end?->toDateString(),
            ], [
                'tenant_id'    => $this->tenant->id,
                'subject_type' => 'warranty',
                'subject_id'   => (int) $w->id,
                'actor'        => ['type' => 'user', 'name' => $by],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('warranty audit event failed (' . $name . '): ' . $e->getMessage());
        }
    }
}
