<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demand Planning & Procurement (Phase 1) — additive, freeze-safe (Constitution: new projection,
 * touches no kernel). A Demand Plan is Ashish's per-shipment worksheet; once submitted it becomes the
 * Procurement Request Jayesh works from. Actual purchases still post through the frozen order_costs
 * path; goods receipt still moves stock through StockLedger. This table only PLANS — it never holds money.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demand_plans')) {
            Schema::create('demand_plans', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('plan_no')->nullable()->index();        // DP-2026-0001
                $t->string('title')->nullable();
                $t->string('shipment_ref')->nullable()->index();   // e.g. SJ-2026-015 (free text until Phase 2 links Shipment)
                $t->string('status')->default('draft');            // draft | submitted | in_procurement | completed | cancelled
                $t->string('created_by')->nullable();
                $t->text('notes')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('demand_plan_lines')) {
            Schema::create('demand_plan_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('demand_plan_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('product_id')->nullable()->index();
                $t->string('name');
                // Planning figures (Ashish)
                $t->decimal('current_stock', 14, 3)->default(0);   // on-hand (Kampala + Juba)
                $t->decimal('in_transit', 14, 3)->default(0);      // already sent, not yet received
                $t->decimal('expected_sales', 14, 3)->default(0);  // demand for the coming cycle
                $t->decimal('suggested_qty', 14, 3)->default(0);   // system suggestion
                $t->decimal('requested_qty', 14, 3)->default(0);   // Ashish's final ask
                // Procurement figures (Jayesh)
                $t->decimal('qty_available', 14, 3)->nullable();
                $t->decimal('qty_purchased', 14, 3)->default(0);
                $t->unsignedBigInteger('supplier_id')->nullable();
                $t->string('supplier_name')->nullable();
                $t->date('expected_delivery')->nullable();
                $t->text('remarks')->nullable();
                $t->string('line_status')->default('pending');     // pending | partial | purchased | not_available
                $t->timestamps();
                $t->index(['tenant_id', 'demand_plan_id']);
                $t->index(['tenant_id', 'line_status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_plan_lines');
        Schema::dropIfExists('demand_plans');
    }
};
