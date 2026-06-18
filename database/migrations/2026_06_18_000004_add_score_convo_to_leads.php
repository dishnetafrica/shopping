<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('leads')) return;

        Schema::table('leads', function (Blueprint $t) {
            if (! Schema::hasColumn('leads', 'lead_score')) {
                $t->unsignedTinyInteger('lead_score')->default(0)->index()->after('interest');
            }
            if (! Schema::hasColumn('leads', 'conversation_id')) {
                $t->unsignedBigInteger('conversation_id')->nullable()->index()->after('source');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('leads')) return;
        Schema::table('leads', function (Blueprint $t) {
            foreach (['lead_score', 'conversation_id'] as $c) {
                if (Schema::hasColumn('leads', $c)) $t->dropColumn($c);
            }
        });
    }
};
