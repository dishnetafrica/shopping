<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5.08 — opening inventory: the count you anchor on.
 *
 * ── why a new table ─────────────────────────────────────────────────────────────────────────────
 *
 * `stock_movements` holds DELTAS and every reader sums them. An opening balance is not a delta, it
 * is an absolute statement — "on 14 August there were 115 cartons of cling film in Juba, because
 * Ashish counted them". Putting an absolute row into a table that gets summed corrupts every total
 * that reads it, so the anchor lives here and the ledger is left alone.
 *
 * ── what it changes ─────────────────────────────────────────────────────────────────────────────
 *
 * Before, Juba Stock derived stock as Purchased − Sold across ALL TIME, which quietly demanded that
 * every purchase and every invoice since the shop opened be present and correctly spelled. It is
 * not: 303 units of sales carry invoice wordings that match no product, 60 products showed as sold
 * more than bought, and `products.stock` disagreed with the paperwork on 445 of 449 products.
 *
 * With an opening the sum becomes
 *
 *     Current = counted on date D  +  purchases after D  −  sales after D
 *
 * so the history before D stops mattering — it is already inside the count. One physical fact plus
 * a short, checkable tail, instead of years of paperwork that has to be perfect.
 *
 * A count REPLACES rather than adjusts: the most recent opening per product and location wins, and
 * everything before it is closed off. Old rows are kept, because how the number moved is worth
 * knowing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_openings')) {
            return;
        }

        Schema::create('stock_openings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('location', 40);
            $t->decimal('qty', 14, 3);
            // The moment of the count, NOT of the data entry. Everything after this timestamp is
            // maintained against it; everything before is assumed to be inside it.
            $t->timestamp('counted_at');
            $t->string('counted_by', 80)->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'product_id', 'location'], 'stock_openings_lookup');
            $t->index(['tenant_id', 'counted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_openings');
    }
};
