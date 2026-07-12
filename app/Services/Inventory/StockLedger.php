<?php declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\StockMovement;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The ONE stock ledger (Roadmap E6.1, Constitution R2/R5 — one ledger per fact).
 *
 * Writes append-only movements and projects on-hand from them: on-hand for a SKU at a location is
 * the signed sum of that SKU's `qty` at that location. IN adds, OUT subtracts, ADJUST is a signed
 * correction, and MOVE is a linked pair (−qty at the source, +qty at the destination) so a transfer
 * never changes the company total. This class is the single writer + reader of that truth; E6.2
 * dual-writes it from the existing stock paths and E6.4 points on-hand reads at it (behind stock_v2).
 *
 * E6.1 introduces it as a NEW, UNUSED surface — no callers — so it changes no existing behaviour.
 */
class StockLedger
{
    /** Movement types. */
    public const IN = 'IN';
    public const OUT = 'OUT';
    public const MOVE = 'MOVE';
    public const ADJUST = 'ADJUST';

    /** Known stock locations (Kampala warehouse + the Juba consignment position). */
    public const LOC_KAMPALA = 'kampala';
    public const LOC_JUBA = 'juba-consignment';
    public const LOCATIONS = [self::LOC_KAMPALA, self::LOC_JUBA];

    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /**
     * Whether on-hand READS should come from the ledger for this tenant (E6.4). Requires BOTH the
     * write flag (stock_ledger — the ledger is being maintained) AND the read flag (stock_v2), so we
     * never read on-hand from a ledger that isn't being kept up to date.
     */
    public static function readsFromLedger(Tenant $t): bool
    {
        return (bool) $t->setting('stock_v2', false) && (bool) $t->setting('stock_ledger', false);
    }

    /**
     * Batched on-hand per product at one location — one query, so a product list can read projected
     * on-hand without an N+1. Returns [product_id => qty].
     *
     * @return array<int,float>
     */
    public function onHandMap(string $location): array
    {
        $rows = StockMovement::where('tenant_id', $this->tenant->id)->where('location', $location)
            ->whereNotNull('product_id')
            ->select('product_id', DB::raw('SUM(qty) as qty'))
            ->groupBy('product_id')->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = round((float) $r->qty, 3);
        }
        return $map;
    }

    /**
     * Stock IN — goods received/purchased into a location. Always stored as a positive delta.
     *
     * @param  array{product_id?:?int,sku?:?string,location:string,qty:float,ref_type?:?string,ref_id?:?int,note?:?string,created_by?:?string}  $d
     */
    public function receive(array $d): StockMovement
    {
        return $this->record(self::IN, $d, abs((float) $d['qty']));
    }

    /**
     * Stock OUT — sold/delivered/consumed out of a location. Stored as a negative delta.
     *
     * @param  array{product_id?:?int,sku?:?string,location:string,qty:float,ref_type?:?string,ref_id?:?int,note?:?string,created_by?:?string}  $d
     */
    public function issue(array $d): StockMovement
    {
        return $this->record(self::OUT, $d, -abs((float) $d['qty']));
    }

    /**
     * ADJUST — a signed correction (stock count, shrinkage, found stock). `qty` is used verbatim, so
     * a negative value reduces on-hand and a positive value raises it.
     *
     * @param  array{product_id?:?int,sku?:?string,location:string,qty:float,ref_type?:?string,ref_id?:?int,note?:?string,created_by?:?string}  $d
     */
    public function adjust(array $d): StockMovement
    {
        return $this->record(self::ADJUST, $d, (float) $d['qty']);
    }

    /**
     * MOVE qty between two locations as a linked pair: −qty leaves `from`, +qty arrives at `to`. The
     * company total is unchanged. Returns [outMovement, inMovement].
     *
     * @param  array{product_id?:?int,sku?:?string,from:string,to:string,qty:float,ref_type?:?string,ref_id?:?int,note?:?string,created_by?:?string}  $d
     * @return array{0:StockMovement,1:StockMovement}
     */
    public function move(array $d): array
    {
        $qty = abs((float) $d['qty']);
        $base = [
            'product_id' => $d['product_id'] ?? null,
            'sku'        => $d['sku'] ?? null,
            'ref_type'   => $d['ref_type'] ?? null,
            'ref_id'     => $d['ref_id'] ?? null,
            'note'       => $d['note'] ?? null,
            'created_by' => $d['created_by'] ?? null,
        ];

        return DB::transaction(function () use ($base, $d, $qty) {
            $out = $this->record(self::MOVE, $base + ['location' => $d['from']], -$qty);
            $in  = $this->record(self::MOVE, $base + ['location' => $d['to']], $qty);
            return [$out, $in];
        });
    }

    /**
     * E6.5 — derive the legacy `products.stock` cache from the ledger projection (Kampala), so the
     * column reflects the ledger truth. Raw DB update (no model events → no observer loop). CRITICAL:
     * only products that ACTUALLY have ledger movements are touched — a product absent from the ledger
     * is left alone rather than being zeroed, so this can never wipe stock for a product whose opening
     * balance hasn't been seeded yet (e.g. bulk-imported rows before a re-backfill). Returns the count
     * of products reconciled.
     */
    public function syncProductStockCache(?int $productId = null): int
    {
        $map = $this->onHandMap(self::LOC_KAMPALA);
        if ($productId !== null) {
            $map = array_key_exists($productId, $map) ? [$productId => $map[$productId]] : [];
        }

        $n = 0;
        foreach ($map as $pid => $qty) {
            \Illuminate\Support\Facades\DB::table('products')
                ->where('tenant_id', $this->tenant->id)->where('id', $pid)
                ->update(['stock' => (int) round($qty)]);
            $n++;
        }
        return $n;
    }

    /** The single writer. `$qty` is the SIGNED delta actually stored. */
    private function record(string $type, array $d, float $qty): StockMovement
    {
        return StockMovement::create([
            'tenant_id'  => $this->tenant->id,
            'product_id' => $d['product_id'] ?? null,
            'sku'        => $d['sku'] ?? null,
            'location'   => (string) $d['location'],
            'type'       => $type,
            'qty'        => $qty,
            'ref_type'   => $d['ref_type'] ?? null,
            'ref_id'     => $d['ref_id'] ?? null,
            'note'       => $d['note'] ?? null,
            'created_by' => $d['created_by'] ?? null,
        ]);
    }

    /**
     * On-hand for one SKU/product at one location = Σ signed qty.
     * Matches by product_id when given, otherwise by sku.
     */
    public function onHandFor(?int $productId, ?string $sku, string $location): float
    {
        $q = StockMovement::where('tenant_id', $this->tenant->id)->where('location', $location);
        if ($productId !== null)      $q->where('product_id', $productId);
        elseif ($sku !== null)        $q->where('sku', $sku);
        else                          return 0.0;

        return round((float) $q->sum('qty'), 3);
    }

    /**
     * On-hand projection grouped per (product_id, sku, location). Optional filters: product_id, sku,
     * location. Returns rows ['product_id','sku','location','qty'] with qty = Σ signed movements.
     *
     * @param  array{product_id?:?int,sku?:?string,location?:?string}  $filters
     * @return array<int,array{product_id:?int,sku:?string,location:string,qty:float}>
     */
    public function onHand(array $filters = []): array
    {
        $q = StockMovement::where('tenant_id', $this->tenant->id);
        if (! empty($filters['product_id'])) $q->where('product_id', (int) $filters['product_id']);
        if (! empty($filters['sku']))        $q->where('sku', (string) $filters['sku']);
        if (! empty($filters['location']))   $q->where('location', (string) $filters['location']);

        return $q->select('product_id', 'sku', 'location', DB::raw('SUM(qty) as qty'))
            ->groupBy('product_id', 'sku', 'location')
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id !== null ? (int) $r->product_id : null,
                'sku'        => $r->sku,
                'location'   => (string) $r->location,
                'qty'        => round((float) $r->qty, 3),
            ])
            ->all();
    }
}
