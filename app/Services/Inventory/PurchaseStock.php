<?php declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * v4.64 — a purchase is a goods receipt. The arithmetic of saying so.
 *
 * PURE. No models, no database, no clock.
 *
 * Until now, buying stock recorded only money. The quantity was already there — every line of the
 * Buy stock form writes its own `order_costs` row carrying `product_id`, `qty` and `unit_cost` —
 * and nothing ever turned that into stock. So the shop could say what it spent and not what arrived.
 *
 * Two pieces of arithmetic decide whether this is trustworthy, and both are here rather than inside
 * the controller, because both are the kind that goes wrong quietly:
 *
 *   DELTA    A purchase can be edited, re-saved, or voided and restored. What must be posted to the
 *            ledger is never "the quantity on the line" — it is the DIFFERENCE between what the
 *            ledger already holds for that purchase row and what the line now says. Post the raw
 *            quantity instead and every edit inflates stock by the whole amount again, silently,
 *            with no error anywhere and a warehouse that reads high for ever.
 *
 *   HOME     Which location "our warehouse" means. The ledger has always had two — a Kampala
 *            warehouse and a Juba consignment position — and four separate places hard-coded
 *            Kampala as the one that matters: where a sale is issued from, where an opening balance
 *            is seeded, and which projection the legacy `products.stock` column mirrors. A shop
 *            whose goods land in Juba would receive into one location and sell out of another, so
 *            Juba would climb for ever while Kampala went negative on every sale, and the number on
 *            the product screen — which mirrors Kampala — would drift below zero within a week.
 */
final class PurchaseStock
{
    /** Below this a quantity is a rounding artefact, not goods. */
    private const EPS = 0.0005;

    /** What the ledger tags a movement made by a purchase, so it can be found again and reversed. */
    public const REF = 'purchase';

    /**
     * Can this purchase line move stock at all?
     *
     * A line with no product cannot: you cannot add to the on-hand of something the catalogue has
     * never heard of. Those lines are real purchases and stay in the books as money — they are just
     * not goods this system can count. Freight, a bribe at the border and a bag of nails all arrive
     * this way.
     */
    public static function receivable(?int $productId, $qty): bool
    {
        return $productId !== null && $productId > 0 && (float) $qty > self::EPS;
    }

    /**
     * What to post so the ledger matches this line — the difference, never the quantity.
     *
     * Returns 0.0 when they already agree, which is what makes re-saving a purchase free rather than
     * doubling it. Negative when the line was reduced or voided, so a reversal needs no separate
     * code path: voiding is just `wanted = 0`.
     */
    public static function delta(float $alreadyPosted, float $wanted): float
    {
        // Rounded to the precision the quantity column actually holds (decimal 14,3), which is also
        // what collapses "50 vs 50.0004" to no movement at all. A second epsilon test here would be
        // the same guard twice; the one that earns its keep is moves(), which takes a raw float from
        // a caller that has not been rounded.
        return round($wanted - $alreadyPosted, 3);
    }

    /** Is there anything to write? Kept separate so a caller cannot forget the epsilon. */
    public static function moves(float $delta): bool
    {
        return abs($delta) > self::EPS;
    }

    /**
     * Which location this shop's own goods live in.
     *
     * Defaults to Kampala, because every tenant before this release was implicitly Kampala and a
     * default that silently moved them would rewrite the meaning of their existing stock. A tenant
     * whose warehouse is Juba sets `stock_home_location` and every path — receiving, issuing,
     * opening balances and the legacy column — follows it together, which is the only arrangement
     * where the numbers can agree.
     *
     * An unrecognised value falls back rather than being trusted: a typo in a settings JSON must not
     * be able to invent a third warehouse that receives goods nothing ever sells from.
     */
    public static function homeLocation($setting): string
    {
        $s = strtolower(trim((string) $setting));

        return in_array($s, StockLedger::LOCATIONS, true) ? $s : StockLedger::LOC_KAMPALA;
    }

    /**
     * A line's total, from the two numbers the form actually captures.
     *
     * The purchase screen has always shown the money and never the quantity, which is the complaint
     * this release answers: "UGX 4,900,000" cannot tell anybody how many cartons arrived.
     */
    public static function lineTotal($qty, $unitCost): float
    {
        return round(max(0.0, (float) $qty) * max(0.0, (float) $unitCost), 2);
    }

    /**
     * "50 CTN @ 98,000" — the quantity first, because that is the part nobody could see.
     */
    public static function describe($qty, ?string $unitLabel, $unitCost, ?string $currency = null): string
    {
        $q = (float) $qty;
        if ($q <= self::EPS) {
            return '';
        }
        $unit = trim((string) ($unitLabel ?: ''));
        $out  = self::number($q) . ($unit !== '' ? ' ' . $unit : '');

        $u = (float) $unitCost;
        if ($u > 0) {
            $out .= ' @ ' . self::number($u) . ($currency ? ' ' . strtoupper(trim($currency)) : '');
        }

        return $out;
    }

    /** Whole numbers stay whole; a fractional quantity keeps enough to be recognisable. */
    public static function number(float $v): string
    {
        return abs($v - round($v)) < 0.001
            ? number_format(round($v))
            : rtrim(rtrim(number_format($v, 3), '0'), '.');
    }
}
