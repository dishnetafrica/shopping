<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sourcing_requests — Asish (field, South Sudan) asks Jayesh (purchaser, Uganda) to
 * find a price for an item customers want. The item need NOT exist in the catalogue.
 * Flow: new (needs price) -> priced (Jayesh added price) -> closed. Isolated table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sourcing_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('item_name');
            $t->text('note')->nullable();
            $t->string('demand', 12)->default('medium');     // low | medium | high
            $t->string('photo_path')->nullable();
            $t->string('status', 12)->default('new');         // new | priced | closed
            // filled by the purchaser:
            $t->decimal('quoted_price', 16, 2)->nullable();
            $t->string('currency', 8)->nullable();
            $t->string('supplier_name')->nullable();
            $t->string('availability')->nullable();           // e.g. "in stock", "3 days"
            $t->text('price_note')->nullable();
            $t->string('priced_by')->nullable();
            $t->timestamp('priced_at')->nullable();
            // requester:
            $t->string('requested_by')->nullable();
            $t->unsignedBigInteger('requested_by_user_id')->nullable();
            $t->timestamps();
            $t->index(['tenant_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('sourcing_requests'); }
};
