<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * demand:weekly — a short weekly WhatsApp to the owner listing:
 *   • ITEMS customers asked for that the shop does NOT stock (purchasing signal), and
 *   • QUESTIONS the bot handled poorly (gaps to fix / products to alias),
 * pulled from the nightly learning log (`learning_log`, written by relationship:learn).
 *
 * Report-only — the shop decides what to add. Opt-in via `relationship_memory` (the same
 * flag that turns on the nightly learning). Optional extra recipients via
 * `demand_weekly_numbers` (comma/space separated). Never throws on a send failure.
 */
class DemandWeeklyCommand extends Command
{
    protected $signature   = 'demand:weekly {--tenant=0} {--days=7} {--force : Send even if learning is off / to preview}';
    protected $description  = 'Weekly WhatsApp: items customers asked for that we do not stock (+ bot gaps)';

    public function handle(WhatsAppManager $wa, TenantContext $ctx): int
    {
        $days = max(1, (int) $this->option('days'));
        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->get();

        foreach ($tenants as $t) {
            if (! (bool) $t->setting('relationship_memory', false) && ! $this->option('force')) {
                continue; // learning off → nothing to report
            }
            if (! $t->whatsapp_instance) {
                continue;
            }
            $ctx->set($t->id);

            // Last N days of the nightly learning log: keys are Y-m-d dates.
            $log    = (array) $t->setting('learning_log', []);
            $cutoff = now()->subDays($days)->toDateString();
            $recent = array_filter($log, fn ($v, $k) => (string) $k >= $cutoff, ARRAY_FILTER_USE_BOTH);

            $demand = collect($recent)->pluck('demand')->flatten()->map(fn ($s) => trim((string) $s))
                ->filter()->countBy()->sortDesc()->take(12);
            $gaps   = collect($recent)->pluck('gaps')->flatten()->map(fn ($s) => trim((string) $s))
                ->filter()->countBy()->sortDesc()->take(6);

            if ($demand->isEmpty() && $gaps->isEmpty()) {
                continue; // nothing worth a message this week
            }

            $lines = ["📊 *Weekly customer demand & gaps* (last {$days} days)", ''];
            if ($demand->isNotEmpty()) {
                $lines[] = '🛒 *Asked for — not matched in your catalogue:*';
                foreach ($demand as $item => $n) {
                    $lines[] = '• ' . $item . ($n > 1 ? " ({$n}×)" : '');
                }
                $lines[] = '';
            }
            if ($gaps->isNotEmpty()) {
                $lines[] = '❓ *Questions the bot struggled with:*';
                foreach ($gaps as $g => $n) {
                    $lines[] = '• ' . $g . ($n > 1 ? " ({$n}×)" : '');
                }
                $lines[] = '';
            }
            $lines[] = '_Add the ones you actually sell to Products (or alias an existing item) so the bot can quote them next time._';
            $msg = implode("\n", $lines);

            // Recipients: owner alert numbers + optional dedicated demand recipients.
            $nums  = method_exists($t, 'ownerAlertNumbers') ? $t->ownerAlertNumbers() : [];
            $extra = array_filter(array_map('trim', preg_split('/[,\s]+/', (string) $t->setting('demand_weekly_numbers', ''))));
            if ($extra) {
                $nums = array_values(array_unique(array_merge($nums, $extra)));
            }
            $nums = array_filter($nums);
            if (! $nums) {
                continue;
            }

            $inst = $t->sendInstance();
            foreach ($nums as $n) {
                try {
                    $wa->forTenant($t)->sendText($inst, $n, $msg);
                    MessageLog::record($t->id, $n, $inst, 'out', 'system', $msg);
                } catch (\Throwable $e) {
                    // best-effort; a send failure must never break the digest for other tenants
                }
            }
            $this->info("demand:weekly sent: {$t->name} (" . $demand->count() . ' demand, ' . $gaps->count() . ' gaps)');
        }

        return self::SUCCESS;
    }
}
