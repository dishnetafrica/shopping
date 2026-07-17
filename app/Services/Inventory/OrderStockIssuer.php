<?php declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;

/**
 * Perpetual inventory — Step 2 of the operational-audit backlog.
 *
 * When an order is DELIVERED, its line items leave Kampala stock (goods issue), so on-hand reflects
 * reality and oversell becomes visible. Reverses automatically if an order is moved back OUT of
 * Delivered. Writes to the ONE stock ledger (StockMovement) the same way the Juba consignment sale
 * already does, so nothing new is invented.
 *
 * SAFETY:
 *  - Gated by StockLedger::readsFromLedger() (stock_v2 + stock_ledger) AND stock_issue_on_delivery
 *    (default on). If the tenant isn't on ledger inventory yet, this is a complete no-op — the flag
 *    that makes on-hand READ from the ledger is the same one that makes delivery WRITE to it, so the
 *    two can never disagree.
 *  - Idempotent by NET outstanding quantity per order (issues − reversals), so a re-save, a double
 *    Delivered, or an un-deliver/re-deliver cycle can never double-deduct.
 *  - Skips channel 'market' (consignment sales already issue their own Juba movement).
 *  - Best-effort at the call sites: never breaks an order save.
 */
final class OrderStockIssuer
{
    private const REF_ISSUE    = 'order_delivery';
    private const REF_REVERSAL = 'order_delivery_reversal';

    public static function active(Tenant $t): bool
    {
        return StockLedger::readsFromLedger($t) && (bool) $t->setting('stock_issue_on_delivery', true);
    }

    /**
     * Resolve the order's deliverable lines to [product_id, qty, name]. Prefers the structured
     * OrderItem rows; falls back to items_json entries carrying a product_id (or an exact name match).
     * Lines with no resolvable product are dropped (you can't move stock for an unknown product).
     *
     * @return array<int,array{product_id:int,qty:float,name:string}>
     */
    public static function lines(Order $order): array
    {
        $out = [];

        foreach (OrderItem::where('order_id', $order->id)->get() as $it) {
            $pid = (int) ($it->product_id ?? 0);
            $qty = (float) $it->qty;
            if ($pid > 0 && $qty > 0) {
                $out[] = ['product_id' => $pid, 'qty' => $qty, 'name' => (string) $it->name];
            }
        }
        if ($out) return $out;

        foreach ((array) $order->items_json as $l) {
            if (! is_array($l)) continue;
            $pid  = (int) ($l['product_id'] ?? 0);
            $qty  = (float) ($l['qty'] ?? $l['quantity'] ?? 0);
            $name = (string) ($l['name'] ?? '');
            if ($pid <= 0 && $name !== '') {
                $pid = (int) (Product::where('tenant_id', $order->tenant_id)->where('name', $name)->value('id') ?? 0);
            }
            if ($pid > 0 && $qty > 0) {
                $out[] = ['product_id' => $pid, 'qty' => $qty, 'name' => $name];
            }
        }
        return $out;
    }

    /** Signed net quantity already issued for this order (issues − reversals), keyed by product. */
    private function netIssued(Tenant $t, Order $order): array
    {
        $net = [];
        $rows = StockMovement::where('tenant_id', $t->id)
            ->whereIn('ref_type', [self::REF_ISSUE, self::REF_REVERSAL])
            ->where('ref_id', $order->id)->get(['product_id', 'ref_type', 'qty']);
        foreach ($rows as $m) {
            $pid = (int) $m->product_id;
            $mag = abs((float) $m->qty);
            $net[$pid] = ($net[$pid] ?? 0) + ($m->ref_type === self::REF_ISSUE ? $mag : -$mag);
        }
        return $net;
    }

    /** Issue stock for a delivered order. Idempotent. Returns movements written. */
    public function issueForOrder(Tenant $t, Order $order): int
    {
        if (! self::active($t) || $order->channel === 'market') return 0;

        // Already actively issued (net outstanding > 0)? Then do nothing.
        foreach ($this->netIssued($t, $order) as $q) {
            if ($q > 0.0001) return 0;
        }

        $sl = StockLedger::forTenant($t);
        $n = 0;
        foreach (self::lines($order) as $ln) {
            $sl->issue([
                'product_id' => $ln['product_id'], 'sku' => null,
                'location'   => StockLedger::LOC_KAMPALA, 'qty' => $ln['qty'],
                'ref_type'   => self::REF_ISSUE, 'ref_id' => $order->id,
                'note'       => 'Delivered ' . $order->order_no . ' — ' . $ln['name'],
                'created_by' => (string) ($order->delivered_by ?? 'system'),
            ]);
            $n++;
        }
        return $n;
    }

    /** Reverse the outstanding delivery issue when an order leaves Delivered. Idempotent. */
    public function reverseForOrder(Tenant $t, Order $order): int
    {
        if (! self::active($t)) return 0;

        $sl = StockLedger::forTenant($t);
        $n = 0;
        foreach ($this->netIssued($t, $order) as $pid => $q) {
            if ($pid <= 0 || $q <= 0.0001) continue;   // nothing outstanding to reverse
            $sl->receive([
                'product_id' => $pid, 'sku' => null,
                'location'   => StockLedger::LOC_KAMPALA, 'qty' => $q,
                'ref_type'   => self::REF_REVERSAL, 'ref_id' => $order->id,
                'note'       => 'Reversed delivery ' . $order->order_no,
                'created_by' => 'system',
            ]);
            $n++;
        }
        return $n;
    }
}
