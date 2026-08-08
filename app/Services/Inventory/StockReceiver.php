<?php declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\OrderCost;
use App\Models\StockMovement;
use App\Models\Tenant;

/**
 * v4.64 — putting a purchase's goods into the warehouse, exactly once.
 *
 * The one writer, so that everything which changes what a purchase says about quantity — saving it,
 * editing it, voiding it, un-voiding it — goes through the same arithmetic and cannot disagree with
 * itself.
 *
 * IDEMPOTENT BY CONSTRUCTION. It never posts a quantity; it posts the DIFFERENCE between what the
 * ledger already holds against that purchase row and what the row now says. Save the same purchase
 * twice and the second write is zero. Halve the quantity and it posts the negative half. Void it and
 * it posts the whole thing back out. There is no separate reversal path to get wrong, because a
 * reversal is only "the wanted quantity is now zero".
 *
 * That is not defensive coding for its own sake. Stock is the number an owner uses to decide what to
 * buy, and the failure being prevented here is silent: post the raw quantity on an edit and the
 * warehouse reads high for ever, with no error anywhere and nothing on any screen to suggest it.
 */
final class StockReceiver
{
    /**
     * Bring the ledger in line with one purchase line.
     *
     * @param  OrderCost  $line   the purchase row (order_costs, stage=purchase, one row per line)
     * @param  float      $wanted the quantity that row should now represent — 0.0 to take it all back
     * @return float      the signed quantity actually posted (0.0 when they already agreed)
     */
    public static function forPurchase(Tenant $t, OrderCost $line, float $wanted, string $by = ''): float
    {
        $productId = (int) ($line->product_id ?? 0);
        if ($productId <= 0) {
            return 0.0;               // money, not goods — freight, a fee, a bag of nails
        }
        // The ledger only means anything when the shop is keeping it. Writing receipts into a ledger
        // nothing reads or maintains produces a number that looks authoritative and is not.
        if (! (bool) $t->setting('stock_ledger', false)) {
            return 0.0;
        }

        $posted = self::postedFor($t, $line);
        $delta  = PurchaseStock::delta($posted, max(0.0, $wanted));
        if (! PurchaseStock::moves($delta)) {
            return 0.0;
        }

        $ledger = StockLedger::forTenant($t);
        $note   = trim('Purchase ' . ($line->supplier_name ? 'from ' . $line->supplier_name . ' ' : '') . '· ' . (string) $line->note);

        $mv = [
            'product_id' => $productId,
            'location'   => StockLedger::home($t),
            'qty'        => abs($delta),
            'ref_type'   => PurchaseStock::REF,
            'ref_id'     => (int) $line->id,
            'note'       => mb_substr($note, 0, 300),
            'created_by' => $by,
        ];

        // receive() and adjust() differ in the movement TYPE they record, and the type is what a
        // person reads on a stock report. Goods arriving is IN; goods leaving again because the
        // purchase was corrected or voided is an ADJUST, because no supplier took them back.
        //
        // array_merge and NOT `$mv + [...]`: in a PHP array union the LEFT side wins on a key
        // collision, so `$mv + ['qty' => $delta]` would keep the abs() above and a reversal would
        // ADD the goods a second time instead of taking them out.
        $delta > 0
            ? $ledger->receive($mv)
            : $ledger->adjust(array_merge($mv, ['qty' => $delta]));

        // The legacy `products.stock` column is what the product screen, the low-stock list and the
        // bot all still read. It follows the ledger at the shop's own warehouse.
        try {
            $ledger->syncProductStockCache($productId);
        } catch (\Throwable $e) {
        }

        return $delta;
    }

    /**
     * How much this purchase row has already put into the ledger.
     *
     * Summed rather than counted, because a row that has been edited twice has more than one
     * movement against it and their sum is the only honest answer.
     */
    public static function postedFor(Tenant $t, OrderCost $line): float
    {
        try {
            return (float) StockMovement::where('tenant_id', $t->id)
                ->where('ref_type', PurchaseStock::REF)
                ->where('ref_id', (int) $line->id)
                ->sum('qty');
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
