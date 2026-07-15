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
