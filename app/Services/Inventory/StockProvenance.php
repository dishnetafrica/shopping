<?php declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * v4.66 — how much of a stock figure can actually be proved.
 *
 * PURE. Movement sums in, a verdict out. No models, no database.
 *
 * ── the question the owner asked ───────────────────────────────────────────────────────────────
 *
 * "What you show on the product page is not real stock. Only what we purchased is real stock,
 * against supplier."
 *
 * That is exactly right, and the parity check cannot see it. Parity proves the ledger equals the
 * product screen. Both can agree perfectly on a number that somebody typed once and copied down a
 * catalogue — 231 of 432 opening balances on this shop are exactly `100`, which is not 231 stock
 * counts that happen to agree.
 *
 * So the useful question is not "do the two systems match" but "where did each unit come from", and
 * every movement in the ledger already carries the answer in its `ref_type`. Nothing new has to be
 * recorded. It only has to be read.
 *
 * ── the four kinds of unit ─────────────────────────────────────────────────────────────────────
 *
 *   SUPPLIER   a supplier bill or a received shipment says these arrived. There is paper behind it
 *              and a person on the other end who agrees. The strongest evidence a purchase can have.
 *   COUNTED    somebody physically counted them. Stronger still — it is the only evidence that
 *              describes the shelf rather than the paperwork.
 *   SALES      left for a customer, or came back from one. Real, and always a reduction on balance.
 *   TYPED      somebody typed a number: an opening balance, the stock box on a new product, a hand
 *              edit, a CSV import. Might be perfectly accurate. Cannot be shown to be.
 *
 * An unrecognised `ref_type` counts as TYPED, deliberately. A new movement source added later is
 * unproven until somebody decides it is proof and says so here; the failure that matters is a figure
 * looking better supported than it is, so the default has to fall the other way.
 */
final class StockProvenance
{
    public const SUPPLIER = 'supplier';
    public const COUNTED  = 'counted';
    public const SALES    = 'sales';
    public const TYPED    = 'typed';

    /** Paper behind it: a supplier bill, a received shipment, a consignment delivery. */
    private const FROM_SUPPLIER = [
        'purchase',               // a line on a supplier bill — the one the owner named
        'procurement_receive',    // a demand-plan receipt against a shipment
        'trust_receiving',        // receiving with evidence attached
        'consignment_receive',    // goods received on consignment
    ];

    /** Somebody counted the shelf. */
    private const FROM_COUNT = ['trust_count'];

    /** Out to a customer, or back from one. */
    private const FROM_SALES = [
        'order_delivery', 'order_delivery_reversal', 'consignment_sale', 'pos_return',
    ];

    /** Which kind of evidence stands behind a movement. */
    public static function bucket(?string $refType): string
    {
        $r = strtolower(trim((string) $refType));

        if (in_array($r, self::FROM_SUPPLIER, true)) return self::SUPPLIER;
        if (in_array($r, self::FROM_COUNT, true))    return self::COUNTED;
        if (in_array($r, self::FROM_SALES, true))    return self::SALES;

        // backfill_product, backfill_consignment, product_create, product_edit, stock_sync — and
        // anything added in future that nobody has classified yet.
        return self::TYPED;
    }

    /**
     * Sum a product's movements into the four kinds.
     *
     * @param  array<int,array{ref_type:?string,qty:float|int|string}>  $movements
     * @return array{supplier:float,counted:float,sales:float,typed:float,on_hand:float}
     */
    public static function summarise(array $movements): array
    {
        $out = [self::SUPPLIER => 0.0, self::COUNTED => 0.0, self::SALES => 0.0, self::TYPED => 0.0];

        foreach ($movements as $m) {
            $out[self::bucket($m['ref_type'] ?? null)] += (float) ($m['qty'] ?? 0);
        }

        foreach ($out as $k => $v) {
            $out[$k] = round($v, 3);
        }
        $out['on_hand'] = round(array_sum($out), 3);

        return $out;
    }

    /**
     * What the on-hand would be if every typed unit were struck out — the owner's question, in one
     * number. Supplier receipts and physical counts, less what has been sold.
     *
     * Can be negative, and a negative is informative rather than an error: it means more has been
     * sold than the supplier bills account for, so goods were on the shelf that this system never
     * saw arrive. That is a real fact about the records, not a fault in the arithmetic.
     */
    public static function provable(array $s): float
    {
        return round(($s[self::SUPPLIER] ?? 0.0) + ($s[self::COUNTED] ?? 0.0) + ($s[self::SALES] ?? 0.0), 3);
    }

    /** The share of the on-hand figure that rests on somebody typing, 0–1. */
    public static function typedShare(array $s): float
    {
        $onHand = (float) ($s['on_hand'] ?? 0.0);
        if (abs($onHand) < 0.0005) {
            return abs((float) ($s[self::TYPED] ?? 0.0)) < 0.0005 ? 0.0 : 1.0;
        }

        return round(min(1.0, abs((float) ($s[self::TYPED] ?? 0.0)) / abs($onHand)), 3);
    }

    /**
     * A short verdict a person can sort a list by.
     *
     * A count beats everything: it is the only evidence about the shelf itself, so once a product
     * has been counted the provenance of what came before stops mattering.
     */
    public static function verdict(array $s): string
    {
        if (abs((float) ($s[self::COUNTED] ?? 0.0)) > 0.0005) {
            return 'counted';
        }
        $typed = abs((float) ($s[self::TYPED] ?? 0.0));
        if ($typed < 0.0005) {
            return 'from bills';
        }

        return self::typedShare($s) >= 0.5 ? 'mostly typed' : 'part typed';
    }

    /**
     * Which products to put in front of somebody first.
     *
     * Not the biggest gap and not the biggest quantity — the most MONEY resting on a typed number.
     * A thousand typed units of something worth 200 a carton matters; a thousand typed units of
     * something worth 2 does not, and a list sorted by quantity puts the second one on top.
     */
    public static function exposure(array $s, float $unitCost): float
    {
        return round(abs((float) ($s[self::TYPED] ?? 0.0)) * max(0.0, $unitCost), 2);
    }
}
