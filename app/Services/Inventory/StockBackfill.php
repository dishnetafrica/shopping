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

        // --- Kampala: each stocked product's current stock is its opening on-hand ---
        $seenProduct = array_flip(array_map('intval',
            DB::table('stock_movements')->where('tenant_id', $tenantId)
                ->where('ref_type', self::REF_PRODUCT)->pluck('ref_id')->all()));

        DB::table('products')->where('tenant_id', $tenantId)->where('stock', '>', 0)
            ->orderBy('id')->chunkById(500, function ($rows) use (&$inserted, $tenantId, $now, $seenProduct) {
                $batch = [];
                foreach ($rows as $p) {
                    if (isset($seenProduct[(int) $p->id])) continue;
                    $batch[] = [
                        'tenant_id' => $tenantId, 'product_id' => $p->id, 'sku' => $p->sku,
                        'location' => StockLedger::LOC_KAMPALA, 'type' => StockLedger::IN, 'qty' => (float) $p->stock,
                        'ref_type' => self::REF_PRODUCT, 'ref_id' => $p->id, 'note' => 'Opening balance',
                        'created_by' => 'backfill', 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                if ($batch) { DB::table('stock_movements')->insert($batch); $inserted += count($batch); }
            });

        // --- Juba: each consignment line's remaining quantity is its opening on-hand ---
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
}
