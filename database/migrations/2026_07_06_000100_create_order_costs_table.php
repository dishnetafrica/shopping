<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * order_costs — every cost booked against an order, in its NATIVE currency, with the USD base value
 * and the rate used (DishNet's proven amount(USD)+native+rate model). Ties cost to an order (which
 * DishNet lacked) and to a holder (who paid, so per-currency cash-in-hand is exact).
 * Additive only — no existing table touched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_costs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('order_no')->nullable()->index();     // denormalised for quick display
            $t->string('stage')->default('purchase');        // purchase|freight_bus|delivery_boda|other
            $t->string('currency', 8)->default('UGX');       // UGX|SSP|USD (native)
            $t->decimal('native_amount', 16, 2)->default(0); // what was actually paid
            $t->decimal('rate_to_usd', 16, 6)->default(1);   // native per 1 USD (e.g. 3700)
            $t->decimal('usd_amount', 16, 2)->default(0);    // base value = native / rate
            $t->string('holder')->nullable();                // who paid / holds (Jaeysh, Asish, safe...)
            $t->string('receipt_path')->nullable();          // uploaded receipt image
            $t->string('status')->default('booked');         // booked|approved|rejected
            $t->text('note')->nullable();
            $t->string('created_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id','currency']);
            $t->index(['tenant_id','holder','currency']);
        });
    }
    public function down(): void { Schema::dropIfExists('order_costs'); }
};
