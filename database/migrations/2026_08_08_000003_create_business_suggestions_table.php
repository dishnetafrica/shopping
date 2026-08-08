<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v4.62 — the discovery review queue. ADR-016 decision 4a.
 *
 * Nothing a provider finds is written directly. It lands here, and a person decides.
 *
 * The unit of a row is a BUSINESS, not a field — which is where this departs from
 * `product_suggestions`, and deliberately. A product already exists and only its fields are
 * missing, so one row per field is right. A discovered business usually does not exist at all, and
 * "is this worth having in my system" is one decision about one organisation, not eight decisions
 * about eight columns. The findings ride inside the row with their confidence and their source, so
 * the screen can still show where every value came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_suggestions')) {
            return;
        }
        Schema::create('business_suggestions', function (Blueprint $t) {
            $jsonb = DB::connection()->getDriverName() === 'pgsql';

            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();

            // BusinessIdentity::key() — 'p:<digits>' or 'n:<sha1>'. The same business found twice
            // must land on one row, whether it came from two providers or two runs a month apart.
            $t->string('identity_key', 80)->index();

            // Set when this matches a profile the shop already has, in which case approving fills
            // empty fields rather than creating anything. Null means a business new to the system.
            $t->unsignedBigInteger('customer_profile_id')->nullable()->index();

            // Denormalised for the screen, so listing the queue needs no joins and no decoding.
            $t->string('name', 200)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('city', 80)->nullable();

            // Every finding: field, value, confidence, source, note. The provenance a reviewer needs
            // in order to disagree with a value rather than merely accept or reject it.
            $jsonb ? $t->jsonb('findings')->nullable() : $t->json('findings')->nullable();
            // What would actually be written if this were approved, decided when the row was made.
            // Re-checked at apply time regardless — a field can be filled in by a person in between.
            $jsonb ? $t->jsonb('proposed')->nullable() : $t->json('proposed')->nullable();

            $t->string('provider', 40)->nullable();          // which source found it
            $t->decimal('confidence', 4, 3)->default(0);     // the best claim's confidence, for sorting
            $t->string('status', 16)->default('pending');    // pending | applied | dismissed
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();

            // The queue screen: this tenant's pending rows, best first.
            $t->index(['tenant_id', 'status', 'confidence']);
            // The dedupe check when a run writes: is this business already waiting?
            $t->index(['tenant_id', 'identity_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_suggestions');
    }
};
