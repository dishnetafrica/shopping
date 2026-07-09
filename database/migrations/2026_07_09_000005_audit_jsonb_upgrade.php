<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Safety net: if audit_logs was created earlier with TEXT snapshots / no request metadata,
 *  bring it up to JSONB + ip/user_agent/request_id. Idempotent — safe if already correct. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) return;

        Schema::table('audit_logs', function (Blueprint $t) {
            if (! Schema::hasColumn('audit_logs','ip_address')) $t->ipAddress('ip_address')->nullable();
            if (! Schema::hasColumn('audit_logs','user_agent')) $t->string('user_agent', 255)->nullable();
            if (! Schema::hasColumn('audit_logs','request_id')) $t->string('request_id', 64)->nullable();
        });

        // Convert TEXT -> JSONB on Postgres. If already jsonb, the statement is skipped/ignored.
        try {
            if (DB::getDriverName() === 'pgsql') {
                foreach (['old_values','new_values'] as $col) {
                    try {
                        DB::statement("ALTER TABLE audit_logs ALTER COLUMN {$col} TYPE jsonb USING NULLIF({$col}::text,'')::jsonb");
                    } catch (\Throwable $e) { /* already jsonb */ }
                }
            }
        } catch (\Throwable $e) { /* non-pgsql: leave as-is */ }
    }

    public function down(): void { /* no-op */ }
};
