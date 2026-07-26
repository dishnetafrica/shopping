<?php declare(strict_types=1);

namespace App\Services\Inventory;

use App\BOS\Bos;
use App\Kernel\Events\SerialEvents;
use App\Models\InventoryUnit;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Warranty\WarrantyService;
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
    /** Statuses that mean a unit has left inventory into a customer's hands — warranty starts here (S3c). */
    private const WARRANTY_ON = ['sold', 'installed'];

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

        // S3c — a unit sold/installed auto-inherits its product's default warranty policy.
        if (in_array($to, self::WARRANTY_ON, true)) {
            $this->maybeActivateWarranty($unit);
        }

        return $unit;
    }

    /**
     * S3c — auto-activate a warranty when a unit is sold/installed, inheriting the PRODUCT's default
     * terms (S3b: warranty_manufacturer_months / warranty_company_months). Fires only when the product
     * actually carries a policy and the unit isn't already covered — so it never double-creates and
     * never fires for products that don't do warranties. Fully guarded: a warranty hiccup can never
     * break the inventory write. WarrantyService links the unit back (unit.warranty_id).
     */
    private function maybeActivateWarranty(InventoryUnit $unit): void
    {
        try {
            if ($unit->warranty_id) {
                return; // already covered (auto earlier, or explicitly assigned in this transition) — idempotent
            }
            $p = Product::where('tenant_id', $this->tenant->id)->find((int) $unit->product_id);
            if (! $p) {
                return;
            }
            $mfr  = $p->warranty_manufacturer_months !== null ? (int) $p->warranty_manufacturer_months : null;
            $comp = $p->warranty_company_months !== null ? (int) $p->warranty_company_months : null;
            if (! $mfr && ! $comp) {
                return; // product has no default policy — nothing to inherit
            }

            $brand = trim((string) ($p->brand ?? ''));
            $w = WarrantyService::forTenant($this->tenant)->activate([
                'product_id'          => (int) $unit->product_id,
                'inventory_unit_id'   => (int) $unit->id,
                'serial'              => (string) $unit->serial,
                'customer_id'         => $unit->customer_id ?: null,
                'invoice_id'          => $unit->invoice_id ?: null,
                'manufacturer'        => $brand !== '' ? $brand : null,
                'manufacturer_months' => $mfr,
                'company_months'      => $comp,
                'created_by'          => 'system (auto on ' . (string) $unit->status . ')',
            ]);

            // Keep the in-memory unit consistent with what WarrantyService just persisted.
            $unit->warranty_id = $w->id;
        } catch (\Throwable $e) {
            \Log::warning('serial auto-warranty failed: ' . $e->getMessage());
        }
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
     * OBSERVE-FIRST (decision D2): when a serialized product is delivered but no serials were picked
     * (there is no picking step yet), record it as evidence — never block. One observation per
     * serialized line. Fully guarded. Called from the delivery stock-issue path.
     *
     * @param  array<int,array{product_id:int,qty:float,name:string}>  $lines
     */
    public function observeDelivery(Order $order, array $lines): void
    {
        try {
            $pids = array_values(array_unique(array_filter(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines))));
            if ($pids === []) {
                return;
            }
            $serialized = Product::where('tenant_id', $this->tenant->id)->whereIn('id', $pids)
                ->where('serialized', true)->pluck('id')->map(fn ($v) => (int) $v)->all();
            if ($serialized === []) {
                return;
            }
            foreach ($lines as $l) {
                $pid = (int) ($l['product_id'] ?? 0);
                if (! in_array($pid, $serialized, true)) {
                    continue;
                }
                Bos::emit(SerialEvents::SALE_UNPICKED, [
                    'product_id' => $pid, 'order_no' => (string) ($order->order_no ?? ''), 'qty' => (float) ($l['qty'] ?? 0),
                ], [
                    'tenant_id' => $this->tenant->id, 'subject_type' => 'order', 'subject_id' => (int) $order->id,
                    'actor' => ['type' => 'system', 'name' => 'delivery'],
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('serial delivery observe failed: ' . $e->getMessage());
        }
    }

    /**
     * Reconcile a serialized product: the number of in-stock units should equal the ledger on-hand.
     * A report (drift + reconciled flag), never a hard block — surfaces where serials and stock diverge.
     */
    public function reconcile(int $productId): array
    {
        $p = Product::where('tenant_id', $this->tenant->id)->find($productId);
        $onHand = $p ? (float) $p->onHand() : 0.0;
        $inStock = (int) InventoryUnit::where('tenant_id', $this->tenant->id)
            ->where('product_id', $productId)->where('status', 'in_stock')->count();

        return [
            'product_id'        => $productId,
            'ledger_on_hand'    => round($onHand, 2),
            'in_stock_serials'  => $inStock,
            'drift'             => round($onHand - $inStock, 2),
            'reconciled'        => abs($onHand - $inStock) < 0.001,
        ];
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
