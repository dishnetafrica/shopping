<?php declare(strict_types=1);

namespace App\Services\Inventory;

use App\BOS\Bos;
use App\Kernel\Events\SerialEvents;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * The ONE reader/writer for serialized inventory (Asset Lifecycle · S2a).
 *
 * The inventory_units row is the source of truth (its `status` column); each change also emits an
 * AUDIT event on the spine (SerialRegistered / SerialSold / …) — history, not state. Registration is
 * idempotent per (tenant, serial): re-registering a known serial returns the existing unit and emits
 * nothing. Tenant scoping is automatic (BelongsToTenant).
 */
class SerialService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /** Register one physical unit (In Stock). Idempotent per serial. */
    public function register(int $productId, string $serial, array $extra = []): InventoryUnit
    {
        $serial = trim($serial);

        $existing = InventoryUnit::where('tenant_id', $this->tenant->id)->where('serial', $serial)->first();
        if ($existing) {
            return $existing; // never a duplicate unit, never a second event
        }

        $unit = InventoryUnit::create([
            'tenant_id'           => $this->tenant->id,
            'product_id'          => $productId,
            'serial'              => $serial,
            'manufacturer_serial' => $extra['manufacturer_serial'] ?? null,
            'batch'               => $extra['batch'] ?? null,
            'status'              => 'in_stock',
            'location'            => $extra['location'] ?? null,
            'supplier'            => $extra['supplier'] ?? null,
            'purchase_date'       => ($extra['purchase_date'] ?? '') !== '' ? $extra['purchase_date'] : null,
            'qr_token'            => 'u_' . Str::lower(Str::random(20)),
        ]);

        // Registering serials makes a product serial-tracked — reflect that on the product.
        Product::where('tenant_id', $this->tenant->id)->where('id', $productId)
            ->where('serialized', false)->update(['serialized' => true]);

        $this->emit(SerialEvents::REGISTERED, $unit, null, 'in_stock', (string) ($extra['created_by'] ?? ''));

        return $unit;
    }

    /** Register many serials for one product. Blanks skipped; duplicates de-duped. @return InventoryUnit[] */
    public function bulkRegister(int $productId, array $serials, array $common = []): array
    {
        $out = [];
        foreach ($serials as $s) {
            $s = trim((string) $s);
            if ($s === '') {
                continue;
            }
            $out[] = $this->register($productId, $s, $common);
        }
        return $out;
    }

    /** Move a unit to a new status (source of truth = the column) and emit the matching audit event. */
    public function transition(InventoryUnit $unit, string $to, array $ctx = []): InventoryUnit
    {
        $to = strtolower(trim($to));
        if (! SerialEvents::isValidStatus($to)) {
            throw new \InvalidArgumentException('invalid status: ' . $to);
        }
        $from = (string) $unit->status;
        if ($from === $to) {
            return $unit;
        }

        $unit->status = $to;
        foreach (['location', 'order_id', 'customer_id', 'warranty_id', 'invoice_id'] as $k) {
            if (array_key_exists($k, $ctx)) {
                $unit->{$k} = $ctx[$k];
            }
        }
        $unit->save();

        $this->emit(SerialEvents::eventFor($to), $unit, $from, $to, (string) ($ctx['created_by'] ?? ''));

        return $unit;
    }

    /** @return \Illuminate\Support\Collection<int,InventoryUnit> */
    public function unitsFor(int $productId)
    {
        return InventoryUnit::where('tenant_id', $this->tenant->id)
            ->where('product_id', $productId)->orderBy('serial')->get();
    }

    /** Count of units per status for a product, e.g. ['in_stock'=>8,'sold'=>2]. */
    public function counts(int $productId): array
    {
        return InventoryUnit::where('tenant_id', $this->tenant->id)->where('product_id', $productId)
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')
            ->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Emit an AUDIT event. No idempotency_key on purpose — a unit legitimately re-enters a status
     * (sold → returned → sold), and every transition must be recorded. Guarded: an audit hiccup can
     * never break the inventory write.
     */
    private function emit(string $name, InventoryUnit $unit, ?string $from, string $to, string $by): void
    {
        try {
            Bos::emit($name, [
                'serial' => (string) $unit->serial, 'product_id' => (int) $unit->product_id,
                'from' => $from, 'to' => $to,
            ], [
                'tenant_id'    => $this->tenant->id,
                'subject_type' => 'inventory_unit',
                'subject_id'   => (int) $unit->id,
                'actor'        => ['type' => 'user', 'name' => $by],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('serial audit event failed (' . $name . '): ' . $e->getMessage());
        }
    }
}
