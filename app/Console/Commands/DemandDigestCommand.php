<?php

namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * demand:digest — the 08:00 purchasing signal (2026-07-18).
 *
 * The nightly learner (02:30) already records every item customers asked for that
 * we don't stock. This command turns yesterday + the last 7 days into ONE WhatsApp
 * message to the team — delivered the same way order notifications are — so the
 * morning stock decision is made with the customers' own voices on the table.
 * Silent when there's nothing to say (no empty spam, ever).
 */
class DemandDigestCommand extends Command
{
    protected $signature   = 'demand:digest {--tenant=0} {--dry : Print the message, send nothing}';
    protected $description = 'Morning WhatsApp digest of asked-but-not-stocked items to the team';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->get();

        foreach ($tenants as $t) {
            if (! (bool) $t->setting('relationship_memory', false)) continue;

            $log = (array) $t->setting('learning_log', []);
            if (! $log) continue;

            $yesterday = now()->subDay()->toDateString();
            $fresh = array_values(array_unique((array) (($log[$yesterday]['demand'] ?? []))));

            $week = collect($log)->filter(fn ($v, $d) => $d >= now()->subDays(7)->toDateString())
                ->pluck('demand')->flatten()->filter()
                ->countBy(fn ($d) => mb_strtolower(trim((string) $d)))
                ->sortDesc()->take(10);

            if (empty($fresh) && $week->isEmpty()) continue;   // nothing to say → say nothing

            $lines = ["\u{1F4CB} *Customers asked — we don't stock* (".now()->format('d M').')'];
            if ($fresh) {
                $lines[] = '';
                $lines[] = '*Yesterday:*';
                foreach (array_slice($fresh, 0, 8) as $d) $lines[] = '• '.$d;
            }
            if ($week->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '*Last 7 days (most asked):*';
                foreach ($week as $d => $n) $lines[] = "• {$n}× ".$d;
            }
            $lines[] = '';
            $lines[] = '_Every line is a customer who wanted to pay us. Worth a look for the next purchase run._';
            $msg = implode("\n", $lines);

            if ($this->option('dry')) {
                $this->line("--- {$t->name} ---");
                $this->line($msg);
                continue;
            }

            // Owner gets it exactly like order notifications…
            NotifyOwner::dispatch($t->id, $msg);
            // …and any extra team numbers (e.g. the purchaser) from one setting.
            foreach (array_filter(array_map('trim', explode(',', (string) $t->setting('demand_digest_numbers', '')))) as $num) {
                $num = preg_replace('/[^0-9]/', '', $num);
                if ($num !== '') NotifyOwner::dispatch($t->id, $msg, $num);
            }
            $this->info("{$t->name}: digest sent (".count($fresh).' new · '.$week->count().' weekly)');
        }

        return self::SUCCESS;
    }
}
