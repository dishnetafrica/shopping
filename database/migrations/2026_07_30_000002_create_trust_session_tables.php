<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory Trust v1.2 — receiving + blind-count session tables (Pilot Plan Phase B1).
 * Additive only. Sessions are opened by trust:links and worked on a phone via public nonce
 * pages (the TaskEngine /task/{nonce} pattern). Submitting writes REAL StockLedger movements
 * with evidence attached — this is where scale_weight / grn_photo / counts start flowing.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('trust_receiving_sessions')) {
            Schema::create('trust_receiving_sessions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('nonce', 40)->unique();
                $t->string('supplier_name')->nullable();
                $t->string('expected_ref')->nullable();     // delivery note / invoice no (typed later is fine)
                $t->string('location', 40)->default('kampala');
                $t->string('status', 12)->default('open');  // open|submitted
                $t->string('created_by')->nullable();
                $t->string('submitted_by')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->json('summary')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('trust_receiving_lines')) {
            Schema::create('trust_receiving_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('session_id');
                $t->foreignId('product_id')->nullable();
                $t->string('sku')->nullable();
                $t->decimal('qty', 14, 3);
                $t->decimal('weight_kg', 10, 3)->nullable();
                $t->string('photo_url')->nullable();
                $t->string('note')->nullable();
                $t->foreignId('stock_movement_id')->nullable();
                $t->timestamps();
                $t->index('session_id');
            });
        }

        if (! Schema::hasTable('trust_count_sessions')) {
            Schema::create('trust_count_sessions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('nonce', 40)->unique();
                $t->string('status', 12)->default('open');  // open|submitted
                $t->json('picks');                          // [{product_id, location, name}] — chosen by the engine
                $t->json('results')->nullable();            // [{product_id, location, counted, on_hand, diff}]
                $t->string('created_by')->nullable();
                $t->string('submitted_by')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        foreach (['trust_count_sessions', 'trust_receiving_lines', 'trust_receiving_sessions'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
