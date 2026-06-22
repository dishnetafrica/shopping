<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment Platform v6 — box-level custody. One row per physical box, generated when a shipment is
 * packed. `code` is the human + QR identity (e.g. SH-0001-B3); it's what a scanner reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_boxes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('shipment_id')->index();
            $t->unsignedInteger('box_number');
            $t->string('code')->unique();      // SH-0001-B3
            $t->timestamps();
            $t->unique(['shipment_id', 'box_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_boxes');
    }
};
