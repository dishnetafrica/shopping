<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customer_payments — money received FROM customers (advance or on-delivery).
 *  - Cash side is mirrored into order_costs (stage 'customer_payment', money IN) so Cash
 *    Position rises. usd_amount uses the same base+rate engine.
 *  - An advance is UNEARNED until its order is Delivered: kind='advance' + applied flag.
 *    'settlement' = payment on/after delivery (already earned). This keeps advances OUT
 *    of invoiced revenue (which counts only at Delivered).
 * Additive, isolated table; never writes to ShopBot's orders table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('receipt_no')->index();               // RCP-xxxxxx
            $t->string('customer_name');
            $t->string('customer_phone')->nullable();
            $t->string('order_no')->nullable()->index();     // tie to an order, or null = account credit
            $t->string('kind', 16)->default('advance');      // advance | settlement
            $t->string('currency', 8);
            $t->decimal('amount', 16, 2)->default(0);
            $t->decimal('rate_to_usd', 16, 6)->default(1);
            $t->decimal('usd_amount', 16, 2)->default(0);
            $t->string('method', 24)->default('cash');       // cash | momo | bank | other
            $t->string('reference')->nullable();             // txn/reference no.
            $t->string('receipt_path')->nullable();          // optional photo of the receipt
            $t->boolean('applied')->default(false);          // advance consumed against an invoice
            $t->unsignedBigInteger('order_cost_id')->nullable(); // the mirrored cash row
            $t->string('holder')->nullable();                // who collected it
            $t->unsignedBigInteger('holder_user_id')->nullable();
            $t->string('created_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id','customer_phone']);
        });
    }
    public function down(): void { Schema::dropIfExists('customer_payments'); }
};
