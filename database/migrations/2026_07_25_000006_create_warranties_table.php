<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Lifecycle · S3a — Warranty as a first-class object. Attaches to EITHER a serial
 * (inventory_unit_id) OR a non-serialized invoice line (invoice_id + invoice_item_id), per the
 * architecture review. The `status` column is the source of truth (active | void); "expired" is
 * DERIVED from warranty_end, so no nightly job is needed. Lifecycle changes also emit audit events.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('product_id')->nullable()->index();
            $t->unsignedBigInteger('inventory_unit_id')->nullable()->index();  // serial-attached
            $t->unsignedBigInteger('invoice_id')->nullable()->index();         // invoice-line-attached
            $t->unsignedBigInteger('invoice_item_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('serial')->nullable();                                  // denormalized for display
            $t->string('manufacturer')->nullable();                            // maker (e.g. Victron) — full entity later
            $t->date('installation_date')->nullable();
            $t->date('warranty_start')->nullable();
            $t->date('warranty_end')->nullable()->index();
            $t->unsignedInteger('manufacturer_months')->nullable();
            $t->unsignedInteger('company_months')->nullable();
            $t->string('status')->default('active')->index();                  // active | void (expired derived)
            $t->jsonb('meta')->nullable();                                     // certificates / claim data (later)
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'warranty_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
