<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment Platform v1 — append-only chain-of-custody ledger. One row per physical handoff,
 * spanning both the transport leg AND the last mile, so every box movement is auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('shipment_id')->index();
            $t->string('event', 32);          // packed|sent_to_transport|received_by_transport|bus_departed|arrived|collected_by_rider|delivered|cancelled|damaged
            $t->string('actor', 24);          // shop|transport|destination_agent|rider|system
            $t->string('actor_name')->nullable();
            $t->integer('box_count')->nullable();   // null = this handoff did not recount
            $t->string('photo_url')->nullable();
            $t->text('note')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['tenant_id', 'shipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
    }
};
