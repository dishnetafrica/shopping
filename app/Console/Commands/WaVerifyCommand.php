<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WhatsappChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Is this shop able to talk to its customers RIGHT NOW?
 *
 *   php artisan wa:verify                          read-only, safe any time
 *   php artisan wa:verify --tenant=17              a specific shop
 *   php artisan wa:verify --send=211922281312      LIVE: really sends, waits for WhatsApp's receipt
 *   php artisan wa:verify --quiet                  exit code only (cron / uptime checks)
 *
 * Exit 0 = delivering. Exit 1 = not delivering.
 *
 * Written because on 11 Aug 2026 the honest answer had been "no" for twenty-four days while every
 * single thing anyone would think to check said yes. The connection was open, webhooks were
 * arriving, the queue worker was healthy, the AI was writing good replies — and WhatsApp was
 * rejecting every one of them with error 463. Nothing in the stack disagreed with itself, so
 * nothing looked wrong. The only fact that mattered was whether a delivery receipt had come back,
 * and nothing in the system had ever asked.
 */
class WaVerifyCommand extends Command
{
    protected $signature = 'wa:verify
        {--tenant=17 : Which shop to check}
        {--send= : Send a real test message to this number and wait for the receipt}
        {--wait=45 : Seconds to wait for WhatsApp to confirm the test message}';

    protected $description = 'Verify end to end that a shop can actually deliver WhatsApp messages.';

    /** A rejection this recent means we are actively trying and actively failing. */
    private const ERR_WINDOW_MIN = 90;
    /** Nothing delivered for this long, while rejections keep arriving, is a mute number. */
    private const STALE_HOURS = 6;

    private int $fails = 0;
    private int $warns = 0;

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $t = Tenant::find($tenantId);
        if (! $t) {
            $this->error("No tenant {$tenantId}.");
            return self::FAILURE;
        }

        $this->line('');
        $this->line("  <options=bold>CloudBSS WhatsApp — verification</>");
        $this->line("  <fg=gray>{$t->name} · tenant {$tenantId} · " . now()->format('D j M Y H:i T') . '</>');

        [$base, $key, $instance] = $this->configBlock($t);
        $this->channelsBlock($t, $instance);
        $this->connectionBlock($base, $key, $instance);
        $failedSends = $this->pipelineBlock($tenantId);
        $delivering = $this->deliveryBlock($t, $tenantId, $failedSends);

        if ($to = preg_replace('/\D/', '', (string) $this->option('send'))) {
            $live = $this->liveTest($t, $base, $key, $instance, $to);
            if ($live === false) $delivering = false;
            if ($live === true) $delivering = true;
        }

        $this->line('');
        $this->line('  ' . str_repeat('─', 68));
        if ($delivering && ! $this->fails) {
            $this->line('  <fg=green;options=bold>WORKING</> — messages are reaching customers.');
        } elseif (! $delivering) {
            $this->line('  <fg=red;options=bold>NOT WORKING</> — the shop cannot answer its customers.');
            $this->line('  <fg=gray>Everything upstream can look perfectly healthy while this is true.</>');
        } else {
            $this->line("  <fg=yellow;options=bold>DEGRADED</> — {$this->fails} failed check(s), {$this->warns} warning(s). Delivery itself is alive.");
        }
        $this->line('');

        return $delivering ? self::SUCCESS : self::FAILURE;
    }

    // ── blocks ────────────────────────────────────────────────────────────────────

    private function configBlock(Tenant $t): array
    {
        $this->head('CONFIGURATION');
        $cfg = config('whatsapp.drivers.evolution');
        $driver = (string) ($t->whatsapp_driver ?: config('whatsapp.default', 'evolution'));
        $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $key = (string) ($cfg['api_key'] ?? '');
        $instance = (string) ($t->whatsapp_instance ?? '');

        $this->row($driver ? 'pass' : 'fail', 'driver', $driver);
        $this->row($base ? 'pass' : 'fail', 'evolution base url', $base ?: 'NOT SET');
        $this->row($key ? 'pass' : 'fail', 'evolution api key', $key ? 'set' : 'NOT SET');
        $this->row($instance ? 'pass' : 'fail', 'tenant instance', $instance ?: 'EMPTY');
        $this->row('info', 'tenant number', (string) ($t->whatsapp_number ?: '—'));

        $override = trim((string) $t->setting('wa_send_instance', ''));
        if ($override !== '' && $override !== $instance) {
            $this->row('warn', 'send override active', $override . '  (failover is engaged)');
        }

        return [$base, $key, $instance];
    }

    private function channelsBlock(Tenant $t, string $instance): void
    {
        $this->head('CHANNELS');
        $channels = WhatsappChannel::where('tenant_id', $t->id)->get();
        foreach ($channels as $c) {
            $live = $c->is_primary && $c->active;
            $this->row($live ? 'pass' : 'info', (string) $c->instance,
                ($c->is_primary ? 'primary' : 'secondary') . ($c->active ? ', active' : ', retired'));
        }
        $primaries = $channels->where('is_primary', true)->where('active', true);
        if ($primaries->count() === 0) {
            $this->row('fail', 'primary channel', 'NONE — proactive sends have nowhere to go');
        } elseif ($primaries->count() > 1) {
            // Two primaries is undefined behaviour: which one a proactive send picks depends on
            // row order. Family Shoppers had exactly this on 11 Aug, pointing at a dead instance.
            $this->row('fail', 'primary channel', $primaries->count() . ' marked primary — which one wins is undefined');
        } else {
            $this->row('pass', 'primary channel', 'exactly one');
        }
        if ($instance !== '' && ! $channels->firstWhere('instance', $instance)) {
            $this->row('warn', 'instance ↔ channel', "tenant points at {$instance}, which has no channel row");
        }
    }

    private function connectionBlock(string $base, string $key, string $instance): void
    {
        $this->head('CONNECTION');
        if (! $base || ! $key) {
            $this->row('fail', 'evolution', 'not configured — skipped');
            return;
        }
        try {
            $r = Http::withHeaders(['apikey' => $key])->timeout(15)->get("{$base}/instance/fetchInstances");
            if (! $r->successful()) {
                $this->row('fail', 'evolution reachable', 'HTTP ' . $r->status());
                return;
            }
            $all = $r->json() ?: [];
            $this->row('pass', 'evolution reachable', 'HTTP ' . $r->status() . ', ' . count($all) . ' instance(s)');

            $live = null;
            foreach ($all as $i) {
                if ((string) ($i['name'] ?? $i['instanceName'] ?? '') === $instance) { $live = $i; break; }
            }
            if (! $live) {
                $this->row('fail', 'instance present', "{$instance} does NOT exist in Evolution");
                return;
            }
            $state = (string) ($live['connectionStatus'] ?? $live['status'] ?? '?');
            $who = (string) ($live['profileName'] ?? '?');
            $num = preg_replace('/@.*/', '', (string) ($live['ownerJid'] ?? ''));
            // "open" is necessary and nowhere near sufficient — it read open for 24 days
            // while every send was refused.
            $this->row($state === 'open' ? 'pass' : 'fail', 'connection state', "{$state}  ({$who} · {$num})");
        } catch (\Throwable $e) {
            $this->row('fail', 'evolution reachable', substr($e->getMessage(), 0, 70));
        }
    }

    private function pipelineBlock(int $tenantId): int
    {
        $this->head('PIPELINE (last 24h)');
        $since = now()->subDay();
        $stage = fn (string $s) => (int) DB::table('bot_events')
            ->where('tenant_id', $tenantId)->where('stage', $s)->where('created_at', '>', $since)->count();

        $queued = $stage('queued');
        $started = $stage('started');
        $replied = $stage('ai_replied');
        $failed = $stage('send_failed');
        $errors = $stage('error') + $stage('job_failed');

        $this->row($queued > 0 ? 'pass' : 'warn', 'inbound received',
            $queued . ' queued' . ($queued ? '' : '  (quiet, or webhooks are not arriving)'));
        // The sharpest single check in this block: messages accepted but never picked up.
        $this->row($queued && ! $started ? 'fail' : 'pass', 'queue worker',
            $started . ' jobs started' . ($queued && ! $started ? '  ← QUEUED BUT NEVER STARTED: the worker is dead' : ''));
        $this->row($replied > 0 || ! $started ? 'pass' : 'warn', 'ai replies written', (string) $replied);
        $this->row($errors ? 'warn' : 'pass', 'job errors', (string) $errors);

        try {
            $pending = (int) DB::table('jobs')->count();
            $failedJobs = (int) DB::table('failed_jobs')->count();
            $this->row($pending > 50 ? 'warn' : 'pass', 'queue depth', $pending . ' pending');
            $this->row($failedJobs ? 'warn' : 'pass', 'failed jobs', (string) $failedJobs);
        } catch (\Throwable $e) {
            $this->row('info', 'queue tables', 'not a database queue — check supervisorctl status');
        }

        return $failed;
    }

    private function deliveryBlock(Tenant $t, int $tenantId, int $failedSends): bool
    {
        $this->head('DELIVERY  ← the one that matters');
        $okAt = $this->stamp($t->setting('wa_send_ok_at'));
        $errAt = $this->stamp($t->setting('wa_send_err_at'));

        $this->row($okAt && $okAt->diffInHours(now()) < self::STALE_HOURS ? 'pass' : 'fail',
            'last delivered', $this->ago($okAt));
        $this->row('info', 'last rejected', $this->ago($errAt));
        $this->row($failedSends ? 'fail' : 'pass', 'rejections (24h)', (string) $failedSends);

        $delivering = $this->isDelivering($okAt, $errAt);

        if ($delivering) {
            $this->row('pass', 'VERDICT', 'DELIVERING — customers are being answered');
        } else {
            $this->row('fail', 'VERDICT', 'NOT DELIVERING — the bot is answering into a void');
            $last = DB::table('bot_events')->where('tenant_id', $tenantId)
                ->where('stage', 'send_failed')->latest('created_at')->first();
            if ($last) $this->row('info', 'most recent rejection', (string) $last->detail);
        }

        return $delivering;
    }

    /**
     * Rejections arriving NOW *and* nothing delivered for hours.
     *
     * The AND is the whole design. A quiet shop has a stale wa_send_ok_at and nothing wrong with
     * it; judging on staleness alone would fail every Sunday, the owner would stop believing it,
     * and a monitor nobody believes is worse than no monitor.
     */
    private function isDelivering(?Carbon $okAt, ?Carbon $errAt): bool
    {
        if (! $errAt) return true;                                  // nothing is being refused
        if ($errAt->diffInMinutes(now()) > self::ERR_WINDOW_MIN) return true;   // refusals are stale
        if ($okAt && $okAt->greaterThan($errAt)) return true;        // the last event was a success
        if ($okAt && $okAt->diffInHours(now()) < self::STALE_HOURS) return true; // delivered recently
        return false;
    }

    /** Returns true = delivered, false = rejected, null = no receipt (inconclusive). */
    private function liveTest(Tenant $t, string $base, string $key, string $instance, string $to): ?bool
    {
        $this->head("LIVE TEST → {$to}");
        if (! $base || ! $key || ! $instance) {
            $this->row('fail', 'live test', 'configuration incomplete — skipped');
            return null;
        }

        $beforeOk = $this->stamp($t->fresh()->setting('wa_send_ok_at'));
        $beforeErr = $this->stamp($t->fresh()->setting('wa_send_err_at'));

        try {
            $r = Http::withHeaders(['apikey' => $key])->timeout(25)
                ->post("{$base}/message/sendText/{$instance}",
                    ['number' => $to, 'text' => 'CloudBSS delivery test ' . now()->format('H:i:s')]);
            // ACCEPTED IS NOT DELIVERED. This returns 201 today, while completely broken.
            $this->row($r->successful() ? 'pass' : 'fail', 'evolution accepted', 'HTTP ' . $r->status());
            if ($id = (string) data_get($r->json(), 'key.id', '')) {
                $this->row('info', 'message id', $id);
            }
        } catch (\Throwable $e) {
            $this->row('fail', 'evolution accepted', substr($e->getMessage(), 0, 70));
            return null;
        }

        $wait = max(5, (int) $this->option('wait'));
        $this->line("  <fg=gray>  waiting up to {$wait}s for WhatsApp's delivery receipt…</>");

        for ($i = 0; $i < $wait; $i += 3) {
            sleep(3);
            $f = $t->fresh();
            $nowOk = $this->stamp($f->setting('wa_send_ok_at'));
            $nowErr = $this->stamp($f->setting('wa_send_err_at'));
            if ($nowOk && (! $beforeOk || $nowOk->greaterThan($beforeOk))) {
                $this->row('pass', 'WHATSAPP RECEIPT', 'DELIVERED — it is working');
                return true;
            }
            if ($nowErr && (! $beforeErr || $nowErr->greaterThan($beforeErr))) {
                $this->row('fail', 'WHATSAPP RECEIPT', 'REJECTED — WhatsApp refused it (error 463 territory)');
                return false;
            }
        }
        $this->row('warn', 'WHATSAPP RECEIPT',
            "no receipt in {$wait}s — inconclusive. Check the handset, and that Evolution posts "
            . 'messages.update to the webhook.');
        return null;
    }

    // ── output ────────────────────────────────────────────────────────────────────

    private function head(string $s): void
    {
        $this->line('');
        $this->line("  <options=bold>{$s}</>");
    }

    private function row(string $state, string $label, string $value = ''): void
    {
        $mark = [
            'pass' => '<fg=green>✓</>', 'fail' => '<fg=red>✗</>',
            'warn' => '<fg=yellow>!</>', 'info' => '<fg=gray>·</>',
        ][$state] ?? ' ';
        if ($state === 'fail') $this->fails++;
        if ($state === 'warn') $this->warns++;
        $this->line(sprintf('  %s %s %s', $mark, str_pad($label, 26), $value));
    }

    private function ago(?Carbon $t): string
    {
        if (! $t) return 'never';
        return $t->format('Y-m-d H:i') . ' (' . $t->diffForHumans(now(), ['syntax' => Carbon::DIFF_ABSOLUTE]) . ' ago)';
    }

    private function stamp($v): ?Carbon
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        try { return Carbon::parse($v); } catch (\Throwable $e) { return null; }
    }
}
