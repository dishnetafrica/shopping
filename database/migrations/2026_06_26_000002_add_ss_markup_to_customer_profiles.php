<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_profiles', 'ss_markup_pct')) {
            Schema::table('customer_profiles', function (Blueprint $t) {
                // Per-client South Sudan markup override (percent). NULL = use the
                // tenant default (settings.ssMarkupPct). e.g. 25 = +25%.
                $t->decimal('ss_markup_pct', 6, 2)->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_profiles', 'ss_markup_pct')) {
            Schema::table('customer_profiles', function (Blueprint $t) {
                $t->dropColumn('ss_markup_pct');
            });
        }
    }
};
