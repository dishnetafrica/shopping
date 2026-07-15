<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive portal fields for the customer Quotation experience.
 *
 * Nothing existing is altered — every column is new and nullable/defaulted, so the current
 * quotation engine (bot / panel / v3 QuotationWriteService) keeps working unchanged. These
 * columns power the public customer portal, WhatsApp sharing and (later) the revision engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // The unguessable public key for the customer portal — the /q/{token} page.
            // Mirrors the Order.track_token pattern (no login required for the customer).
            $table->string('public_token', 48)->nullable()->unique()->after('quote_no');
            // Where the goods should go (captured at request time; shown on the portal + PDF).
            $table->text('delivery_address')->nullable()->after('customer_phone');
            // Sales-team remarks visible to the customer on the portal / PDF.
            $table->text('remarks')->nullable()->after('delivery_address');
            // Assigned salesperson (nullable FK to users; conventional, no DB constraint to
            // match the codebase's existing convention of soft references).
            $table->unsignedBigInteger('sales_exec_id')->nullable()->index()->after('order_id');
            // First time the customer opened the portal (for the "expected response" UX + audit).
            $table->timestamp('viewed_at')->nullable()->after('last_sent_at');
            // When the customer approved the quotation (locks it + triggers conversion).
            $table->timestamp('accepted_at')->nullable()->after('viewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'delivery_address', 'remarks', 'sales_exec_id', 'viewed_at']);
        });
    }
};
