<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product sell mode for the storefront (Solar / equipment vertical).
 *
 * 'buy'   — normal e-commerce: Add to cart / checkout (default; every existing product keeps this).
 * 'quote' — high-value / made-to-order (a solar system, a generator): the storefront swaps the
 *           primary action to "Request a quote / installation", reusing the existing quotation portal.
 *
 * Additive and reversible: a nullable string defaulting to 'buy', so grocery and every current product
 * behave exactly as before. Nothing reads this column until the storefront slice is live.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'sell_mode')) {
            Schema::table('products', function (Blueprint $t) {
                $t->string('sell_mode', 8)->default('buy')->after('active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'sell_mode')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('sell_mode'));
        }
    }
};
