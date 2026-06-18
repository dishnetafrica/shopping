<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('leads')) return;
        if (Schema::hasColumn('leads', 'dedupe_key')) return;

        Schema::table('leads', function (Blueprint $t) {
            $t->string('dedupe_key', 40)->nullable()->index()->after('interest');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('leads') && Schema::hasColumn('leads', 'dedupe_key')) {
            Schema::table('leads', function (Blueprint $t) {
                $t->dropColumn('dedupe_key');
            });
        }
    }
};
