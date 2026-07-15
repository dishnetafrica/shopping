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
    public function __construct(private Tenant $tenant, private int $salesWindowDays = 30) {}

    public static function forTenant(Tenant $t, int $salesWindowDays = 30): self
    {
        return new self($t, $salesWindowDays);
    }

    /**
     * Suggested lines for the plan, richest-demand first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function suggestLines(int $limit = 60): array
    {
        $tid = $this->tenant->id;

        $kampala = $this->safeMap(fn () => StockLedger::forTenant($this->tenant)->onHandMap(StockLedger::LOC_KAMPALA));
        $juba    = $this->safeMap(fn () => StockLedger::forTenant($this->tenant)->onHandMap(StockLedger::LOC_JUBA));
        $transit = $this->inTransitMap($tid);
        $sales   = $this->salesMap($tid);

        $products = Product::where('tenant_id', $tid)
            ->where(fn ($q) => $q->whereNull('active')->orWhere('active', true))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->limit(1000)
            ->get(['id', 'name', 'stock', 'pack_size', 'moq', 'unit_label']);

        $lines = [];
        foreach ($products as $p) {
            $pid = (int) $p->id;
            // Prefer the ledger; fall back to the legacy per-product stock cache for Kampala.
            $stockK = array_key_exists($pid, $kampala) ? $kampala[$pid] : (float) ($p->stock ?? 0);
            $stockJ = (float) ($juba[$pid] ?? 0);
            $current = round($stockK + $stockJ, 3);
            $inTransit = round((float) ($transit[$pid] ?? 0), 3);
            $expected = round((float) ($sales[$pid] ?? 0), 3);

            $need = $expected - $current - $inTransit;
            $suggested = $need > 0 ? $this->roundUpToPack($need, (float) ($p->pack_size ?? 0), (float) ($p->moq ?? 0)) : 0.0;

            // Only include products that have demand or need topping up — keeps the plan focused.
            if ($expected <= 0 && $suggested <= 0) {
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
                '_rank'          => $suggested * 1000 + $expected,
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
