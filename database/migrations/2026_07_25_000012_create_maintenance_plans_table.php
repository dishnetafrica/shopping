<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Lifecycle · Phase 2 · S6 — recurring maintenance / service plans. A plan says "service this
 * customer's system every N months"; logging a visit spawns a real service Work Order (S5) and rolls
 * `next_due` forward, so the due list always shows what needs attention. Due-list-driven (no cron):
 * staff act from what's due. Additive, tenant-scoped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();

            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();

            $t->unsignedBigInteger('product_id')->nullable()->index();
            $t->unsignedBigInteger('inventory_unit_id')->nullable()->index();   // the specific installed unit
            $t->string('serial')->nullable();

            $t->unsignedBigInteger('source_work_order_id')->nullable();          // the install job that started it
            $t->unsignedBigInteger('work_order_id')->nullable();                 // the most recent service visit

            $t->unsignedInteger('interval_months')->default(6);
            $t->date('last_service_at')->nullable();
            $t->date('next_due')->nullable();
            $t->string('status')->default('active');                             // active|paused|ended

            $t->text('notes')->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status', 'next_due']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_plans');
    }
};
