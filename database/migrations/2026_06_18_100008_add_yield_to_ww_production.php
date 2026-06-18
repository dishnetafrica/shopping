<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Win World: capture resin input + regrind so we can compute true material yield. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ww_production_entries')) return;
        Schema::table('ww_production_entries', function (Blueprint $t) {
            if (! Schema::hasColumn('ww_production_entries', 'input_kg'))   $t->decimal('input_kg', 12, 2)->nullable()->after('produced_kg');
            if (! Schema::hasColumn('ww_production_entries', 'regrind_kg')) $t->decimal('regrind_kg', 12, 2)->nullable()->after('scrap_kg');
        });
    }
    public function down(): void
    {
        if (! Schema::hasTable('ww_production_entries')) return;
        Schema::table('ww_production_entries', function (Blueprint $t) {
            foreach (['input_kg','regrind_kg'] as $c) if (Schema::hasColumn('ww_production_entries', $c)) $t->dropColumn($c);
        });
    }
};
