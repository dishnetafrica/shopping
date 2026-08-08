<?php declare(strict_types=1);

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * v4.65.6 — movements filed at a location the shop does not use.
 *
 * ── how a shop ends up with these ──────────────────────────────────────────────────────────────
 *
 * Before v4.64 every stock path in the system hard-coded Kampala. A shop whose goods are in Juba ran
 * for weeks with its product creates, stock edits and delivery issues all mirrored into a warehouse
 * it has never seen. Nothing was wrong with any of them at the time — Kampala was the only location
 * the code could name. Then `stock_home_location` was set, every path moved to Juba together, and
 * the opening balances were seeded there from `products.stock`.
 *
 * `products.stock` is the accumulated result of exactly those creates, edits and deliveries. So the
 * Juba opening ALREADY CONTAINS the net effect of every movement stranded at Kampala. The Kampala
 * rows are not a second set of goods and they are not lost history — they are a superseded mirror of
 * events whose source records (products, orders) are untouched and still authoritative.
 *
 * ── why they are worth removing at all ─────────────────────────────────────────────────────────
 *
 * They are inert. On-hand, sales, purchases and the product screen all read the home location, so
 * nothing displays them and no total includes them. The cost is narrower and worse: `stock:reconcile`
 * fails for ever, and a check that always fails is a check nobody reads — including on the day it
 * has something real to say.
 *
 * ── why deleting, and not adjusting ────────────────────────────────────────────────────────────
 *
 * A compensating ADJUST of −214,612 would keep the ledger append-only and would appear in every
 * movement report as a catastrophic write-off on the day it ran. Nothing was written off. Deleting a
 * superseded mirror asserts nothing; posting a correction asserts an event that did not happen.
 */
class MisfiledStock
{
    /**
     * Locations holding movements that no legacy figure describes.
     *
     * @return array<string,array{units:float,rows:int}>
     */
    public function locations(int $tenantId, string $home): array
    {
        $out = [];
        foreach (LegacySource::locations($home) as $loc) {
            if (LegacySource::table($home, $loc) !== LegacySource::NONE) {
                continue;
            }
            $r = DB::table('stock_movements')->where('tenant_id', $tenantId)->where('location', $loc)
                ->selectRaw('COUNT(*) as n, COALESCE(SUM(qty), 0) as units')->first();
            if ($r && (int) $r->n > 0) {
                $out[$loc] = ['units' => round((float) $r->units, 3), 'rows' => (int) $r->n];
            }
        }
        return $out;
    }

    /**
     * What wrote the movements at a location — grouped by ref_type, movement type and hand.
     *
     * The per-product table cannot answer this and it is the only question worth asking. An opening
     * balance seeded before the home location existed is a superseded mirror; a delivery issued out
     * is a real event. They are identical in a quantity column and they are not the same decision,
     * so the decision is made from this and not from the quantities.
     *
     * @return array<int,array<string,string>>
     */
    public function wroteThese(int $tenantId, string $location): array
    {
        return DB::table('stock_movements')
            ->where('tenant_id', $tenantId)->where('location', $location)
            ->select('ref_type', 'type', 'created_by',
                DB::raw('COUNT(*) as n'), DB::raw('SUM(qty) as units'),
                DB::raw('MIN(created_at) as first_at'), DB::raw('MAX(created_at) as last_at'))
            ->groupBy('ref_type', 'type', 'created_by')
            ->orderByRaw('SUM(qty) DESC')
            ->get()
            ->map(fn ($r) => [
                'ref_type'   => (string) ($r->ref_type ?? '—'),
                'type'       => (string) $r->type,
                'created_by' => (string) ($r->created_by ?? '—'),
                'n'          => (string) (int) $r->n,
                'units'      => self::num((float) $r->units),
                // v4.65.6 — to the MINUTE, not the day. The question these dates exist to answer is
                // "has this stopped?", and a shop that set its home location this morning cannot tell
                // from a date alone whether the last stray write was before or after that moment.
                'first_at'   => substr((string) $r->first_at, 0, 16),
                'last_at'    => substr((string) $r->last_at, 0, 16),
            ])
            ->all();
    }

    /**
     * Is every product's ledger figure at home already equal to the number on its product screen?
     *
     * This is the safety proof for clearing anything anywhere else. If it holds, the figures the shop
     * reads are correct BEFORE the deletion and are composed entirely of movements at home, so a
     * deletion at another location cannot change one of them. If it does not hold, some product may
     * be depending on a movement filed in the wrong place, and clearing would be the thing that
     * broke it.
     *
     * @return array{ok:bool,drifting:int,products:int}
     */
    public function homeParity(int $tenantId, string $home, float $tol = 0.0): array
    {
        $legacy = [];
        DB::table('products')->where('tenant_id', $tenantId)->select('id', 'stock')
            ->orderBy('id')->chunkById(1000, function ($rows) use (&$legacy) {
                foreach ($rows as $p) { $legacy[(int) $p->id] = (float) ($p->stock ?? 0); }
            });

        $ledger = [];
        DB::table('stock_movements')->where('tenant_id', $tenantId)->where('location', $home)
            ->whereNotNull('product_id')
            ->select('product_id', DB::raw('SUM(qty) as qty'))->groupBy('product_id')->get()
            ->each(function ($r) use (&$ledger) { $ledger[(int) $r->product_id] = round((float) $r->qty, 3); });

        $ids = array_unique(array_merge(array_keys($legacy), array_keys($ledger)));
        $drifting = 0;
        foreach ($ids as $pid) {
            if (abs(round(($ledger[$pid] ?? 0.0) - ($legacy[$pid] ?? 0.0), 3)) > $tol) {
                $drifting++;
            }
        }

        return ['ok' => $drifting === 0, 'drifting' => $drifting, 'products' => count($ids)];
    }

    /**
     * Remove every movement at `$location`. Bounded by tenant AND location, and by nothing else —
     * deliberately, because a filter on ref_type here would leave a partial location behind and a
     * partial location is harder to reason about than either a full one or an empty one.
     *
     * The caller is responsible for having checked `LegacySource::mayClear()` first; this asks again
     * anyway, because the one thing worse than no guard is a guard that can be walked past.
     *
     * @return int rows removed, or -1 when the guard refuses
     */
    public function clear(int $tenantId, string $home, string $location, float $tol = 0.0): int
    {
        if (! LegacySource::mayClear($home, $location, $this->homeParity($tenantId, $home, $tol)['ok'])) {
            return -1;
        }

        return DB::table('stock_movements')->where('tenant_id', $tenantId)
            ->where('location', $location)->delete();
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }
}
