<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Lifecycle · Phase 2 · S5 — the equipment on a job. Each row is a product (and, when serial-
 * tracked, a specific inventory unit) being installed/serviced on a work order. On completion of an
 * installation, these units transition to 'installed' — which auto-activates their warranties (S3c).
 * Tenant-scoped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('work_order_id')->index();
            $t->unsignedBigInteger('product_id')->nullable()->index();
            $t->unsignedBigInteger('inventory_unit_id')->nullable()->index();   // the specific serial unit
            $t->string('serial')->nullable();                                   // denormalized for display
            $t->decimal('qty', 12, 3)->default(1);
            $t->jsonb('meta')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'work_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_items');
    }
};
