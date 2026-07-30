<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\Trust\EntityTrust;
use App\Services\Trust\SignalCatalog;
use App\Services\Trust\TrustScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory Trust — GATE-1 backtest (Pilot Plan Phase A).
 *
 * READ-ONLY by default: replays the tenant's stock_movements CHRONOLOGICALLY, building actor
 * reputation causally as it goes (a movement is scored using only what was knowable before it),
 * and prints the top-N transactions by expected loss with their reason codes — the list Bhavin
 * and Rupesh adjudicate against records and memory. GATE-1 passes if ≥6 of the top-20 are real.
 *
 * Causality note: negative ADJUSTs are treated as revealed variances and attributed to the actors
 * who touched that SKU+location in the prior 14 days — but only for movements scored AFTER the
 * adjustment, never before. The report separately annotates flagged movements that were later
 * followed by an adjustment ("⚠ adj +Nd") as a validation aid; that annotation is not in the score.
 *
 *   php artisan trust:backtest --tenant=17 --months=12 --top=20 [--write]
 */
final class TrustBacktestCommand extends Command
{
    protected $signature = 'trust:backtest
        {--tenant= : tenant id (required)}
        {--months=12 : how far back to replay}
        {--top=20 : size of the GATE-1 list}
        {--write : persist trust_scores / entity stats / variance events (default: read-only)}';

    protected $description = 'Replay stock_movements through the Inventory Trust Engine; print the GATE-1 expected-loss list.';

    public function handle(): int
    {
        $id = (int) $this->option('tenant');
        if (! $id) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $tt) {
                $this->line('  --tenant=' . $tt->id . '   ' . $tt->name);
            }
            return self::FAILURE;
        }
        $tenant = Tenant::findOrFail($id);
        $write = (bool) $this->option('write');
        $since = now()->subMonths(max(1, (int) $this->option('months')));

        $movements = StockMovement::where('tenant_id', $id)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')->orderBy('id')->get();

        if ($movements->isEmpty()) {
            $this->warn('No stock_movements in the window. If the ledger is new, run the pilot forward (Phase B0) instead of a backtest.');
            return self::SUCCESS;
        }

        // Unit values from the catalogue (cost preferred, price fallback).
        $unitValue = Product::where('tenant_id', $id)->get()
            ->mapWithKeys(fn ($p) => [$p->id => (float) ($p->cost_price ?? $p->price ?? 0)])->all();
        $valueOf = fn (StockMovement $m): float => abs((float) $m->qty) * ($unitValue[$m->product_id] ?? 0.0);

        $values = $movements->map($valueOf)->filter(fn ($v) => $v > 0)->sort()->values();
        $median = $values->isEmpty() ? 1.0 : (float) $values[(int) floor($values->count() / 2)];

        // ---- causal replay ----
        $actorStats = [];          // created_by => ['alpha','beta']
        $recent = [];              // sku|location => [['ts','actor','value','type'], ...] (14-day tail)
        $burst = [];               // actor|sku|type => [timestamps] (7-day tail)
        $adjustAfter = [];         // movement_id => days-until-later-adjust (report annotation only)
        $scored = [];
        $varianceEvents = [];

        foreach ($movements as $m) {
            $ts = $m->created_at->getTimestamp();
            $key = ($m->sku ?: (string) $m->product_id) . '|' . $m->location;
            $actor = $m->created_by ?: '';
            $value = $valueOf($m);
            $type = strtoupper((string) $m->type);

            // patterns (computed from the PAST only)
            $patterns = [];
            $hour = (int) $m->created_at->format('G');
            if ($hour < 6 || $hour >= 22) {
                $patterns[] = 'off_hours';
            }
            if ($type === 'ADJUST' && (float) $m->qty < 0) {
                $patterns[] = 'negative_adjust';
            }
            $q = abs((float) $m->qty);
            if (in_array($type, ['OUT', 'ADJUST'], true) && $q >= 20 && fmod($q, 10.0) === 0.0) {
                $patterns[] = 'round_qty';
            }
            if ($actor !== '' && in_array($type, ['OUT', 'ADJUST'], true)) {
                $bk = $actor . '|' . $key . '|' . $type;
                $burst[$bk] = array_values(array_filter($burst[$bk] ?? [], fn ($t) => $t > $ts - 7 * 86400));
                if (count($burst[$bk]) >= 3) {
                    $patterns[] = 'burst';
                }
                $burst[$bk][] = $ts;
            }

            // ledger-derived evidence
            $evidence = [
                'ref_linked' => ($m->ref_type && $m->ref_id) ? 'present_pass' : 'expected_missing',
                'actor_known' => $actor !== '' ? 'present_pass' : 'expected_missing',
                'note_present' => trim((string) $m->note) !== '' ? 'present_pass' : 'expected_missing',
            ];

            $stat = $actor !== '' ? ($actorStats[$actor] ?? EntityTrust::coldStart()) : null;
            $r = TrustScorer::score($type, $value, $median, $stat, $patterns, $evidence);
            $r['movement'] = $m;
            $r['value'] = $value;
            $scored[] = $r;

            // reputation update: default clean; negative ADJUST reveals a variance and taxes
            // the actors who touched this SKU+location in the prior 14 days (causal: affects
            // only FUTURE scores).
            if ($type === 'ADJUST' && (float) $m->qty < 0) {
                $window = array_filter($recent[$key] ?? [], fn ($e) => $e['ts'] > $ts - 14 * 86400);
                $sev = $value > 0 ? min(1.0, $value / max($median * 4, 0.01)) : 0.25;
                foreach (array_unique(array_filter(array_column($window, 'actor'))) as $a) {
                    $s = $actorStats[$a] ?? EntityTrust::coldStart();
                    $actorStats[$a] = EntityTrust::update($s['alpha'], $s['beta'], false, $sev);
                }
                foreach ($window as $e) {
                    if (isset($adjustAfter[$e['id']])) {
                        continue;
                    }
                    $adjustAfter[$e['id']] = (int) ceil(($ts - $e['ts']) / 86400);
                }
                $varianceEvents[] = [
                    'tenant_id' => $id, 'product_id' => $m->product_id, 'sku' => $m->sku,
                    'location' => $m->location, 'variance_qty' => (float) $m->qty,
                    'variance_value' => $value, 'adjust_movement_id' => $m->id,
                    'window_start' => date('Y-m-d H:i:s', $ts - 14 * 86400),
                    'window_end' => $m->created_at->toDateTimeString(),
                    'created_at' => now(), 'updated_at' => now(),
                ];
            } elseif ($actor !== '') {
                $s = $actorStats[$actor] ?? EntityTrust::coldStart();
                $actorStats[$actor] = EntityTrust::update($s['alpha'], $s['beta'], true);
            }

            $recent[$key] = array_slice(array_filter($recent[$key] ?? [],
                fn ($e) => $e['ts'] > $ts - 14 * 86400), -50);
            $recent[$key][] = ['ts' => $ts, 'actor' => $actor, 'value' => $value, 'id' => $m->id];
        }

        // ---- persist (optional) ----
        if ($write) {
            $this->persist($id, $scored, $actorStats, $varianceEvents);
        }

        // ---- report ----
        $n = $movements->count();
        $holds = count(array_filter($scored, fn ($r) => $r['decision'] === 'hold'));
        $steps = count(array_filter($scored, fn ($r) => $r['decision'] === 'step_up'));
        $this->info(sprintf('%s — %d movements since %s · median value %s · decisions: %d auto / %d step-up / %d hold',
            $tenant->name, $n, $since->toDateString(), number_format($median, 0), $n - $holds - $steps, $steps, $holds));

        usort($scored, fn ($a, $b) => $b['expected_loss'] <=> $a['expected_loss']);
        $top = array_slice($scored, 0, max(1, (int) $this->option('top')));

        $this->newLine();
        $this->info('=== GATE-1 LIST — adjudicate each row: clean | error | confirmed_loss ===');
        $rows = [];
        foreach ($top as $r) {
            $m = $r['movement'];
            $why = implode(', ', array_map(
                fn ($c) => $c['code'] . ' ' . sprintf('%+.1f', $c['contribution']),
                array_slice($r['reason_codes'], 0, 3)));
            $rows[] = [
                $m->id, $m->created_at->format('Y-m-d H:i'), $m->type,
                ($m->sku ?: ('#' . $m->product_id)), $m->location, (string) $m->qty,
                number_format($r['value'], 0), $r['trust'], $r['decision'],
                number_format($r['expected_loss'], 0),
                isset($adjustAfter[$m->id]) ? '⚠ adj +' . $adjustAfter[$m->id] . 'd' : '',
                $m->created_by ?: '—', $why,
            ];
        }
        $this->table(['mv id', 'when', 'type', 'sku', 'loc', 'qty', 'value', 'T', 'decision',
                      'E[loss]', 'later', 'actor', 'top reasons'], $rows);

        // actor scorecard
        $this->newLine();
        $this->info('=== Actor scorecard (routes review only — never an accusation) ===');
        $ar = [];
        foreach ($actorStats as $a => $s) {
            $mean = $s['alpha'] / max($s['alpha'] + $s['beta'], 1e-6);
            $ar[] = [$a, round($mean * 100, 1) . '%', round($s['alpha'] + $s['beta'], 1)];
        }
        usort($ar, fn ($x, $y) => (float) $x[1] <=> (float) $y[1]);
        $this->table(['actor', 'clean rate (decayed)', 'weight'], array_slice($ar, 0, 15));

        $this->newLine();
        $this->line('GATE-1 passes if ≥6 of the top-' . count($top) . ' are adjudicated error/confirmed_loss.');
        if (! $write) {
            $this->line('(read-only run — add --write to persist trust_scores, entity stats, variance events)');
        }
        return self::SUCCESS;
    }

    private function persist(int $tenantId, array $scored, array $actorStats, array $varianceEvents): void
    {
        if (! Schema::hasTable('trust_scores')) {
            $this->error('trust tables missing — run: php artisan migrate --force');
            return;
        }
        DB::transaction(function () use ($tenantId, $scored, $actorStats, $varianceEvents) {
            foreach (SignalCatalog::signals() as $code => $def) {
                DB::table('trust_signal_definitions')->updateOrInsert(['code' => $code], [
                    'independence_class' => $def['class'], 'logit_present' => $def['present'],
                    'logit_missing' => $def['missing'], 'applies_to' => json_encode($def['applies']),
                    'version' => SignalCatalog::VERSION, 'active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($scored as $r) {
                DB::table('trust_scores')->updateOrInsert(
                    ['stock_movement_id' => $r['movement']->id],
                    ['tenant_id' => $tenantId, 'score_version' => SignalCatalog::VERSION,
                     'logit_base' => $r['logit_base'], 'logit_prior' => $r['logit_prior'],
                     'logit_evidence' => $r['logit_evidence'], 'trust' => $r['trust'],
                     'trust_required' => $r['trust_required'], 'value_at_risk' => $r['value'],
                     'expected_loss' => $r['expected_loss'], 'decision' => $r['decision'],
                     'reason_codes' => json_encode($r['reason_codes']),
                     'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($actorStats as $a => $s) {
                DB::table('trust_entity_stats')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'entity_type' => 'actor',
                     'entity_key' => $a, 'txn_type' => '*'],
                    ['alpha' => $s['alpha'], 'beta' => $s['beta'],
                     'created_at' => now(), 'updated_at' => now()]);
            }
            if ($varianceEvents) {
                DB::table('trust_variance_events')->insert($varianceEvents);
            }
        });
        $this->info('Persisted: ' . count($scored) . ' scores, ' . count($actorStats)
            . ' actor stats, ' . count($varianceEvents) . ' variance events.');
    }
}
