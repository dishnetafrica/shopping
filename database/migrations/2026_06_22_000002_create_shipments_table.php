<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment Platform v1 — the long-distance transport leg (Kampala→Juba etc.).
 * Separate from Order (shop lifecycle) and Delivery (last mile). Platform-wide, tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('order_id')->nullable()->index();
            $t->string('shipment_number', 32);                 // SH-0001 per tenant
            $t->string('status', 24)->default('packed');       // see ShipmentStateMachine::FLOW + cancelled
            $t->string('token', 40)->unique();                 // external tokenized pages (Phase 2)

            // box accounting
            $t->integer('boxes_sent')->nullable();             // shop's count at packing/dispatch
            $t->integer('boxes_received')->nullable();         // latest confirmed downstream count
            $t->decimal('weight_kg', 10, 2)->nullable();

            // transporter handoff
            $t->string('transport_company')->nullable();
            $t->string('bus_number')->nullable();
            $t->string('driver_phone', 32)->nullable();

            // route + destination agent
            $t->string('origin_city')->nullable();
            $t->string('destination_city')->nullable();
            $t->string('destination_agent_name')->nullable();
            $t->string('destination_agent_phone', 32)->nullable();

            $t->text('notes')->nullable();

            // stage timestamps (for dashboards / SLA)
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('transport_confirmed_at')->nullable();
            $t->timestamp('departed_at')->nullable();
            $t->timestamp('arrived_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->unique(['tenant_id', 'shipment_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
