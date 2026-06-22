<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment Platform v6 — the box-level custody ledger. Every scan is recorded here, one row per box
 * per stage (unique, so re-scanning the same box at the same stage is idempotent). The scanned count
 * per stage feeds the existing reconciliation engine — no manual box counts required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_box_scans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('shipment_id')->index();
            $t->unsignedBigInteger('box_id')->index();
            $t->string('stage');     // received_by_transport | arrived | collected_by_rider | delivered
            $t->string('actor');     // transport | destination_agent | rider
            $t->string('actor_name')->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->timestamps();
            $t->unique(['box_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_box_scans');
    }
};
