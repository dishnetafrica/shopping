<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
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
 * Schedule (add during enablement, see DEPLOY-STOCK-LEDGER-ENABLE.txt):
 *   Schedule::command('stock:watch')->dailyAt('06:00');
 */
class StockWatchCommand extends Command
{
    protected $signature = 'stock:watch
        {tenant? : Tenant id to check (omit for every tenant with the ledger switched on)}
        {--force : Check even when stock_v2 is off (normally dormant until enablement)}
        {--tolerance=0 : Absolute qty drift tolerated per product before alerting}';

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
            if (! is_array($data) || ! empty($data['ok'])) {
                continue; // parity — nothing to report
            }

            // Build a short, human summary of where it drifted.
            $lines = [];
            foreach (($data['tenants'] ?? []) as $tr) {
                foreach (($tr['locations'] ?? []) as $locName => $s) {
                    if ((int) ($s['drift_products'] ?? 0) > 0) {
                        $place = ucfirst(str_replace('-consignment', '', (string) $locName));
                        $lines[] = "• {$place}: " . (int) $s['drift_products'] . ' product(s) off (total '
                            . rtrim(rtrim(number_format((float) ($s['total_abs_drift'] ?? 0), 3, '.', ''), '0'), '.') . ')';
                    }
                }
            }
            if (! $lines) continue;

            $msg = "⚠️ *Stock check — needs a look*\n"
                . "The live stock and the stock ledger don't match:\n"
                . implode("\n", $lines)
                . "\n\nRun  stock:reconcile " . (int) $t->id . "  to see which products, then re-seed or adjust."
                . "\n(Fixes: see DEPLOY-STOCK-LEDGER-ENABLE.txt)";

            $instance = (string) ($t->whatsapp_instance ?? '');
            $numbers  = method_exists($t, 'ownerAlertNumbers') ? $t->ownerAlertNumbers() : [];
            if ($instance === '' || ! count($numbers)) {
                Log::info('stock:watch drift for tenant ' . $t->id . ' but no WhatsApp instance / owner number set.');
                continue;
            }
            $gw = $wa->forTenant($t);
            foreach ($numbers as $n) {
                try { $gw->sendText($instance, $n, $msg); } catch (\Throwable $e) { Log::warning('stock:watch alert send failed: ' . $e->getMessage()); }
            }
            $this->info("stock:watch — alerted owner of tenant {$t->id} about stock drift.");
        }

        return self::SUCCESS;
    }
}
