<?php declare(strict_types=1);

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * Roadmap E6.3 — seed the stock ledger so its projected on-hand equals today's numbers.
 *
 * Before the ledger can become the source of on-hand (E6.4), it must already reproduce the current
 * figures. This writes ONE opening-balance IN movement per stocked product (at Kampala) and per
 * consignment line's remaining quantity (at Juba), using the same signed rule as StockLedger. It is
 * idempotent — an opening row is keyed by (ref_type, ref_id) and skipped if already present — so a
 * re-run (or running after some movements already exist) never double-counts. Raw DB is used so the
 * backfill runs tenant-agnostically from a migration/CLI without the tenant global scope.
 *
 * v4.65.4 — the consignment pass is skipped for a shop whose home IS Juba. Idempotence protected
 * against seeding the same ROW twice; it could not see that on a Juba shop the two passes seed the
 * same GOODS twice, from different tables, into the same location.
 */
class StockBackfill
{
    public const REF_PRODUCT = 'backfill_product';
    public const REF_CONSIGNMENT = 'backfill_consignment';

    /** Backfill every tenant. Returns the number of opening movements written. */
    public function run(): int
    {
        $n = 0;
        DB::table('tenants')->orderBy('id')->chunkById(200, function ($tenants) use (&$n) {
            foreach ($tenants as $t) {
                $n += $this->runForTenant((int) $t->id);
            }
        });
        return $n;
    }

    /** Backfill one tenant. Returns the number of opening movements written. */
    public function runForTenant(int $tenantId): int
    {
        $now = now();
        $inserted = 0;

        // v4.64 — the opening balance has to land in the SAME location the shop sells out of and
        // receives purchases into, or the ledger starts life split across two warehouses and every
        // figure disagrees from the first day.
        $home = $this->home($tenantId);

        // --- Home: each stocked product's current stock is its opening on-hand ---
        // (Called "Kampala" here until v4.65.4, which is what it was for every tenant that existed
        // when it was written and is why this went on reading as correct for so long.)
        $seenProduct = array_flip(array_map('intval',
            DB::table('stock_movements')->where('tenant_id', $tenantId)
                ->where('ref_type', self::REF_PRODUCT)->pluck('ref_id')->all()));

        DB::table('products')->where('tenant_id', $tenantId)->where('stock', '>', 0)
            ->orderBy('id')->chunkById(500, function ($rows) use (&$inserted, $tenantId, $now, $seenProduct, $home) {
                $batch = [];
                foreach ($rows as $p) {
                    if (isset($seenProduct[(int) $p->id])) continue;
                    $batch[] = [
                        'tenant_id' => $tenantId, 'product_id' => $p->id, 'sku' => $p->sku,
                        'location' => $home, 'type' => StockLedger::IN, 'qty' => (float) $p->stock,
                        'ref_type' => self::REF_PRODUCT, 'ref_id' => $p->id, 'note' => 'Opening balance',
                        'created_by' => 'backfill', 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                if ($batch) { DB::table('stock_movements')->insert($batch); $inserted += count($batch); }
            });

        // --- Juba: each consignment line's remaining quantity is its opening on-hand ---
        //
        // v4.65.4 — unless Juba IS this shop's home, in which case the pass above has already seeded
        // those cartons at this very location out of products.stock, and doing it again counts the
        // same goods twice. The two tables describe the same shelf for a Juba shop and two different
        // shelves for a Kampala one; only the second case has anything left to seed.
        if (LegacySource::doubleSeeds($home)) {
            return $inserted;
        }

        $seenCons = array_flip(array_map('intval',
            DB::table('stock_movements')->where('tenant_id', $tenantId)
                ->where('ref_type', self::REF_CONSIGNMENT)->pluck('ref_id')->all()));

        DB::table('consignment_stock')->where('tenant_id', $tenantId)
            ->orderBy('id')->chunkById(500, function ($rows) use (&$inserted, $tenantId, $now, $seenCons) {
                $batch = [];
                foreach ($rows as $k) {
                    if (isset($seenCons[(int) $k->id])) continue;
                    $base = $k->qty_received !== null ? (float) $k->qty_received : (float) $k->qty_sent;
                    $remaining = round(max(0.0, $base - (float) $k->qty_sold), 3);
                    if ($remaining <= 0) continue;
                    $batch[] = [
                        'tenant_id' => $tenantId, 'product_id' => $k->product_id, 'sku' => null,
                        'location' => StockLedger::LOC_JUBA, 'type' => StockLedger::IN, 'qty' => $remaining,
                        'ref_type' => self::REF_CONSIGNMENT, 'ref_id' => $k->id, 'note' => 'Opening balance: ' . $k->name,
                        'created_by' => 'backfill', 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                if ($batch) { DB::table('stock_movements')->insert($batch); $inserted += count($batch); }
            });

        return $inserted;
    }

    /**
     * v4.65.4 — un-seed the consignment openings a pre-v4.65.4 backfill wrote on a Juba-home shop.
     *
     * Deleting from an append-only ledger needs a reason better than tidiness, and this has one: these
     * rows are not history. Nothing happened when they were written — no goods moved, no purchase was
     * recorded, no sale went out. They are a backfill's own statement of an opening position, that
     * backfill has since been shown to have counted the same cartons twice, and the rows it wrote are
     * identified exactly and only by `ref_type = backfill_consignment`. Withdrawing a wrong statement
     * is not rewriting a history; an ADJUST would be, because it would assert that a real correction
     * of that size occurred on the day the command was run.
     *
     * Refuses on a shop where Juba is NOT home, because there the consignment openings are the only
     * record of the away position and deleting them would empty it.
     *
     * @return int rows removed, or -1 when the shop's home is not Juba and nothing was touched
     */
    public function dropConsignmentOpenings(int $tenantId): int
    {
        if (! LegacySource::doubleSeeds($this->home($tenantId))) {
            return -1;
        }

        return DB::table('stock_movements')->where('tenant_id', $tenantId)
            ->where('ref_type', self::REF_CONSIGNMENT)->delete();
    }

    /** This shop's own warehouse, read tenant-agnostically (no global scope in a console/migration). */
    private function home(int $tenantId): string
    {
        return PurchaseStock::homeLocation(
            data_get(json_decode((string) DB::table('tenants')->where('id', $tenantId)->value('settings'), true) ?: [],
                     'stock_home_location', StockLedger::LOC_KAMPALA)
        );
    }
}
