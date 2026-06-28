<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finding 1 (Drop-1 review): enforce append-only at the DATABASE, not just in code.
 * A partial unique index makes it impossible to have two current versions of the same fact —
 * BusinessMemory must supersede (flip is_current) before inserting the next version.
 */
return new class extends Migration {
    public function up(): void
    {
        // Postgres partial unique index. (Guarded so the migration is a no-op on non-pgsql.)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS knowledge_facts_current_unique
                           ON knowledge_facts (tenant_id, capability, fact_type, key)
                           WHERE is_current');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS knowledge_facts_current_unique');
        }
    }
};
