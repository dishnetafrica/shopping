<?php

namespace App\Services\Storefront;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * "Buy Again" / "Frequently Purchased" — a personalized rail for a returning, logged-in customer.
 *
 * READ-ONLY and ADDITIVE. It derives the customer's most-repurchased catalogue products straight from
 * their own order history (orders matched by phone → order_items), ranked by how many separate orders
 * contained the product, then by recency. It writes nothing and touches no frozen service — the order
 * history that powers it already exists; this just reads it back per-customer for the first time.
 *
 * Returns lightweight rows [{id, name, times}] so the storefront can match them to its already-loaded
 * catalogue and render with current price/stock/image — no stale snapshot of the product is returned.
 */
final class BuyAgain
{
    /**
     * Top repurchased products for a customer, most-ordered first.
     *
     * @return array<int,array{id:?int,name:string,times:int}>
     */
    public function for(int $tenantId, string $phone, int $limit = 12): array
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits === '') {
            return [];
        }

        // The customer's real sales (a cancelled/rejected/void order is not a purchase to repeat).
        $orderIds = Order::where('tenant_id', $tenantId)
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->whereRaw("lower(coalesce(status,'')) not in ('cancelled','rejected','void')")
            ->pluck('id');
        if ($orderIds->isEmpty()) {
            return [];
        }

        $lines = DB::table('order_items')
            ->whereIn('order_id', $orderIds->all())
            ->select('product_id', 'name', 'qty', 'order_id')
            ->get();
        if ($lines->isEmpty()) {
            return [];
        }

        // Aggregate per product: keyed by product_id when present, else by normalised name so legacy /
        // web lines that never captured a product_id still coalesce into one "buy again" entry.
        $agg = [];
        foreach ($lines as $it) {
            $name = trim((string) $it->name);
            if ($name === '') {
                continue;
            }
            $key = $it->product_id ? ('p' . (int) $it->product_id) : ('n' . mb_strtolower($name));
            if (! isset($agg[$key])) {
                $agg[$key] = ['id' => $it->product_id ? (int) $it->product_id : null, 'name' => $name, 'orders' => [], 'last' => 0];
            }
            $agg[$key]['orders'][(int) $it->order_id] = true;   // distinct orders → "times bought"
            $agg[$key]['last'] = max($agg[$key]['last'], (int) $it->order_id);
        }

        $rows = array_map(fn ($a) => [
            'id' => $a['id'], 'name' => $a['name'], 'times' => count($a['orders']), 'last' => $a['last'],
        ], array_values($agg));

        // Most repeatedly bought first, then most recent.
        usort($rows, fn ($x, $y) => [$y['times'], $y['last']] <=> [$x['times'], $x['last']]);

        // Keep only products that still exist and are active, so the rail never shows a dead item.
        $ids = array_values(array_filter(array_map(fn ($r) => $r['id'], $rows)));
        // v4.54 — a hidden line stays off the rail even for someone who bought it before, so the
        // storefront never lists it back to them.
        $activeIds = $ids
            ? \App\Services\Catalog\Visibility::scope(Product::where('tenant_id', $tenantId)->whereIn('id', $ids)
                ->where('active', true)->whereNull('archived_at'), $tenantId)->pluck('id')->all()
            : [];
        $activeIds = array_flip($activeIds);

        $out = [];
        foreach ($rows as $r) {
            // id-linked lines must resolve to a live product; name-only lines are left for the
            // storefront to match against its loaded catalogue (it renders only what it finds).
            if ($r['id'] !== null && ! isset($activeIds[$r['id']])) {
                continue;
            }
            $out[] = ['id' => $r['id'], 'name' => $r['name'], 'times' => $r['times']];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
