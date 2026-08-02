<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7.7 — manufacturer part numbers on products (catalogue review #1).
 *
 * Mechanics and garages order by the OEM number printed on the genuine packet
 * (e.g. a Bajaj "JE331002"), not by our display name. The column is nullable and
 * fills over time: from the supplier's cross-reference list, or copied off the
 * packet as stock arrives. Read by the catalogue PDF, the storefront (part-number
 * chip + exploded-diagram cross-links), the products feed/export and the importer.
 * Guarded so a re-run on a box where it already exists is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'oem_number')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('oem_number', 60)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'oem_number')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('oem_number');
            });
        }
    }
};
