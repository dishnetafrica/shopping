<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Lifecycle · Phase 2 · S7 — Warranty Claims. A claim is opened against a warranty (Phase 1) and
 * then approved or rejected, with a resolution (repair / replace / refund). The `status` column is the
 * source of truth; lifecycle events on the spine are the audit log. Additive, tenant-scoped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('warranty_id')->nullable()->index();   // the warranty being claimed against

            $t->unsignedBigInteger('product_id')->nullable()->index();
            $t->unsignedBigInteger('inventory_unit_id')->nullable()->index();
            $t->string('serial')->nullable();

            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();

            $t->text('fault_description')->nullable();
            $t->string('status')->default('open');            // open|approved|rejected|resolved
            $t->string('resolution')->nullable();             // repair|replace|refund
            $t->text('resolution_notes')->nullable();

            $t->string('opened_by')->nullable();
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();

            $t->jsonb('meta')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
