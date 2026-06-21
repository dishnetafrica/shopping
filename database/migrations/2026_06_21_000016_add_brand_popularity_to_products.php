<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'brand')) {
                $t->string('brand')->nullable()->after('category');
            }
            if (! Schema::hasColumn('products', 'popularity')) {
                $t->integer('popularity')->default(0);   // rolling sales/popularity score for ranking
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (Schema::hasColumn('products', 'brand')) $t->dropColumn('brand');
            if (Schema::hasColumn('products', 'popularity')) $t->dropColumn('popularity');
        });
    }
};
