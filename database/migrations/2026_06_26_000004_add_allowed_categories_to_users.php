<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'allowed_categories')) {
                $t->jsonb('allowed_categories')->nullable(); // null = all; [] = none; [..] = limited (product_staff)
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'allowed_categories')) {
                $t->dropColumn('allowed_categories');
            }
        });
    }
};
