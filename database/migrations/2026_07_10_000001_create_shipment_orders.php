<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shipment_orders — the manifest join between a consignment (shipment/AWB) and the
 * customer orders it carries. Many orders per shipment; an order may also appear on
 * more than one shipment (an order split across two buses), so the only uniqueness is
 * (shipment_id, order_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('shipment_id')->index();
            $t->unsignedBigInteger('order_id')->index();
            $t->string('status', 20)->default('on_board');   // on_board | delivered | returned
            $t->string('note')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('delivered_by')->nullable();
            $t->timestamps();
            $t->unique(['shipment_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_orders');
    }
};
