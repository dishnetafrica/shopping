<?php declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * v4.65.4 — which legacy table holds the on-hand for a location, for THIS shop.
 *
 * PURE. A home location and a location in, the name of a table out. No models, no database.
 *
 * ── why this exists ────────────────────────────────────────────────────────────────────────────
 *
 * `stock:reconcile` compares the old numbers against the new ledger and refuses to let reads switch
 * over until they agree. It had the pairing hard-coded:
 *
 *     kampala          ← products.stock
 *     juba-consignment ← Σ consignment_stock remaining
 *
 * which is right for a shop whose goods are in Kampala and wrong for one whose goods are in Juba.
 * v4.64 taught eleven other places to ask `stock_home_location` where their stock lives; this was
 * the twelfth, and I missed it. On a Juba shop the openings are seeded into Juba and `products.stock`
 * carries the same figures, so the two agree perfectly — and the command compared Juba against an
 * empty consignment table and Kampala against `products.stock` with an empty ledger, reporting the
 * whole catalogue as drifting twice over. A parity check that cries wolf is worse than none, because
 * the one thing it exists to do is be believed on the day it is right.
 *
 * ── the rule ───────────────────────────────────────────────────────────────────────────────────
 *
 *   `products.stock` is the legacy on-hand for whichever location the shop calls HOME. It is the
 *   number on the product screen, the number a sale decrements and the number the backfill seeds.
 *   It follows the shop; it is not a Kampala fact.
 *
 *   `consignment_stock` is the legacy on-hand for Juba only when Juba is AWAY — goods sent out on a
 *   shipment and not yet sold. When Juba IS home, the product screen already counts those cartons,
 *   and adding the consignment lines on top counts them a second time.
 *
 *   A location that is neither has no legacy figure at all. That is not zero. Zero is a claim that
 *   the old system said "none"; NONE is the truth, which is that the old system had no opinion —
 *   and the difference matters, because movements sitting at a location the shop does not use are a
 *   fault to be named, not a drift to be measured.
 */
final class LegacySource
{
    /** The product screen's stock column — the shop's own warehouse, wherever that is. */
    public const PRODUCTS = 'products.stock';

    /** Loose stock out on consignment, not yet sold — only ever the AWAY position. */
    public const CONSIGNMENT = 'consignment_stock';

    /** No legacy figure exists for this location. Distinct from a legacy figure of zero. */
    public const NONE = 'none';

    /**
     * An opening balance at or above this was not arrived at by counting cartons on a shelf.
     *
     * Deliberately generous. The point is not to catch every made-up figure — it is to never fire on
     * a shop whose products genuinely hold small quantities, because a warning that cries wolf on
     * every tenant is ignored by the second week and then it is not a warning at all.
     */
    private const TOO_BIG_TO_COUNT = 100.0;

    /**
     * Which table is the legacy on-hand for `$location`, for a shop whose home is `$home`.
     *
     * Both arguments are passed through `PurchaseStock::homeLocation()` first, so an unrecognised
     * or mis-cased setting falls back to Kampala here exactly as it does everywhere else rather
     * than quietly selecting NONE and reporting a clean parity against nothing at all.
     */
    public static function table(string $home, string $location): string
    {
        $home     = PurchaseStock::homeLocation($home);
        $location = PurchaseStock::homeLocation($location);

        if ($location === $home) {
            return self::PRODUCTS;
        }

        return $location === StockLedger::LOC_JUBA ? self::CONSIGNMENT : self::NONE;
    }

    /**
     * The locations worth checking for this shop, home first.
     *
     * Always both — a location with no legacy source is exactly where an orphaned movement hides,
     * and skipping it would make the report clean by not looking.
     *
     * @return array<int,string>
     */
    public static function locations(string $home): array
    {
        $home = PurchaseStock::homeLocation($home);

        return array_values(array_unique(array_merge([$home], StockLedger::LOCATIONS)));
    }

    /**
     * Would seeding openings from BOTH tables land the same goods at the same location twice?
     *
     * True only for a Juba-home shop, where `products.stock` and `consignment_stock` both describe
     * Juba. `StockBackfill` asks this before its consignment pass; `stock:reconcile` asks it to
     * explain openings already written by a backfill that ran before this was fixed.
     */
    public static function doubleSeeds(string $home): bool
    {
        return PurchaseStock::homeLocation($home) === StockLedger::LOC_JUBA;
    }

    /**
     * v4.65.6 — may the movements at `$location` be cleared away?
     *
     * Three conditions, and the third is the one that makes this safe rather than merely bounded.
     *
     *   1. It is not the shop's home. Obvious, and the only one anybody would think to check.
     *   2. It has no legacy figure — nothing in the old system describes this location, so there is
     *      no second opinion being discarded.
     *   3. HOME IS ALREADY IN PARITY. Every product's ledger figure at home already equals the
     *      number on its product screen. That is what makes the deletion provably unable to change
     *      anything the shop reads: the figures it reads are all at home, they are all correct
     *      before, and nothing at another location contributes to them.
     *
     * Without (3) this is a guess dressed as a cleanup. A product whose only opening balance was
     * seeded at the wrong location — before the home setting existed, which is exactly how this
     * mess arises — would read zero afterwards, and the deletion would be the cause. Parity at home
     * is the proof that no such product exists.
     */
    public static function mayClear(string $home, string $location, bool $homeInParity): bool
    {
        // Condition (1) is not written out, because `table()` answers PRODUCTS for the home location
        // by construction — NONE already excludes it, and it excludes a mis-cased or mistyped home
        // too, since `table()` normalises both sides before comparing. A first draft did check it
        // separately and no input could tell the two versions apart. On a destructive path that
        // reads like defence in depth; it is a comment pretending to be code, and the mutant suite
        // is what said so.
        return $homeInParity && self::table($home, $location) === self::NONE;
    }

    /**
     * Does one quantity repeat often enough across opening balances to be a default rather than a count?
     *
     * A backfill seeds whatever `products.stock` says, and says nothing about where that number came
     * from. Four hundred products opening at exactly 1000 is not four hundred stock counts that
     * happened to agree; it is a placeholder typed once and copied, and the total it produces —
     * 222,404 units of foil and cling film — is fiction that looks exactly like data. The ledger
     * cannot tell. A person reading the report can, the moment the repetition is put in front of
     * them, so this only reports the shape and never acts on it.
     *
     * @param  array<int|string,float>  $qtyByProduct  opening qty per product
     * @return array{value:float,count:int,share:float}|null  null when nothing dominates
     */
    public static function placeholder(array $qtyByProduct, float $share = 0.25): ?array
    {
        $total = count($qtyByProduct);
        if ($total < 4) {
            return null;                       // too few for a repeat to mean anything
        }

        $tally = [];
        foreach ($qtyByProduct as $q) {
            $k = (string) round((float) $q, 3);
            $tally[$k] = ($tally[$k] ?? 0) + 1;
        }
        arsort($tally);

        // Not simply the commonest value. In a real catalogue the commonest quantity is 1 or 2, and
        // it repeats across half the shop honestly — a bare frequency test would fire on every
        // tenant, every run, and be ignored within a week. The question is narrower: among the
        // openings too LARGE to have been counted by hand, does one of them repeat?
        foreach ($tally as $k => $count) {
            $value = (float) $k;
            if ($value < self::TOO_BIG_TO_COUNT) {
                continue;
            }
            if ($count / $total >= $share) {
                return ['value' => $value, 'count' => $count, 'share' => round($count / $total, 3)];
            }
        }

        return null;
    }
}
