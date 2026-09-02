<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local Purchase Orders (LPO) — additive, freeze-safe (Constitution: new projection, no kernel touch).
 *
 * A per-supplier ordering document: what we asked a supplier to deliver, and what actually arrived,
 * tracked per line (ordered vs received, full or partial). This is a DOCUMENT + a goods-receipt
 * tracker — it holds no money. The optional cost per line is a reference/expectation only; the
 * authoritative spend still posts through the frozen order_costs path (supplier bills), and goods
 * receipt still moves stock through StockLedger. Kept deliberately separate from demand_plans (the
 * Kampala→Juba shipment worksheet): an LPO is one order to one supplier and fits any tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('po_no')->nullable()->index();          // LPO-2026-0001
                $t->unsignedBigInteger('supplier_id')->nullable()->index();
                $t->string('supplier_name')->nullable();
                $t->string('status')->default('draft');            // draft | sent | partial | received | closed | cancelled
                $t->date('order_date')->nullable();
                $t->date('expected_date')->nullable();
                $t->string('currency', 8)->nullable();
                $t->string('delivery_address')->nullable();
                $t->text('terms')->nullable();
                $t->text('notes')->nullable();
                $t->string('created_by')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('purchase_order_lines')) {
            Schema::create('purchase_order_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('product_id')->nullable()->index();
                $t->string('name');
                $t->decimal('ordered_qty', 14, 3)->default(0);
                $t->decimal('unit_cost', 14, 4)->nullable();       // expected cost per unit (reference)
                $t->decimal('received_qty', 14, 3)->default(0);
                $t->string('line_status')->default('pending');     // pending | partial | received
                $t->text('remarks')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'purchase_order_id']);
                $t->index(['tenant_id', 'line_status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
