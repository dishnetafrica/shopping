<?php
namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\BotBrain;
use App\Services\Bot\BotNlu;
use App\Support\BotMiss;
use App\Support\ChatReplayCsv;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * bot:replay — Replay an exported chat CSV through the live bot, offline, and report the
 * failure rate + the still-failing messages. Turns "read misses one screenshot at a time"
 * into a measurable number that should drop after each bot batch.
 *
 * CSV columns (header optional; matched by name, else positional):
 *     timestamp, conversation_id, direction, text
 * Only inbound (customer -> shop) rows are replayed, in file order, grouped per conversation
 * so the state machine (awaiting_confirm / awaiting_location) behaves as it did live.
 *
 * DRY-RUN ENVELOPE (nothing leaks to production):
 *   - Runs inside a DB transaction that is ALWAYS rolled back — any Order / Conversation
 *     rows the bot creates during replay are discarded.
 *   - Queue / Bus / Mail / Notification are faked, so owner-pings and notifications dispatched
 *     during replay are captured, not sent.
 *   - respond() returns the reply string; the WhatsApp send lives in the Job, not here, so no
 *     message is ever delivered.
 *   - Misses are captured in memory (BotMiss::startCapture) instead of being written to the
 *     live bot_misses table.
 *   - The LLM layer is OFF by default (deterministic + free); pass --llm to include it.
 *
 * Examples:
 *   php artisan bot:replay pals.csv --tenant=2
 *   php artisan bot:replay pals.csv --tenant=2 --limit=200 --json=storage/app/replay-pals.json
 *   php artisan bot:replay fs.csv   --tenant=1 --llm --show-passes
 */
class BotReplayCommand extends Command
{
    protected $signature = 'bot:replay
        {file : path to the exported chat CSV (timestamp,conversation_id,direction,text)}
        {--tenant= : tenant id (required)}
        {--llm : also run the LLM layer (default: deterministic only, free)}
        {--limit= : cap the number of inbound messages replayed}
        {--max-fails=60 : how many distinct failing messages to print}
        {--show-passes : also list the messages the bot resolved}
        {--json= : write full per-message results to this path as JSON}';

    protected $description = 'Replay an exported chat CSV through the bot offline and report the failure rate + still-failing messages.';

    public function handle(): int
    {
        $tid = (int) $this->option('tenant');
        if ($tid <= 0) { $this->error('--tenant=<id> is required.'); return self::INVALID; }

        $tenant = Tenant::find($tid);
        if (! $tenant) { $this->error("No tenant #{$tid}."); return self::FAILURE; }

        $path = $this->resolvePath((string) $this->argument('file'));
        if ($path === null) { $this->error('CSV not found: ' . $this->argument('file')); return self::FAILURE; }

        $parsed = ChatReplayCsv::parse((string) file_get_contents($path));
        $groups = ChatReplayCsv::inboundByConversation($parsed);
        $inboundTotal = array_sum(array_map('count', $groups));
        if ($inboundTotal === 0) {
            $this->warn('No inbound (customer) messages found in the CSV. Check the direction column / values.');
            return self::SUCCESS;
        }

        // Activate the tenant so the catalogue + conversation queries scope correctly.
        app(TenantContext::class)->set($tid);

        // Determinism: disable the LLM unless explicitly asked for.
        $useLlm = (bool) $this->option('llm');
        if (! $useLlm) {
            app()->bind(BotNlu::class, fn () => new class extends BotNlu {
                public function enabled(): bool { return false; }
            });
        } elseif (! (config('openai.api_key') ?: env('OPENAI_API_KEY'))) {
            $this->warn('--llm set but no OPENAI_API_KEY — the LLM layer will be a no-op anyway.');
        }
        /** @var BotBrain $brain */
        $brain = app(BotBrain::class);

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $this->line('');
        $this->info("bot:replay — {$tenant->name} (#{$tid})");
        $this->line('  bot version : ' . BotBrain::VERSION);
        $this->line('  file        : ' . $path);
        $this->line('  inbound msgs: ' . $inboundTotal . ' across ' . count($groups) . ' conversations'
            . ($limit ? "  (replaying first {$limit})" : ''));
        $this->line('  llm layer   : ' . ($useLlm ? 'ON' : 'off (deterministic)'));
        $this->line('');

        // Neutralise async side effects, then run inside a transaction we always roll back.
        Queue::fake();
        Bus::fake();
        Mail::fake();
        Notification::fake();

        $results = [];
        $convos  = [];   // cid => Conversation (one per conversation, state carries across turns)
        $done    = 0;

        BotMiss::startCapture();
        DB::beginTransaction();
        try {
            foreach ($groups as $cid => $messages) {
                $convo = $this->freshConversation($tid, (string) $cid);
                foreach ($messages as $m) {
                    if ($limit !== null && $done >= $limit) break 2;
                    $done++;
                    $text = $m['text'];

                    $before = BotMiss::capturedCount();
                    try {
                        $reply = (string) $brain->respond($tenant, $convo, $text);
                        $err = null;
                    } catch (\Throwable $e) {
                        $reply = '';
                        $err = get_class($e) . ': ' . $e->getMessage();
                    }
                    $grew = BotMiss::capturedCount() > $before;

                    if ($err !== null)              { $missed = true;  $reason = 'error'; }
                    elseif ($grew)                  { $missed = true;  $reason = 'no-match'; }
                    elseif (trim($reply) === '')    { $missed = true;  $reason = 'silent'; }
                    else                            { $missed = false; $reason = 'ok'; }

                    $results[] = [
                        'cid'    => (string) $cid,
                        'text'   => $text,
                        'reply'  => mb_substr($reply, 0, 200),
                        'missed' => $missed,
                        'reason' => $reason,
                        'error'  => $err,
                    ];
                }
            }
        } finally {
            DB::rollBack();          // discard every Order / Conversation write the replay made
            BotMiss::stopCapture();  // (capture buffer already read below via $results)
        }

        return $this->report($tenant, $results, $useLlm);
    }

    private function report(Tenant $tenant, array $results, bool $useLlm): int
    {
        $total  = count($results);
        $missed = array_values(array_filter($results, fn ($r) => $r['missed']));
        $passed = $total - count($missed);
        $rate   = $total ? round(count($missed) / $total * 100, 1) : 0.0;

        $byReason = ['no-match' => 0, 'silent' => 0, 'error' => 0];
        foreach ($missed as $r) { $byReason[$r['reason']] = ($byReason[$r['reason']] ?? 0) + 1; }

        $this->line('────────────────────────────────────────────');
        $this->info("  replayed : {$total}");
        $this->info("  resolved : {$passed}");
        $this->error("  missed   : " . count($missed)
            . "  (no-match {$byReason['no-match']}, silent {$byReason['silent']}, error {$byReason['error']})");
        $this->line('  ──');
        $line = "  MISS RATE: {$rate}%";
        $rate >= 20 ? $this->error($line) : ($rate > 0 ? $this->warn($line) : $this->info($line));
        $this->line('────────────────────────────────────────────');
        $this->line('');

        // Ranked failing terms (the vocabulary gaps to promote into aliases), mirroring bot:misses.
        $terms = [];
        foreach (BotMiss::captured() as $c) {
            $terms[$c['term']] = ($terms[$c['term']] ?? 0) + 1;
        }
        arsort($terms);
        if ($terms) {
            $this->line('Top failing terms:');
            $rows = [];
            foreach (array_slice($terms, 0, 30, true) as $term => $count) { $rows[] = [$term, $count]; }
            $this->table(['Term', 'Count'], $rows);
        }

        // The still-failing messages (deduped by text), the core deliverable.
        $seen = [];
        $distinctFails = [];
        foreach ($missed as $r) {
            $key = mb_strtolower(trim($r['text']));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $distinctFails[] = $r;
        }
        $cap = (int) $this->option('max-fails');
        $this->line('');
        $this->line('Still-failing messages (' . count($distinctFails) . ' distinct, showing up to ' . $cap . '):');
        $rows = [];
        foreach (array_slice($distinctFails, 0, $cap) as $r) {
            $rows[] = [$r['reason'], mb_substr(str_replace("\n", ' ⏎ ', $r['text']), 0, 70)];
        }
        $this->table(['Reason', 'Message'], $rows);

        if ($this->option('show-passes')) {
            $this->line('');
            $this->line('Resolved messages:');
            $rows = [];
            foreach (array_filter($results, fn ($r) => ! $r['missed']) as $r) {
                $rows[] = [
                    mb_substr(str_replace("\n", ' ⏎ ', $r['text']), 0, 40),
                    mb_substr(str_replace("\n", ' ⏎ ', $r['reply']), 0, 50),
                ];
            }
            $this->table(['Message', 'Reply (head)'], $rows);
        }

        if ($json = $this->option('json')) {
            $payload = [
                'tenant'     => ['id' => $tenant->id, 'name' => $tenant->name],
                'version'    => BotBrain::VERSION,
                'llm'        => $useLlm,
                'total'      => $total,
                'resolved'   => $passed,
                'missed'     => count($missed),
                'miss_rate'  => $rate,
                'by_reason'  => $byReason,
                'results'    => $results,
            ];
            $out = $this->resolveWritePath((string) $json);
            file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('');
            $this->info('Wrote full results -> ' . $out);
        }

        return self::SUCCESS;
    }

    /** A throwaway conversation, prefixed so it can never collide with a real customer row. */
    private function freshConversation(int $tid, string $cid): Conversation
    {
        $convo = new Conversation();
        $convo->tenant_id      = $tid;
        $convo->customer_phone = 'replay:' . $cid;
        $convo->instance       = 'replay';
        $convo->state          = [];
        $convo->cart           = [];
        $convo->save();   // inside the rolled-back transaction
        return $convo;
    }

    private function resolvePath(string $file): ?string
    {
        foreach ([$file, base_path($file), storage_path('app/' . $file), storage_path($file)] as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }

    private function resolveWritePath(string $file): string
    {
        // Absolute or already-qualified paths pass through; bare names land in storage/app.
        if (str_starts_with($file, '/') || str_contains($file, '/')) return $file;
        return storage_path('app/' . $file);
    }
}
