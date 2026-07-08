<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cost & margin (profit view) — adds a per-product cost price. No stock/tax changes. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $t) {
                $t->decimal('cost_price', 14, 2)->nullable()->after('base_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $t) { $t->dropColumn('cost_price'); });
        }
    }
};
