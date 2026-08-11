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
 * Exit 0 = delivering (or partly). 1 = not delivering.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE VERDICT DOES NOT COME FROM wa_send_ok_at, AND THAT LESSON COST A MORNING.
 *
 * The first version of this command judged delivery by whether a success stamp was recent. On
 * 11 Aug 2026 that stamp read 18 July, so it reported a three-week total outage — with total
 * confidence, in red, repeatedly.
 *
 * It was wrong. The shop had delivered roughly a thousand messages a day right through to the
 * previous evening. What had actually happened on 18 July was that Evolution's SUCCESS callbacks
 * stopped matching the in_array($status, ['DELIVERY_ACK','READ','PLAYED']) check in
 * WebhookController — a status-vocabulary change on their side. The ERROR branch still matched,
 * so refusals recorded perfectly while successes silently stopped. A frozen wa_send_ok_at proves
 * nothing whatsoever.
 *
 * The real outage began that same morning at 07:26, when the Evolution instance was deleted and
 * recreated: a fresh session holds none of the privacy tokens WhatsApp requires for replying to
 * existing contacts, so replies started failing with error 463. And even then it was PARTIAL —
 * 21 of 30 customers were still being answered while this command shouted NOT WORKING at them.
 *
 * So the verdict now rests on two numbers that are provably written on every single message:
 * how many customers we tried to reach, and how many of those were refused. And it has three
 * states, not two, because "70% working" is a real and common condition that a boolean cannot
 * express.
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 */
class WaVerifyCommand extends Command
{
    protected $signature = 'wa:verify
        {--tenant=17 : Which shop to check}
        {--send= : Send a real test message to this number and wait for the receipt}
        {--wait=45 : Seconds to wait for WhatsApp to confirm the test message}
        {--hours=6 : How many hours back to judge delivery over}';

    protected $description = 'Verify end to end that a shop can actually deliver WhatsApp messages.';

    /** Below this many customers the sample says nothing — a quiet shop is not a broken one. */
    private const MIN_CUSTOMERS = 8;
    /** Measured on this shop: a healthy day refuses ~3-5% of customers (bad numbers, blocks). */
    private const DEGRADED_RATIO = 0.12;
    /** Measured during the real outage: 9 of 30 customers refused, 30%. */
    private const BROKEN_RATIO = 0.25;

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
        $this->line('  <options=bold>CloudBSS WhatsApp — verification</>');
        $this->line("  <fg=gray>{$t->name} · tenant {$tenantId} · " . now()->format('D j M Y H:i T') . '</>');

        [$base, $key, $instance] = $this->configBlock($t);
        $this->channelsBlock($t, $instance);
        $this->connectionBlock($base, $key, $instance);
        $failedSends = $this->pipelineBlock($tenantId);
        [$delivering, $state] = $this->deliveryBlock($t, $tenantId, $failedSends);

        if ($to = preg_replace('/\D/', '', (string) $this->option('send'))) {
            $live = $this->liveTest($t, $base, $key, $instance, $to);
            // One test message is one data point about ONE contact. Error 463 is per-contact, so
            // a single rejection does not mean the shop is mute — that is exactly the mistake
            // this command used to make. It sharpens the verdict, it does not replace it.
            if ($live === false && $state === 'delivering') $state = 'degraded';
            if ($live === true && $state === 'broken') $state = 'degraded';
        }

        $this->line('');
        $this->line('  ' . str_repeat('─', 68));
        if ($state === 'broken') {
            $this->line('  <fg=red;options=bold>NOT WORKING</> — most customers are not being answered.');
            $this->line('  <fg=gray>Everything upstream can look perfectly healthy while this is true.</>');
            $delivering = false;
        } elseif ($state === 'degraded') {
            $this->line('  <fg=yellow;options=bold>PARTLY WORKING</> — some customers are answered, some are refused.');
            $this->line('  <fg=gray>Usually per-contact: a session missing privacy tokens for older contacts.</>');
        } elseif ($state === 'quiet') {
            $this->line('  <fg=yellow;options=bold>TOO QUIET TO JUDGE</> — not enough messages in the window.');
        } else {
            $this->line('  <fg=green;options=bold>WORKING</> — messages are reaching customers.');
        }
        $this->line('');

        return $delivering ? self::SUCCESS : self::FAILURE;
    }

    // ── the verdict ───────────────────────────────────────────────────────────────

    /** @return array{0: bool, 1: string}  [exit-ok, state] */
    private function deliveryBlock(Tenant $t, int $tenantId, int $failedSends): array
    {
        $this->head('DELIVERY  ← the one that matters');

        $hours = max(1, (int) $this->option('hours'));
        [$messaged, $rejected] = $this->reach($tenantId, now()->subHours($hours));
        [$dayMessaged, $dayRejected] = $this->reach($tenantId, today());

        // Shown, but NOT used for the verdict. See the class docblock: this stamp froze on
        // 18 July while the shop delivered a thousand messages a day for three more weeks.
        $this->row('info', 'last delivered stamp', $this->ago($this->stamp($t->setting('wa_send_ok_at')))
            . '  (unreliable — see docblock)');
        $this->row('info', 'last rejected', $this->ago($this->stamp($t->setting('wa_send_err_at'))));
        $this->row($failedSends ? 'warn' : 'pass', 'rejection events (24h)', (string) $failedSends);
        $this->row('info', 'today', "{$dayRejected} of {$dayMessaged} customers refused");

        $ratio = $messaged > 0 ? $rejected / $messaged : 0.0;
        $label = sprintf('%d of %d customers refused (%.0f%%) in the last %dh',
            $rejected, $messaged, $ratio * 100, $hours);

        if ($messaged < self::MIN_CUSTOMERS) {
            $this->row('warn', 'VERDICT', "too few messages to judge — {$label}");
            return [true, 'quiet'];
        }
        if ($ratio >= self::BROKEN_RATIO) {
            $this->row('fail', 'VERDICT', "NOT DELIVERING — {$label}");
            if ($last = DB::table('bot_events')->where('tenant_id', $tenantId)
                ->where('stage', 'send_failed')->latest('created_at')->first()) {
                $this->row('info', 'most recent rejection', (string) $last->detail);
            }
            return [false, 'broken'];
        }
        if ($ratio >= self::DEGRADED_RATIO) {
            $this->row('warn', 'VERDICT', "PARTLY DELIVERING — {$label}");
            return [true, 'degraded'];
        }
        $this->row('pass', 'VERDICT', "DELIVERING — {$label}");
        return [true, 'delivering'];
    }

    /**
     * Distinct customers we tried to message, and distinct customers who were refused.
     *
     * Counted by CUSTOMER, not by message, because "9 of your 30 customers got no answer today"
     * is a sentence a shopkeeper can act on and "70 rejections" is not. Both tables are written
     * on every message, unlike the settings stamps.
     *
     * @return array{0:int, 1:int}
     */
    private function reach(int $tenantId, Carbon $since): array
    {
        $messaged = (int) DB::table('messages')->where('tenant_id', $tenantId)
            ->where('direction', 'out')->where('created_at', '>=', $since)
            ->distinct()->count('customer_phone');
        $rejected = (int) DB::table('bot_events')->where('tenant_id', $tenantId)
            ->where('stage', 'send_failed')->where('created_at', '>=', $since)
            ->distinct()->count('phone');

        // A refusal can outlive its outbound row's window; never report more refused than tried.
        return [$messaged, $messaged > 0 ? min($rejected, $messaged) : $rejected];
    }

    // ── the other blocks ──────────────────────────────────────────────────────────

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
            // Which one a proactive send picks depends on row order. This shop had exactly that.
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
            // "open" is necessary and nowhere near sufficient: it read open throughout the outage.
            $this->row($state === 'open' ? 'pass' : 'fail', 'connection state', "{$state}  ({$who} · {$num})");

            // A session created minutes ago has no privacy tokens for existing contacts, which is
            // the 463 failure. Worth surfacing, because recreating the instance LOOKS like a fix.
            if ($created = (string) ($live['createdAt'] ?? '')) {
                try {
                    $age = Carbon::parse($created);
                    $this->row($age->diffInHours(now()) < 24 ? 'warn' : 'info', 'session created',
                        $age->format('Y-m-d H:i') . ($age->diffInHours(now()) < 24
                            ? '  ← RECREATED TODAY. A fresh session loses the privacy tokens for '
                              . 'existing contacts, and replies to them fail with 463.' : ''));
                } catch (\Throwable $e) { /* informational only */ }
            }
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
        // The sharpest single check here: messages accepted but never picked up.
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
            // ACCEPTED IS NOT DELIVERED. This returns 201 even while every message is refused.
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
            $nowErr = $this->stamp($f->setting('wa_send_err_at'));
            if ($nowErr && (! $beforeErr || $nowErr->greaterThan($beforeErr))) {
                $this->row('fail', 'WHATSAPP RECEIPT', 'REJECTED for THIS contact (error 463 territory)');
                $this->row('info', '', 'one contact, not a verdict — 463 is per-contact');
                return false;
            }
            // The success stamp is unreliable (class docblock), so an absent rejection within the
            // window is the best positive signal a single test can offer. Reported as such.
            $nowOk = $this->stamp($f->setting('wa_send_ok_at'));
            if ($nowOk && (! $beforeOk || $nowOk->greaterThan($beforeOk))) {
                $this->row('pass', 'WHATSAPP RECEIPT', 'DELIVERED');
                return true;
            }
        }
        $this->row('warn', 'WHATSAPP RECEIPT',
            "no rejection in {$wait}s — probably delivered. Check the handset: the success "
            . 'callback has not been recorded on this tenant since 18 Jul.');
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
