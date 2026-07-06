<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trade_order_items — structured line items for trading orders (product + qty), so the
 * Buy List can total "what to buy today" across every open order. bought_qty lets Jayesh
 * tick items off as he buys. Dedicated to the trading flow; additive, isolated table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('trade_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('order_no')->nullable()->index();
            $t->string('name');                       // item name (free text or from catalogue)
            $t->decimal('qty', 14, 2)->default(1);    // ordered quantity
            $t->string('unit', 24)->nullable();       // pcs, bags, box...
            $t->decimal('bought_qty', 14, 2)->default(0); // marked bought from the buy list
            $t->unsignedBigInteger('product_id')->nullable(); // optional catalogue link (no FK — decoupled)
            $t->timestamps();
            $t->index(['tenant_id','name']);
            $t->index(['tenant_id','order_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('trade_order_items'); }
};
