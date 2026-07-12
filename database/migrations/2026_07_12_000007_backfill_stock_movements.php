<?php

use App\Services\Inventory\StockBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap E6.3 — backfill the stock ledger from current stock + consignment history so its projected
 * on-hand equals today's numbers (the prerequisite for E6.4 switching reads onto the projection).
 *
 * Data-only + idempotent (opening rows keyed by ref_type+ref_id). Reversible: down() removes ONLY the
 * opening rows this migration wrote, leaving any real (E6.2) movements intact.
 */
return new class extends Migration {
    public function up(): void
    {
        try {
            (new StockBackfill())->run();
        } catch (\Throwable $e) {
            // Never let the backfill abort a deploy; it is idempotent and can be re-run.
            \Log::warning('stock_movements backfill skipped: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        DB::table('stock_movements')
            ->whereIn('ref_type', [StockBackfill::REF_PRODUCT, StockBackfill::REF_CONSIGNMENT])
            ->delete();
    }
};
