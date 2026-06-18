<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('leads')) return;

        Schema::table('leads', function (Blueprint $t) {
            if (! Schema::hasColumn('leads', 'next_followup_at')) $t->timestamp('next_followup_at')->nullable()->index()->after('claimed_at');
            if (! Schema::hasColumn('leads', 'last_contacted_at')) $t->timestamp('last_contacted_at')->nullable()->after('next_followup_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('leads')) return;
        Schema::table('leads', function (Blueprint $t) {
            foreach (['next_followup_at', 'last_contacted_at'] as $c) {
                if (Schema::hasColumn('leads', $c)) $t->dropColumn($c);
            }
        });
    }
};
