<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.45 — a counter behind quotation numbers, so they can run in order.
 *
 * Quotation numbers are minted as `ORD-Q` + the date + FOUR RANDOM CHARACTERS (ORD-Q260806-ZH9Z).
 * Random is collision-prone — v4.38 had to rename live duplicates before it could add the unique
 * index — and, more to the point for the person using it, random cannot be read. You cannot tell
 * which of two quotations came first, you cannot say "quote 41" on the phone, and you cannot see
 * at a glance how many you raised this month.
 *
 * Invoices (INV-2026-0001) and credit notes (CN-YYYY-NNNN) already run on gapless per-year
 * counters. This is the same table shape as `invoice_counters` on purpose: three numbered document
 * types, one convention, one thing to learn.
 *
 * Old `ORD-Q…` numbers are never touched. The new series starts at 1 under a different prefix, so
 * the two cannot collide and history stays exactly as it was issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quote_counters')) {
            return;
        }
        Schema::create('quote_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            // 0 = one running series that never resets; otherwise the calendar year.
            $table->unsignedInteger('year')->default(0);
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'year'], 'quote_counters_tenant_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_counters');
    }
};
