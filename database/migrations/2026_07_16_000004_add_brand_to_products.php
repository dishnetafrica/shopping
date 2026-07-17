<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive brand field on products. Powers the storefront "Featured brands" filter and the Brand
 * column/subline on the professional quotation PDF (both already consume a brand — the data just
 * didn't exist). Nullable, so nothing existing is affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent (prod incident 2026-07-18): the column pre-existed on the live DB
        // while the migrations ledger said otherwise, and the failure blocked every
        // later migration (incl. the Comms tables). Guarded so drift can't block again.
        if (Schema::hasColumn('products', 'brand')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('brand');
        });
    }
};
