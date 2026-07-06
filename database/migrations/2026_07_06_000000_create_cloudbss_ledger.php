<?php declare(strict_types=1);

/**
 * Copy this into ShopBot's database/migrations/ (rename with a timestamp prefix, e.g.
 * 2026_07_06_000000_create_cloudbss_ledger.php) so EasyPanel provisions the finance tables on
 * deploy — NOT on every web request.
 *
 * It creates cloudbss_events (immutable log), cloudbss_ledger_balances (durable projection), and
 * cloudbss_projection_checkpoints in your existing Postgres (the `pgsql` connection = evo_saaspg).
 * The tables are prefixed so they never collide with app tables in the shared `evo` database.
 */

use CloudBSS\Infra\Postgres\PostgresEventLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // migrate() runs infra/Postgres/schema.sql (one source of truth) against the pgsql PDO.
        (new PostgresEventLog(DB::connection('pgsql')->getPdo()))->migrate();
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            'DROP TABLE IF EXISTS cloudbss_events, cloudbss_ledger_balances, cloudbss_projection_checkpoints CASCADE'
        );
    }
};
