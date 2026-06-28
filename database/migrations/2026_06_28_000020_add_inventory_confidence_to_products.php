<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Inventory CONFIDENCE + freshness, so the bot can answer honestly instead of assuming availability. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'stock_confidence')) $t->string('stock_confidence')->default('unknown'); // exact|estimated|unknown
            if (! Schema::hasColumn('products', 'stock_checked_at'))  $t->timestamp('stock_checked_at')->nullable();
            if (! Schema::hasColumn('products', 'stock_source'))      $t->string('stock_source')->nullable();           // count|adjustment|sale|owner
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            foreach (['stock_confidence', 'stock_checked_at', 'stock_source'] as $c) {
                if (Schema::hasColumn('products', $c)) $t->dropColumn($c);
            }
        });
    }
};
