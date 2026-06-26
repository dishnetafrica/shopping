<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'archived_at')) {
            Schema::table('products', function (Blueprint $t) {
                $t->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'archived_at')) {
            Schema::table('products', function (Blueprint $t) {
                $t->dropColumn('archived_at');
            });
        }
    }
};
