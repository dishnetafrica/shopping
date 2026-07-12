<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap E6.1 (Constitution R2/R5 — one ledger per fact). The ONE stock ledger.
 *
 * On-hand today is a mutable `products.stock` integer (and consignment qty_* columns) that every
 * write path pokes directly, so there is no history and the number can drift. This introduces an
 * append-only movement ledger: on-hand for a SKU at a location = Σ signed `qty` of its movements.
 * IN adds, OUT subtracts, ADJUST is a signed correction, MOVE is a pair (−qty at source, +qty at
 * destination). Additive + reversible; NOTHING reads or writes it yet (E6.2 dual-writes, E6.4 reads).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable();   // may be null when only a SKU/name is known
            $t->string('sku')->nullable();
            $t->string('location', 40);                // e.g. 'kampala', 'juba-consignment'
            $t->string('type', 12);                    // IN | OUT | MOVE | ADJUST
            $t->decimal('qty', 14, 3);                 // SIGNED delta to on-hand at `location`
            $t->string('ref_type', 40)->nullable();    // what caused it: order|purchase|consignment|adjust…
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('note')->nullable();
            $t->string('created_by')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'product_id', 'location']);
            $t->index(['tenant_id', 'sku', 'location']);
            $t->index(['tenant_id', 'ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
