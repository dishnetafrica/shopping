<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trade_purchase_ticks — records which native order items Jayesh has BOUGHT, per
 * (order_no, item_name). The Buy List reads live confirmed orders and subtracts these
 * ticks to show what's still to buy. Additive + isolated: ShopBot's own orders table
 * is never written to.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('trade_purchase_ticks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('order_no')->index();
            $t->string('item_name');
            $t->decimal('qty', 14, 2)->default(0);   // quantity marked bought
            $t->string('created_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id','order_no','item_name']);
        });
    }
    public function down(): void { Schema::dropIfExists('trade_purchase_ticks'); }
};
