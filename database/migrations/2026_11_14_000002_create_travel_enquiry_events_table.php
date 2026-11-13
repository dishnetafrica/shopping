<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel Vertical · Milestone 1 — the append-only timeline of a travel enquiry: created, a detail
 * changed, stage changed, handover started/released (with the reason), a staff flag on an incorrect
 * AI answer, a guard hit. It is what staff read as the trip history and what the AI-quality measures
 * are computed from. Rows are never updated or deleted. Additive and tenant-scoped.
 *
 * `meta` holds field names and codes, never passport or identity data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('travel_enquiry_events')) {
            return;
        }
        Schema::create('travel_enquiry_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('enquiry_id')->index();
            $t->string('type', 40);
            $t->string('actor', 16)->default('system');          // customer | bot | staff | system
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_enquiry_events');
    }
};
