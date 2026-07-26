<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Lifecycle · Phase 2 · S5 — Jobs / Work Orders. ONE generic field-work entity for installations,
 * service visits, repairs and inspections (distinguished by `type`), so the platform serves solar,
 * generators, HVAC, etc. from one clean core (V3 Migration Constitution — V3-native, no legacy screen).
 * The `status` column is the source of truth; lifecycle events on the spine are the audit log. Links to
 * the customer and (optionally) the sale it came from. Tenant-scoped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('number')->nullable();                    // JOB-0001 (per-tenant sequence)
            $t->string('type')->default('installation');         // installation|service|repair|inspection
            $t->string('status')->default('draft');              // draft|scheduled|in_progress|completed|cancelled

            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->text('site_address')->nullable();
            $t->string('location')->nullable();                  // map pin / link

            $t->unsignedBigInteger('order_id')->nullable()->index();      // the sale it came from
            $t->unsignedBigInteger('invoice_id')->nullable()->index();
            $t->unsignedBigInteger('quotation_id')->nullable()->index();

            $t->string('assigned_to')->nullable();               // technician / crew (name)
            $t->unsignedBigInteger('assigned_user_id')->nullable()->index();

            $t->timestamp('scheduled_for')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('completed_by')->nullable();

            $t->text('notes')->nullable();
            $t->jsonb('meta')->nullable();                       // checklist / photos / attachments (later)
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'type']);
            $t->index(['tenant_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
