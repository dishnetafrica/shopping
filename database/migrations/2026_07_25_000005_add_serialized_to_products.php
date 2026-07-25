<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'serialized')) {
            Schema::table('products', fn (Blueprint $t) => $t->boolean('serialized')->default(false)->index()->after('active'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'serialized')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('serialized'));
        }
    }
};
