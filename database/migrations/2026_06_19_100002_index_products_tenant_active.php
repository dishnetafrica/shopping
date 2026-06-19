<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Speeds up the storefront/bot catalogue query on large catalogues. */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS products_tenant_active_idx ON products (tenant_id, active)');
    }
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_tenant_active_idx');
    }
};
