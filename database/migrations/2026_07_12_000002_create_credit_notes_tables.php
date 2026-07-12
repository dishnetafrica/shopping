<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes — the formal, numbered "we owe you" document (CN-2026-0001), the mirror image of an
 * invoice. Raised for a return of goods, a price adjustment, an over-payment or goodwill. A non-void
 * credit note reduces the customer's outstanding balance on their statement (optionally tied to a
 * specific invoice). Numbering is gapless + per-year, exactly like invoices.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('credit_note_no')->index();                 // CN-2026-0001 (unique per tenant)
            $t->unsignedBigInteger('invoice_id')->nullable()->index();  // the invoice it credits, if any
            $t->string('invoice_no')->nullable()->index();
            $t->unsignedBigInteger('order_id')->nullable()->index();
            $t->unsignedBigInteger('return_id')->nullable()->index();   // the return/refund it formalises, if any
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable()->index();
            $t->string('bill_address')->nullable();
            $t->string('currency', 8)->default('UGX');
            $t->decimal('subtotal', 16, 2)->default(0);
            $t->decimal('total', 16, 2)->default(0);               // amount credited (positive number)
            $t->string('reason_code')->default('return');          // return | price_adjustment | overpayment | goodwill | damage | other
            $t->string('title')->default('CREDIT NOTE');
            $t->string('status')->default('issued')->index();      // issued | void
            $t->string('source')->default('panel');                // panel | return | auto
            $t->text('notes')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->string('pdf_path')->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'credit_note_no']);
        });

        Schema::create('credit_note_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('credit_note_id')->index();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('name');
            $t->string('sku')->nullable();
            $t->decimal('qty', 14, 2)->default(1);
            $t->decimal('unit_price', 16, 2)->default(0);
            $t->decimal('line_total', 16, 2)->default(0);
            $t->string('uom')->nullable();
            $t->decimal('pack', 12, 2)->nullable();
            $t->timestamps();
        });

        // Gapless, per-year sequence counter (one row per tenant+year) — its own series, separate
        // from invoices, so a credit note never borrows an invoice number.
        Schema::create('credit_note_counters', function (Blueprint $t) {
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
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_note_counters');
        Schema::dropIfExists('credit_notes');
    }
};
