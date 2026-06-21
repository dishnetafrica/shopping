<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'display_order')) {
                // Higher = shown first. Default 0 = neutral (catalogue stays alphabetical
                // until a merchant pins something to the top or pushes it to the bottom).
                $t->integer('display_order')->default(0)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (Schema::hasColumn('products', 'display_order')) {
                $t->dropColumn('display_order');
            }
        });
    }
};
