<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes — the v3 list screens sort/filter by (tenant_id, created_at/issued_at/status/…)
 * and the sidebar counts + cashbook scan (tenant_id, stage, status). Several hot tables lacked those
 * composite indexes, forcing sequential scans + filesorts on every load. Pure indexes — additive,
 * reversible, no data change. Idempotent (CREATE INDEX IF NOT EXISTS), and skips any missing column.
 */
return new class extends Migration
{
    /** [table, index name, [columns]] — created only when the table and every column exist. */
    private function specs(): array
    {
        return [
            ['orders', 'idx_orders_tenant_created', ['tenant_id', 'created_at']],
            ['orders', 'idx_orders_tenant_delivered', ['tenant_id', 'delivered_at']],
            ['invoices', 'idx_invoices_tenant_status', ['tenant_id', 'status']],
            ['invoices', 'idx_invoices_tenant_issued', ['tenant_id', 'issued_at']],
            ['invoices', 'idx_invoices_tenant_due', ['tenant_id', 'due_at']],
            ['customer_payments', 'idx_cpay_tenant_created', ['tenant_id', 'created_at']],
            ['order_costs', 'idx_ocost_tenant_stage_status', ['tenant_id', 'stage', 'status']],
            ['customer_profiles', 'idx_cprof_tenant_name', ['tenant_id', 'name']],
            ['quotations', 'idx_quot_tenant_status', ['tenant_id', 'status']],
            ['quotations', 'idx_quot_tenant_order', ['tenant_id', 'order_id']],
            ['demand_plan_lines', 'idx_dpl_tenant_plan', ['tenant_id', 'demand_plan_id']],
        ];
    }

    public function up(): void
    {
        foreach ($this->specs() as [$table, $name, $cols]) {
            if (! Schema::hasTable($table)) { continue; }
            $ok = true;
            foreach ($cols as $c) { if (! Schema::hasColumn($table, $c)) { $ok = false; break; } }
            if (! $ok) { continue; }
            $colSql = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
            try {
                DB::statement('CREATE INDEX IF NOT EXISTS ' . $name . ' ON "' . $table . '" (' . $colSql . ')');
            } catch (\Throwable $e) {
                // MySQL/SQLite lack "IF NOT EXISTS" for indexes — ignore a duplicate/definition error.
            }
        }
    }

    public function down(): void
    {
        foreach ($this->specs() as [$table, $name, $cols]) {
            try {
                DB::statement('DROP INDEX IF EXISTS ' . $name);
            } catch (\Throwable $e) {
                try { Schema::table($table, fn ($t) => $t->dropIndex($name)); } catch (\Throwable $e2) {}
            }
        }
    }
};
