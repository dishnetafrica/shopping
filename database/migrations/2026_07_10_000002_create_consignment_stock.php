<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * consignment_stock — LOOSE stock carried on a consignment (not tied to a customer order):
 * the extra cartons Asish sells in the Juba market. Tracks the full request→sell chain so the
 * "we didn't send everything Asish asked for" gap is visible and can be carried forward:
 *   qty_requested (Asish asked) → qty_sent (Jayesh loaded) → qty_received (arrived) → qty_sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_stock', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('shipment_id')->index();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('name');
            $t->decimal('qty_requested', 12, 2)->default(0);
            $t->decimal('qty_sent', 12, 2)->default(0);
            $t->decimal('qty_received', 12, 2)->nullable();
            $t->decimal('qty_sold', 12, 2)->default(0);
            $t->decimal('unit_price', 14, 2)->nullable();   // Juba selling price (native)
            $t->string('currency', 8)->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'shipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_stock');
    }
};
