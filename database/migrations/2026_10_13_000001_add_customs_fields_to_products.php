<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customs fields a shipment's paperwork needs but the catalogue could not record.
 *
 * hs_code            the Harmonized System tariff code a clearing agent declares the item
 *                    under (e.g. "8517.62.00"). Short string, not a lookup — a real customs
 *                    entry prints the code as text and it varies by how the item is classified.
 *
 * gross_weight_grams the packed/shipping weight of ONE piece, for the packing-list and the
 *                    commercial invoice's total-gross-weight line. The existing
 *                    reference_weight_grams is a PRICING reference (weight-based sell price),
 *                    so it is reused as the NET weight; this adds the GROSS alongside it.
 *
 * Both nullable and additive — they fill in over time exactly as oem_number / origin_country
 * did, and an item with neither set simply prints blank on the customs documents. Guarded so a
 * re-run where they already exist is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'hs_code')) {
                $table->string('hs_code', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('products', 'gross_weight_grams')) {
                $table->integer('gross_weight_grams')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['hs_code', 'gross_weight_grams'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
