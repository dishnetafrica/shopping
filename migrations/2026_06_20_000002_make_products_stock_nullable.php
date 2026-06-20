<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow products.stock to be NULL, meaning "untracked / make-to-order" (always
 * sellable). Restaurant menus carry no stock column, so the importer writes NULL;
 * grocery tenants still set integer stock and 0 still means out-of-stock. Pairs with
 * the catalogue + storefront which treat null = untracked, 0 = sold out, >0 = tracked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->integer('stock')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Coalesce nulls so the NOT NULL constraint can be restored.
        \Illuminate\Support\Facades\DB::table('products')->whereNull('stock')->update(['stock' => 0]);
        Schema::table('products', function (Blueprint $t) {
            $t->integer('stock')->default(0)->nullable(false)->change();
        });
    }
};
