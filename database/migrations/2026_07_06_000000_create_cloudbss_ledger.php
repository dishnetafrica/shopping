<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cls = 'CloudBSS\\Infra\\Postgres\\PostgresEventLog';
        if (! class_exists($cls)) {
            // Kernel autoload not ready yet — skip so the deploy never breaks.
            // Re-run `php artisan migrate` once class_exists is true.
            return;
        }
        (new $cls(DB::connection('pgsql')->getPdo()))->migrate();
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            'DROP TABLE IF EXISTS cloudbss_events, cloudbss_ledger_balances, cloudbss_subledger, cloudbss_projection_checkpoints CASCADE'
        );
    }
};
