<?php

namespace App\Services\Procurement;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Suggests procurement quantities for a Demand Plan, from live data (Constitution: a read-only
 * projection — it derives, never writes). For each product:
 *   current_stock  = on-hand Kampala + Juba (stock ledger, else the products.stock cache)
 *   in_transit     = already sent to Juba but not yet received (open shipments)
 *   expected_sales = units sold in the last N days (velocity proxy for the coming cycle)
 *   suggested_qty  = max(0, expected_sales − current_stock − in_transit), rounded up to pack size
 * Every data source is guarded so a missing table/column yields 0, never a 500.
 */
final class DemandSuggestion
{
    public function __construct(private Tenant $tenant, private int $salesWindowDays = 30, private int $coverDays = 1) {}

    public static function forTenant(Tenant $t, int $salesWindowDays = 30, int $coverDays = 1): self
    {
        return new self($t, max(1, $salesWindowDays), max(1, $coverDays));
    }

    /**
     * Suggested lines for the plan, richest-demand first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function suggestLines(int $limit = 60): array
    {
        $tid = $this->tenant->id;

        // Read REAL stock from the ledger (goods receipts build it, Juba sales draw it down). We never
        // touch products.stock — that's a catalog availability flag, not inventory. With no ledger
        // movements yet the map is empty → current stock reads 0 (unknown), which Ashish confirms per
        // line; as receipts flow in, the plan reflects true on-hand automatically.
        $kampala = $this->safeMap(fn () => StockLedger::forTenant($this->tenant)->onHandMap(StockLedger::LOC_KAMPALA));
        $juba    = $this->safeMap(fn () => StockLedger::forTenant($this->tenant)->onHandMap(StockLedger::LOC_JUBA));
        $transit = $this->inTransitMap($tid);
        $sales   = $this->salesMap($tid);

        // reorder_point ships in a later migration; only select it where it exists so the plan still
        // builds on a database that has not run that migration yet.
        $hasReorder = \Illuminate\Support\Facades\Schema::hasColumn('products', 'reorder_point');
        $cols = ['id', 'name', 'stock', 'pack_size', 'moq', 'unit_label'];
        if ($hasReorder) { $cols[] = 'reorder_point'; }
        $products = Product::where('tenant_id', $tid)
            ->where(fn ($q) => $q->whereNull('active')->orWhere('active', true))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->limit(1000)
            ->get($cols);

        $lines = [];
        foreach ($products as $p) {
            $pid = (int) $p->id;
            // Real on-hand from the ledger (0 when nothing's been received yet) — never the catalog cache.
            $stockK = (float) ($kampala[$pid] ?? 0);
            $stockJ = (float) ($juba[$pid] ?? 0);
            $current = round($stockK + $stockJ, 3);
            $inTransit = round((float) ($transit[$pid] ?? 0), 3);
            // Scale window sales to a DAILY rate × days covered, so a daily plan asks for ~one day's
            // demand rather than a whole month's.
            $expected = round((float) ($sales[$pid] ?? 0) / $this->salesWindowDays * $this->coverDays, 3);

            $need = $expected - $current - $inTransit;
            $suggested = $need > 0 ? $this->roundUpToPack($need, (float) ($p->pack_size ?? 0), (float) ($p->moq ?? 0)) : 0.0;

            // Reorder-point pull-in. The Juba Stock screen flags a product when it drops to its floor;
            // this brings that same product into the plan even when recent velocity is zero, so a slow
            // mover that has quietly run down still gets bought.
            //
            // GUARDED against the empty-ledger flood: this shop's receipts ledger is 0 for most
            // products, and "current <= floor" would otherwise be true for every product that has a
            // floor set. So the pull-in only fires when there is a real on-hand / in-transit / velocity
            // signal for this product — never on a pure all-zeros row, which is unknown, not empty.
            $rp = $hasReorder && $p->reorder_point !== null ? (int) $p->reorder_point : null;
            $hasSignal = ($stockK + $stockJ + $inTransit) > 0 || $expected > 0;
            $belowReorder = $rp !== null && $hasSignal && ($current + $inTransit) <= $rp;
            if ($belowReorder) {
                // Top the suggestion up to the floor at least — Ashish edits the requested qty anyway.
                $reorderNeed = $rp - $current - $inTransit;
                if ($reorderNeed > 0) {
                    $suggested = max($suggested, $this->roundUpToPack($reorderNeed, (float) ($p->pack_size ?? 0), (float) ($p->moq ?? 0)));
                }
            }

            // Only include products that have demand, need topping up, or have hit their reorder floor.
            if ($expected <= 0 && $suggested <= 0 && ! $belowReorder) {
                continue;
            }

            $lines[] = [
                'product_id'     => $pid,
                'name'           => (string) $p->name,
                'current_stock'  => $current,
                'in_transit'     => $inTransit,
                'expected_sales' => $expected,
                'suggested_qty'  => $suggested,
                'requested_qty'  => $suggested,   // default the ask to the suggestion; Ashish edits
                'unit_label'     => (string) ($p->unit_label ?: ''),
                'below_reorder'  => $belowReorder,
                // Reorder-flagged lines rank above pure-velocity ones of the same size, so what has
                // actually hit its floor floats to the top of the plan.
                '_rank'          => $suggested * 1000 + $expected + ($belowReorder ? 500 : 0),
            ];
        }

        usort($lines, fn ($a, $b) => ($b['_rank'] <=> $a['_rank']));
        $lines = array_slice($lines, 0, max(1, $limit));
        foreach ($lines as &$l) {
            unset($l['_rank']);
        }

        return $lines;
    }

    /** Round a required quantity up to the next whole pack (or at least the MOQ). */
    private function roundUpToPack(float $need, float $pack, float $moq): float
    {
        $q = $need;
        if ($pack > 0) {
            $q = ceil($need / $pack) * $pack;
        } else {
            $q = ceil($need);
        }
        if ($moq > 0 && $q < $moq) {
            $q = $moq;
        }

        return round($q, 3);
    }

    /** Units sold per product over the sales window (velocity proxy). @return array<int,float> */
    private function salesMap(int $tid): array
    {
        return $this->safeMap(function () use ($tid) {
            $rows = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.tenant_id', $tid)
                ->where('orders.created_at', '>=', now()->subDays($this->salesWindowDays))
                ->whereRaw("lower(coalesce(orders.status,'')) not in ('cancelled','canceled','rejected','void')")
                ->whereNotNull('order_items.product_id')
                ->select('order_items.product_id', DB::raw('SUM(order_items.qty) as qty'))
                ->groupBy('order_items.product_id')
                ->get();
            $map = [];
            foreach ($rows as $r) {
                $map[(int) $r->product_id] = (float) $r->qty;
            }

            return $map;
        });
    }

    /** Qty sent to Juba but not yet received, on open (not-arrived) shipments. @return array<int,float> */
    private function inTransitMap(int $tid): array
    {
        return $this->safeMap(function () use ($tid) {
            $rows = DB::table('consignment_stock')
                ->join('shipments', 'shipments.id', '=', 'consignment_stock.shipment_id')
                ->where('consignment_stock.tenant_id', $tid)
                ->whereNull('shipments.arrived_at')
                ->whereNull('shipments.cancelled_at')
                ->whereNotNull('consignment_stock.product_id')
                ->select('consignment_stock.product_id', DB::raw('SUM(consignment_stock.qty_sent - consignment_stock.qty_received) as qty'))
                ->groupBy('consignment_stock.product_id')
                ->get();
            $map = [];
            foreach ($rows as $r) {
                $map[(int) $r->product_id] = max(0.0, (float) $r->qty);
            }

            return $map;
        });
    }

    /** @return array<int,float> */
    private function safeMap(callable $fn): array
    {
        try {
            return $fn() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
