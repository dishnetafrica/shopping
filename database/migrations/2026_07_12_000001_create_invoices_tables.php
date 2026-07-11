<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('invoice_no')->index();                 // INV-2026-0001 (unique per tenant)
            $t->unsignedBigInteger('order_id')->nullable()->index();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable()->index();
            $t->string('bill_address')->nullable();
            $t->string('currency', 8)->default('UGX');
            $t->decimal('subtotal', 16, 2)->default(0);
            $t->decimal('discount', 16, 2)->default(0);
            $t->decimal('freight', 16, 2)->default(0);
            $t->decimal('total', 16, 2)->default(0);
            $t->decimal('amount_paid', 16, 2)->default(0);
            $t->decimal('balance', 16, 2)->default(0);
            $t->string('title')->default('INVOICE');           // INVOICE | TAX INVOICE …
            $t->string('status')->default('issued')->index();  // issued | part_paid | paid | void
            $t->string('source')->default('panel');            // panel | auto | bot
            $t->text('notes')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->date('due_at')->nullable();
            $t->string('pdf_path')->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'invoice_no']);
        });

        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('invoice_id')->index();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->string('sku')->nullable();
            $t->decimal('qty', 14, 2)->default(1);
            $t->decimal('unit_price', 16, 2)->default(0);
            $t->decimal('line_total', 16, 2)->default(0);
            $t->string('uom')->nullable();                     // carton / pc …
            $t->decimal('pack', 12, 2)->nullable();            // pieces per carton, if known
            $t->timestamps();
        });

        // Gapless, per-year sequence counter (one row per tenant+year).
        Schema::create('invoice_counters', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedInteger('year');
            $t->unsignedInteger('last_seq')->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoice_counters');
        Schema::dropIfExists('invoices');
    }
};
