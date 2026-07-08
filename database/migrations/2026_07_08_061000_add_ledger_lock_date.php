<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3 — period lock. Adds a lock_date to ledger_config. Additive, guarded. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ledger_config') && ! Schema::hasColumn('ledger_config', 'lock_date')) {
            Schema::table('ledger_config', function (Blueprint $t) {
                $t->date('lock_date')->nullable()->after('live_posting');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ledger_config') && Schema::hasColumn('ledger_config', 'lock_date')) {
            Schema::table('ledger_config', function (Blueprint $t) {
                $t->dropColumn('lock_date');
            });
        }
    }
};
