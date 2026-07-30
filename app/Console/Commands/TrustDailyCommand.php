<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\Trust\TrustRuntime;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inventory Trust — the owner's daily 15 minutes (Spec §7, Pilot Plan Phase B0/B1).
 *
 * Scores yesterday's unscored movements, decays record confidence, then builds the day's
 * investigation queue: top-K posted movements by expected loss = (1−T)·value, PLUS an ε% random
 * sample of clean-looking ones (game theory — staff must never be able to learn the thresholds).
 * Each queue item becomes a trust_investigations row; resolve them with trust:resolve to feed
 * the label factory. With --send, the digest goes to the owner's WhatsApp numbers.
 *
 * Opt-in: tenant setting `trust_digest` = 1 (extra numbers: `trust_digest_numbers`).
 * Schedule daily 06:45 Africa/Kampala. Test now:
 *   php artisan trust:daily --tenant=17 --send --force
 */
final class TrustDailyCommand extends Command
{
    protected $signature = 'trust:daily
        {--tenant= : tenant id (default: all opted-in)}
        {--top=7 : queue size (the owner has 15 minutes, not a shift)}
        {--epsilon=4 : % random audits injected from auto-posted movements}
        {--counts=5 : cycle-count pick list size}
        {--send : actually send the WhatsApp digest}
        {--force : ignore the trust_digest opt-in gate (testing)}';

    protected $description = 'Score recent stock movements, build the daily investigation queue, send the owner digest.';

    public function handle(WhatsAppManager $wa, TenantContext $ctx): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', (int) $this->option('tenant'))->get()
            : Tenant::all();

        foreach ($tenants as $t) {
            if (! $this->option('force') && ! (bool) $t->setting('trust_digest', false)) {
                continue;
            }
            // Console runs have no request-bound tenant; without this every scoped query
            // silently matches nothing (same lesson as cashbook:digest).
            $ctx->set($t->id);
            try {
                $this->runTenant($t, $wa);
            } catch (\Throwable $e) {
                Log::warning('trust:daily failed', ['tenant' => $t->id, 'err' => $e->getMessage()]);
                $this->error($t->name . ': ' . $e->getMessage());
            } finally {
                $ctx->clear();
            }
        }
        return self::SUCCESS;
    }

    private function runTenant(Tenant $t, WhatsAppManager $wa): void
    {
        $rt = TrustRuntime::forTenant($t);
        $scored = $rt->scoreUnscored(48);
        $picks = $rt->decayAndPick(max(0, (int) $this->option('counts')));

        // ---- queue: expected-loss top-K + ε-random, excluding already-open investigations ----
        $open = DB::table('trust_investigations')->where('tenant_id', $t->id)
            ->whereNull('outcome')->pluck('stock_movement_id')->all();

        $candidates = DB::table('trust_scores')
            ->join('stock_movements', 'stock_movements.id', '=', 'trust_scores.stock_movement_id')
            ->where('trust_scores.tenant_id', $t->id)
            ->where('stock_movements.created_at', '>=', now()->subHours(48))
            ->whereNotIn('trust_scores.stock_movement_id', $open ?: [0])
            ->select('trust_scores.*', 'stock_movements.type', 'stock_movements.sku',
                     'stock_movements.product_id', 'stock_movements.location',
                     'stock_movements.qty', 'stock_movements.created_by',
                     'stock_movements.created_at as moved_at')
            ->get();

        $top = $candidates->sortByDesc('expected_loss')
            ->take(max(1, (int) $this->option('top')))->values();

        $eps = max(0, (int) $this->option('epsilon'));
        $random = collect();
        if ($eps > 0) {
            $pool = $candidates->where('decision', 'auto_post')
                ->whereNotIn('stock_movement_id', $top->pluck('stock_movement_id'));
            $take = (int) ceil($pool->count() * $eps / 100);
            $random = $pool->shuffle()->take(min($take, 3))->values();   // never flood the owner
        }

        $queue = [];
        foreach ([['queue', $top], ['random', $random]] as [$source, $set]) {
            foreach ($set as $row) {
                $invId = DB::table('trust_investigations')->insertGetId([
                    'tenant_id' => $t->id, 'stock_movement_id' => $row->stock_movement_id,
                    'source' => $source, 'expected_loss' => $row->expected_loss,
                    'created_at' => now(), 'updated_at' => now()]);
                $queue[] = ['source' => $source, 'row' => $row, 'id' => $invId];
            }
        }

        // ---- digest ----
        $text = $this->digestText($t, $scored, $candidates, $queue, $picks);
        $this->line($text);

        if ($this->option('send')) {
            $numbers = method_exists($t, 'ownerAlertNumbers') ? $t->ownerAlertNumbers() : [];
            $extra = array_filter(array_map('trim',
                preg_split('/[,\s]+/', (string) $t->setting('trust_digest_numbers', ''))));
            $numbers = array_values(array_unique(array_merge($numbers, $extra)));
            $instance = $t->whatsapp_instance ?? null;
            if ($instance && $numbers) {
                $gw = $wa->forTenant($t);
                foreach ($numbers as $to) {
                    try {
                        $gw->sendText($instance, $to, $text);
                    } catch (\Throwable $e) {
                        Log::warning('trust:daily send failed', ['to' => $to, 'err' => $e->getMessage()]);
                    }
                }
                $this->info('Sent to ' . count($numbers) . ' number(s).');
            } else {
                $this->warn('No WhatsApp instance/numbers configured — printed only.');
            }
        }
    }

    /** One WhatsApp-length daily brief: headline number, the short list, the count list. */
    private function digestText(Tenant $t, int $scored, $candidates, array $queue, array $picks): string
    {
        $totalEl = round((float) $candidates->sum('expected_loss'), 0);
        $holds = $candidates->where('decision', 'hold')->count();
        $steps = $candidates->where('decision', 'step_up')->count();

        $l = [];
        $l[] = '🛡 *Inventory Trust — ' . now()->format('D d M') . '* (' . $t->name . ')';
        $l[] = sprintf('%d movements scored · %d flagged (%d hold, %d step-up) · value at risk ~%s',
            max($scored, $candidates->count()), $holds + $steps, $holds, $steps, number_format($totalEl));
        if ($queue) {
            $l[] = '';
            $l[] = '*Check these today* (' . count($queue) . ' items, ~15 min):';
            $i = 1;
            foreach ($queue as $q) {
                $r = $q['row'];
                $reasons = json_decode((string) $r->reason_codes, true) ?: [];
                $why = $reasons ? str_replace('_', ' ', $reasons[0]['code']) : '';
                $tag = $q['source'] === 'random' ? ' _(spot check)_' : '';
                $l[] = sprintf('%d. [#%d] %s %s ×%s @ %s — T %s, ~%s at risk. %s%s',
                    $i++, $q['id'], $r->type, $r->sku ?: ('#' . $r->product_id), rtrim(rtrim((string) $r->qty, '0'), '.'),
                    $r->location, $r->trust, number_format((float) $r->expected_loss),
                    $why ? ucfirst($why) . '.' : '', $tag);
            }
            $l[] = '_Reply: trust:resolve <id> clean|error|confirmed_loss_';
        } else {
            $l[] = '✅ Nothing needs your attention today.';
        }
        if ($picks) {
            $l[] = '';
            $l[] = '*Count today* (lowest confidence × value):';
            foreach ($picks as $p) {
                $l[] = sprintf('· %s @ %s — confidence %d%%',
                    $p['name'], $p['location'], (int) round($p['confidence'] * 100));
            }
        }
        return implode("\n", $l);
    }
}
