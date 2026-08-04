<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.28 — the two product facts a wholesale catalogue could not record.
 *
 * unit_size     the size of ONE piece as printed on it — "500ml", "2kg", "1L".
 *               Distinct from pack_size, which counts pieces in the selling unit.
 *               A carton of soy sauce is unit_label=Box, pack_size=24, unit_size=500ml;
 *               those three are what "24 × 500ml per box" is made of, and until now
 *               only the first two existed, so the size lived inside the name as text
 *               and nothing could search, filter or group by it.
 *
 * origin_country  "China", "India", "Uganda". Customers ask, tenders demand it, and
 *               quotations and customs paperwork have to state it.
 *
 * Both are short strings rather than lookups on purpose: real invoices say "China",
 * not a country id, and forcing a picker would block a product being added at 8pm.
 * Guarded so a re-run where they already exist is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'unit_size')) {
                $table->string('unit_size', 40)->nullable();
            }
            if (! Schema::hasColumn('products', 'origin_country')) {
                $table->string('origin_country', 60)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['unit_size', 'origin_country'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
