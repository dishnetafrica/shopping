<?php declare(strict_types=1);

namespace App\Services\Warranty;

use App\BOS\Bos;
use App\Kernel\Events\WarrantyClaimEvents;
use App\Models\Tenant;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Illuminate\Support\Carbon;

/**
 * The ONE reader/writer for warranty claims (Asset Lifecycle · Phase 2 · S7).
 *
 * A claim is opened against a warranty (Phase 1) — it inherits the warranty's product / unit / serial /
 * customer so nothing is re-typed — then a manager approves (with a resolution: repair / replace /
 * refund) or rejects it. The warranty_claims row is the source of truth; each change also emits an AUDIT
 * event. Tenant scoping is automatic (BelongsToTenant).
 */
class WarrantyClaimService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /** Open a claim. Inherits product/unit/serial/customer from the warranty when one is linked. */
    public function open(array $d): WarrantyClaim
    {
        $warrantyId = (int) ($d['warranty_id'] ?? 0);
        $w = $warrantyId ? Warranty::where('tenant_id', $this->tenant->id)->find($warrantyId) : null;

        $claim = WarrantyClaim::create([
            'tenant_id'         => $this->tenant->id,
            'warranty_id'       => $w?->id,
            'product_id'        => $d['product_id'] ?? ($w->product_id ?? null),
            'inventory_unit_id' => $d['inventory_unit_id'] ?? ($w->inventory_unit_id ?? null),
            'serial'            => $d['serial'] ?? ($w->serial ?? null),
            'customer_id'       => $d['customer_id'] ?? ($w->customer_id ?? null),
            'customer_name'     => $d['customer_name'] ?? ($w->customer_name ?? null),
            'customer_phone'    => $d['customer_phone'] ?? ($w->customer_phone ?? null),
            'fault_description' => $d['fault_description'] ?? ($d['fault'] ?? null),
            'status'            => 'open',
            'opened_by'         => (string) ($d['opened_by'] ?? ''),
        ]);

        $this->emit(WarrantyClaimEvents::OPENED, $claim);

        return $claim;
    }

    /** Approve an OPEN claim with a resolution (default 'repair'). Idempotent (only acts while open). */
    public function approve(WarrantyClaim $c, array $d = []): WarrantyClaim
    {
        if ($c->status === 'open') {
            $res = (string) ($d['resolution'] ?? '');
            $c->status           = 'approved';
            $c->resolution       = WarrantyClaimEvents::isValidResolution($res) ? $res : 'repair';
            $c->resolution_notes = $d['resolution_notes'] ?? $c->resolution_notes;
            $c->decided_by       = (string) ($d['decided_by'] ?? '');
            $c->decided_at       = Carbon::now();
            $c->save();
            $this->emit(WarrantyClaimEvents::APPROVED, $c);
        }
        return $c;
    }

    /** Reject an OPEN claim. Idempotent. */
    public function reject(WarrantyClaim $c, array $d = []): WarrantyClaim
    {
        if ($c->status === 'open') {
            $c->status           = 'rejected';
            $c->resolution_notes = $d['resolution_notes'] ?? $c->resolution_notes;
            $c->decided_by       = (string) ($d['decided_by'] ?? '');
            $c->decided_at       = Carbon::now();
            $c->save();
            $this->emit(WarrantyClaimEvents::REJECTED, $c);
        }
        return $c;
    }

    /** Close out an approved claim once the repair/replacement is done. Idempotent. */
    public function resolve(WarrantyClaim $c, array $d = []): WarrantyClaim
    {
        if ($c->status === 'approved') {
            $c->status = 'resolved';
            if (! empty($d['resolution_notes'])) {
                $c->resolution_notes = $d['resolution_notes'];
            }
            $c->save();
            $this->emit(WarrantyClaimEvents::RESOLVED, $c);
        }
        return $c;
    }

    /**
     * List claims by any of: warranty_id, status, customer_phone. Newest first.
     * @return \Illuminate\Support\Collection<int,WarrantyClaim>
     */
    public function forFilters(array $f)
    {
        $q = WarrantyClaim::where('tenant_id', $this->tenant->id);
        if (! empty($f['warranty_id']))    $q->where('warranty_id', (int) $f['warranty_id']);
        if (! empty($f['status']))         $q->where('status', strtolower((string) $f['status']));
        if (! empty($f['customer_phone'])) $q->where('customer_phone', (string) $f['customer_phone']);

        return $q->orderByDesc('id')->limit(500)->get();
    }

    /** Emit an AUDIT event. Guarded. */
    private function emit(string $name, WarrantyClaim $c): void
    {
        try {
            Bos::emit($name, [
                'claim_id' => (int) $c->id, 'warranty_id' => (int) ($c->warranty_id ?? 0),
                'serial' => (string) ($c->serial ?? ''), 'status' => (string) $c->status,
                'resolution' => (string) ($c->resolution ?? ''),
            ], [
                'tenant_id'    => $this->tenant->id,
                'subject_type' => 'warranty_claim',
                'subject_id'   => (int) $c->id,
                'actor'        => ['type' => 'user', 'name' => (string) ($c->decided_by ?: $c->opened_by ?: '')],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('warranty claim event failed (' . $name . '): ' . $e->getMessage());
        }
    }
}
