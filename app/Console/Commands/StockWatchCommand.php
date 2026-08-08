<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Inventory\StockAlert;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Stock parity WATCHDOG — the "stays honest" guard once the Stock Ledger is switched on.
 *
 * Runs the read-only `stock:reconcile --json` for each enabled tenant and, if the live stock has
 * drifted from the ledger, WhatsApps the owner so it's caught the same day instead of quietly rotting.
 * DORMANT until stock_v2 is ON for a tenant (so scheduling it before enablement does nothing) — pass
 * --force to check regardless. Writes nothing itself; the reconcile it calls is read-only.
 *
 * v4.65.7 — it now tells the two findings apart. Home drift (the ledger disagrees with the product
 * screen — somebody's on-hand is wrong on a screen right now) alerts daily. Misfiled stock (movements
 * at a location the shop does not use — inert, nothing reads them) alerts only with --include-misfiled,
 * scheduled weekly. Before this, a shop with perfect stock and 219,445 misfiled units would have been
 * told its numbers were broken every morning until somebody muted it, and a muted watchdog is a cron
 * job burning battery.
 *
 * Scheduled in routes/console.php:
 *   Schedule::command('stock:watch')->dailyAt('06:00');
 *   Schedule::command('stock:watch --include-misfiled')->weeklyOn(1, '08:20');
 */
class StockWatchCommand extends Command
{
    protected $signature = 'stock:watch
        {tenant? : Tenant id to check (omit for every tenant with the ledger switched on)}
        {--force : Check even when stock_v2 is off (normally dormant until enablement)}
        {--tolerance=0 : Absolute qty drift tolerated per product before alerting}
        {--include-misfiled : Also report movements at a location this shop does not use (weekly, not daily)}';

    protected $description = 'Daily parity watchdog: WhatsApps the owner if live stock drifts from the Stock Ledger.';

    public function handle(WhatsAppManager $wa): int
    {
        $id = $this->argument('tenant');
        $tenants = $id !== null ? Tenant::where('id', (int) $id)->get() : Tenant::orderBy('id')->get();

        foreach ($tenants as $t) {
            if (! $this->option('force') && ! (bool) $t->setting('stock_v2', false)) {
                continue; // dormant until the ledger is the source of truth for this tenant
            }

            $data = null;
            try {
                Artisan::call('stock:reconcile', [
                    'tenant' => (int) $t->id,
                    '--json' => true,
                    '--tolerance' => (string) max(0.0, (float) $this->option('tolerance')),
                ]);
                $data = json_decode(Artisan::output(), true);
            } catch (\Throwable $e) {
                Log::warning('stock:watch reconcile failed for tenant ' . $t->id . ': ' . $e->getMessage());
                continue;
            }
            if (! is_array($data)) {
                continue;
            }

            // The message is built by a pure function so the one thing that matters here — that a
            // shop whose stock is fine is never told otherwise — is testable without a WhatsApp
            // gateway, a tenant or a database.
            $alert = StockAlert::from($data, (bool) $this->option('include-misfiled'));
            if ($alert === null) {
                continue;
            }
            $msg = $alert['text'];

            $instance = (string) ($t->whatsapp_instance ?? '');
            $numbers  = method_exists($t, 'ownerAlertNumbers') ? $t->ownerAlertNumbers() : [];
            if ($instance === '' || ! count($numbers)) {
                Log::info('stock:watch has something to say about tenant ' . $t->id
                    . ' but no WhatsApp instance / owner number is set: ' . $msg);
                continue;
            }
            $gw = $wa->forTenant($t);
            foreach ($numbers as $n) {
                try { $gw->sendText($instance, $n, $msg); } catch (\Throwable $e) { Log::warning('stock:watch alert send failed: ' . $e->getMessage()); }
            }
            $this->info("stock:watch — alerted owner of tenant {$t->id} ("
                . ($alert['urgent'] ? 'drift at home' : 'misfiled stock') . ').');
        }

        return self::SUCCESS;
    }
}
