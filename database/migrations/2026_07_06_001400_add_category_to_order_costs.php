<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_costs', function (Blueprint $t) {
            if (! Schema::hasColumn('order_costs','category')) $t->string('category')->nullable()->after('note');
        });
    }
    public function down(): void
    {
        Schema::table('order_costs', function (Blueprint $t) {
            if (Schema::hasColumn('order_costs','category')) $t->dropColumn('category');
        });
    }
};
