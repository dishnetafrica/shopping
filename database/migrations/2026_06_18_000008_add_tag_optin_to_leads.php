<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('leads')) return;

        Schema::table('leads', function (Blueprint $t) {
            if (! Schema::hasColumn('leads', 'tag'))               $t->string('tag', 60)->nullable()->index()->after('source');
            if (! Schema::hasColumn('leads', 'marketing_opt_in'))  $t->boolean('marketing_opt_in')->default(true)->after('tag');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('leads')) return;
        Schema::table('leads', function (Blueprint $t) {
            foreach (['tag', 'marketing_opt_in'] as $c) {
                if (Schema::hasColumn('leads', $c)) $t->dropColumn($c);
            }
        });
    }
};
