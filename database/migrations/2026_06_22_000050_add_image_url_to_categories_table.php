<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $t) {
            if (! Schema::hasColumn('categories', 'image_url')) {
                $t->string('image_url', 500)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $t) {
            if (Schema::hasColumn('categories', 'image_url')) {
                $t->dropColumn('image_url');
            }
        });
    }
};
