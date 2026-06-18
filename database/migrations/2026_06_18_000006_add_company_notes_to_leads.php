<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('leads')) return;

        Schema::table('leads', function (Blueprint $t) {
            if (! Schema::hasColumn('leads', 'company')) $t->string('company', 160)->nullable()->after('customer_name');
            if (! Schema::hasColumn('leads', 'notes'))   $t->text('notes')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('leads')) return;
        Schema::table('leads', function (Blueprint $t) {
            foreach (['company', 'notes'] as $c) {
                if (Schema::hasColumn('leads', $c)) $t->dropColumn($c);
            }
        });
    }
};
