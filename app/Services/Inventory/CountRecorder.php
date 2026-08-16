<?php declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record one physical count of one product — the write behind the panel's "Record count" action.
 *
 * ── one fact, three places ──────────────────────────────────────────────────────────────────────
 *
 * A count is the strongest evidence a shop has: somebody stood in the store and looked. It lands in
 * exactly the three places StockCountCommand writes it, and for the same reasons:
 *
 *   1. a `trust_count` ADJUST on the ledger for the DELTA between the count and the book on-hand —
 *      so the ledger equals the count without pretending the history in between never happened;
 *   2. a row in `stock_openings` — the ABSOLUTE anchor Juba Stock derives from (Current = counted +
 *      bought since − sold since). An opening is not a delta and must never be summed into one;
 *   3. the `products.stock` cache, stamped exact / now / count, so every catalogue read reflects it.
 *
 * ── kept deliberately in step with StockCountCommand ────────────────────────────────────────────
 *
 * This mirrors the write block of app/Console/Commands/StockCountCommand.php rather than the command
 * calling in, because that command is the shop's proven counter and is not re-runnable in this
 * sandbox — a faithful copy that both paths can be checked against is safer here than a refactor that
 * cannot be exercised. If one changes, change the other. Unifying them (command delegates to this) is
 * a good follow-up once the panel path has been verified on staging.
 *
 * The delta is computed against the book AT THIS INSTANT, so a recount that agrees writes zero
 * movement and one that disagrees writes exactly the difference — it cannot double up. A second count
 * on a day already counted is refused unless $recount is set (the newest count then wins).
 */
final class CountRecorder
{
    public const SKIPPED   = 'skipped';    // already counted today, and recount not requested
    public const RECOUNTED = 'recounted';  // replaced a count already recorded today
    public const COUNTED   = 'counted';    // first count today

    /**
     * @return array{status:string,book:float,moved:float,counted:float,location:string}
     *         status one of the constants above; book = on-hand before the count; moved = the ADJUST
     *         delta written (0.0 when skipped or when the count agreed with the book).
     */
    public static function record(
        Tenant $tenant,
        Product $product,
        float $counted,
        ?string $location = null,
        string $by = 'Stock count',
        ?Carbon $countedAt = null,
        bool $recount = false,
        ?string $note = null,
    ): array {
        $tid       = (int) $tenant->id;
        $counted   = round(max(0.0, $counted), 3);
        $home      = StockLedger::home($tenant);
        $loc       = trim((string) ($location ?: '')) ?: $home;
        $atHome    = ($loc === $home);
        $onLedger  = StockLedger::readsFromLedger($tenant);
        $ledger    = StockLedger::forTenant($tenant);
        $countedAt = $countedAt ?: now();
        $date      = $countedAt->toDateString();

        // Same-day guard: one count per product per day unless a recount is explicitly asked for.
        $already = DB::table('stock_movements')->where('tenant_id', $tid)
            ->where('product_id', $product->id)->where('ref_type', 'trust_count')
            ->whereDate('created_at', $date)->exists();
        if ($already && ! $recount) {
            return ['status' => self::SKIPPED, 'book' => 0.0, 'moved' => 0.0, 'counted' => $counted, 'location' => $loc];
        }

        // Book on-hand now: the ledger when this tenant reads from it (or when counting away from
        // home, where the ledger is the only record), else the products.stock cache.
        $onHand = ($onLedger || ! $atHome)
            ? (float) DB::table('stock_movements')->where('tenant_id', $tid)
                ->where('product_id', $product->id)->where('location', $loc)->sum('qty')
            : (float) ($product->stock ?? 0);
        $diff = round($counted - $onHand, 3);

        DB::transaction(function () use ($ledger, $product, $loc, $diff, $counted, $onHand, $by, $tid, $onLedger, $atHome, $countedAt, $note) {
            if (abs($diff) > 0.0005) {
                $ledger->adjust([
                    'product_id' => (int) $product->id, 'location' => $loc, 'qty' => $diff,
                    'ref_type' => 'trust_count', 'ref_id' => null,
                    'note' => $note ?: ('physical count ' . $counted . ' vs book ' . $onHand),
                    'created_by' => $by,
                ]);
            }

            if (Schema::hasTable('stock_openings')) {
                DB::table('stock_openings')->insert([
                    'tenant_id' => $tid, 'product_id' => (int) $product->id, 'location' => $loc,
                    'qty' => $counted, 'counted_at' => $countedAt, 'counted_by' => $by,
                    'note' => $note, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if (! $atHome) {
                return;                          // the ledger holds it; products.stock is home's
            }
            if ($onLedger) {
                // The adjust above already made the ledger equal the count. Sync FIRST, then stamp —
                // the other order would overwrite the number we just wrote.
                $ledger->syncProductStockCache((int) $product->id);
                Product::where('tenant_id', $tid)->where('id', $product->id)->update([
                    'stock_confidence' => 'exact', 'stock_checked_at' => now(), 'stock_source' => 'count',
                ]);
            } else {
                Product::where('tenant_id', $tid)->where('id', $product->id)->update([
                    'stock' => (int) round($counted),
                    'stock_confidence' => 'exact', 'stock_checked_at' => now(), 'stock_source' => 'count',
                ]);
            }
        });

        return [
            'status'   => $already ? self::RECOUNTED : self::COUNTED,
            'book'     => round($onHand, 3),
            'moved'    => abs($diff) > 0.0005 ? $diff : 0.0,
            'counted'  => $counted,
            'location' => $loc,
        ];
    }
}
