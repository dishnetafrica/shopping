<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment Platform v1 — box-count discrepancies raised by reconciliation (or by an actor for
 * damage). Localised to the leg where the count changed, so the owner knows where to chase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_exceptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('shipment_id')->index();
            $t->string('type', 20);            // missing_boxes|extra_boxes|damaged_boxes
            $t->string('from_stage', 32)->nullable();
            $t->string('to_stage', 32)->nullable();
            $t->integer('expected')->nullable();
            $t->integer('got')->nullable();
            $t->integer('delta')->nullable();
            $t->text('detail')->nullable();
            $t->boolean('resolved')->default(false);
            $t->string('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['tenant_id', 'shipment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_exceptions');
    }
};
