<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOS v1.0 — Slice 1: the unified, append-only Event Store (the "event spine").
 *
 * Every meaningful business fact lands here as one immutable row (see Event Catalog, doc 5).
 * Objects remain the source of truth (ADR-001); this table is HISTORY, read by projections.
 *
 * Overlay-only: a brand-new table. Nothing writes to it until services opt in via Bos::emit(),
 * so shipping this migration changes zero existing behaviour. Fully reversible (down drops it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bos_events')) return;

        Schema::create('bos_events', function (Blueprint $t) {
            $t->uuid('event_id')->primary();              // client-generated when created offline
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name', 64);                        // CONTRACT: e.g. PaymentReceived
            $t->string('subject_type', 40)->nullable();    // customer|order|invoice|product|…
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('actor_type', 16)->default('system'); // user|bot|system|customer
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 120)->nullable();
            $t->string('from_state', 40)->nullable();       // transition.from
            $t->string('to_state', 40)->nullable();         // transition.to
            $t->json('payload')->nullable();
            $t->string('idempotency_key', 120)->nullable(); // offline retry never double-writes
            $t->timestamp('occurred_at')->nullable()->index(); // real-world (device) time
            $t->timestamp('recorded_at')->nullable();       // server accept time

            // --- Instrumentation, captured on EVERY event from day one (ADR-015). Debugging field
            //     issues in South Sudan/Uganda later is only possible if we record this now.
            $t->uuid('correlation_id')->nullable()->index(); // groups all events of one user action
            $t->uuid('causation_id')->nullable();            // the event that directly caused this one
            $t->string('device', 40)->nullable();            // android-pwa | ios-pwa | desktop | server
            $t->string('connectivity', 8)->nullable();       // online | offline (at creation)
            $t->string('app_version', 20)->nullable();
            $t->unsignedInteger('latency_ms')->nullable();   // client-reported action latency
            $t->json('context')->nullable();                 // any extra diagnostic context

            // One query powers every timeline: events for a subject, in order.
            $t->index(['tenant_id', 'subject_type', 'subject_id'], 'bos_events_subject_idx');
            $t->index(['tenant_id', 'name']);
            // Idempotency: the same offline mutation, retried, is a no-op.
            $t->unique(['tenant_id', 'idempotency_key'], 'bos_events_idem_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bos_events');
    }
};
