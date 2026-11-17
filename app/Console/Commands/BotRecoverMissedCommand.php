<?php

namespace App\Console\Commands;

use App\Jobs\ProcessIncomingMessage;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\AiBrain;
use App\Services\Bot\MissedReply;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\BotTrace;
use App\Support\Idempotency;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Missed-reply recovery. When WhatsApp drops while the bot answers, the send fails and the customer
 * gets nothing (the retry is dropped on purpose: one message, one reply). This finds those chats once
 * the line is back and answers the customer's latest message, opening with a short apology.
 *
 * Opt-in per business (setting `bot_recover_missed`, default off) and only for the AI bot. The rules
 * (who, when, how many tries) are MissedReply::skipReason. It takes the same per-conversation lock as
 * the message job, so a late reply never runs alongside a normal one.
 */
class BotRecoverMissedCommand extends Command
{
    protected $signature = 'bot:recover-missed {--tenant= : only this business id} {--dry-run : list who would get a late reply, send nothing}';

    protected $description = 'Reply to customers whose bot reply never got through (e.g. WhatsApp was disconnected).';

    public function handle(WhatsAppManager $wa, EvolutionAdmin $evo, TenantContext $ctx): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = Tenant::query();
        if ($this->option('tenant')) {
            $q->whereKey((int) $this->option('tenant'));
        }

        foreach ($q->get() as $t) {
            if (! (bool) $t->setting('bot_recover_missed', false) || (string) $t->setting('bot_mode', 'ai') !== 'ai') {
                continue;
            }
            $instance = (string) $t->whatsapp_instance;
            if (($t->whatsapp_driver ?: 'evolution') === 'evolution') {
                $state = ($instance !== '' && $evo->configured()) ? $evo->state($instance) : 'missing';
                if ($state !== 'open') {
                    $this->line("tenant {$t->id}: WhatsApp is {$state} — will try again next run");
                    continue;
                }
            }
            $ctx->set($t->id);
            $this->recoverTenant($t, $wa, $dry);
            $ctx->reset();
        }

        return self::SUCCESS;
    }

    private function recoverTenant(Tenant $t, WhatsAppManager $wa, bool $dry): void
    {
        $cfg = [
            'wait_min' => (int) $t->setting('bot_recover_wait_min', MissedReply::DEFAULTS['wait_min']),
            'window_min' => (int) $t->setting('bot_recover_window_min', MissedReply::DEFAULTS['window_min']),
            'max_tries' => (int) $t->setting('bot_recover_max_tries', MissedReply::DEFAULTS['max_tries']),
        ];
        $muted = array_map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p), (array) $t->setting('bot_muted', []));
        $now = time();

        $chats = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->where('unread', '>', 0)
            ->where('agent_active', false)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '>=', now()->subMinutes(max($cfg['window_min'], $cfg['wait_min'] + 1)))
            ->where('last_inbound_at', '<=', now()->subMinutes(max(2, $cfg['wait_min'])))
            ->orderBy('last_inbound_at')
            ->limit(20)
            ->get();

        foreach ($chats as $c) {
            $from = preg_replace('/[^0-9]/', '', (string) $c->customer_phone);
            $text = (string) DB::table('messages')->where('tenant_id', $t->id)->where('customer_phone', $c->customer_phone)
                ->where('direction', 'in')->where('sender', 'customer')->orderByDesc('id')->value('body');
            $st = is_array($c->state) ? $c->state : [];
            $mr = is_array($st['missed_reply'] ?? null) ? $st['missed_reply'] : [];
            $in = $c->last_inbound_at ? $c->last_inbound_at->getTimestamp() : 0;
            $events = DB::table('bot_events')->where('tenant_id', $t->id)->where('phone', $from)
                ->where('created_at', '>=', $c->last_inbound_at)->orderBy('id')->limit(200)->get(['stage', 'detail'])
                ->map(fn ($e) => ['stage' => (string) $e->stage, 'detail' => $e->detail])->all();

            $why = MissedReply::skipReason([
                'unread' => (int) $c->unread, 'agent_active' => (bool) $c->agent_active, 'last_inbound_at' => $in,
                'human_until' => (int) ($st['human_until'] ?? 0), 'muted' => in_array($from, $muted, true), 'text' => $text,
                'done_for' => ! empty($mr['done']) ? (int) ($mr['inbound'] ?? 0) : 0,
                'tries_for' => (int) ($mr['inbound'] ?? 0), 'tries' => (int) ($mr['tries'] ?? 0),
                'bot_finished' => MissedReply::botFinished($events),
            ], $now, $cfg);
            if ($why !== null) {
                $this->line("  {$from}: skip — {$why}");
                continue;
            }
            if ($dry) {
                $this->line("  {$from}: WOULD reply late to \"" . mb_substr($text, 0, 60) . '"');
                continue;
            }

            // Same lock as ProcessIncomingMessage's WithoutOverlapping middleware for this conversation.
            $lock = Cache::lock('laravel-queue-overlap:' . ProcessIncomingMessage::class . ':'
                . Idempotency::conversationLock($t->id, (string) $c->instance, $from), 120);
            if (! $lock->get()) {
                $this->line("  {$from}: skip — a message for this chat is being handled now");
                continue;
            }
            try {
                $c->refresh();
                if ((int) $c->unread <= 0 || $c->agent_active) {
                    continue;                                   // answered while we were looking
                }
                BotTrace::log($t->id, 'recover', $from, 'missed_reply_try', mb_substr($text, 0, 120));
                app(AiBrain::class)->handle($t, $c, $from, $text, $wa->forTenant($t), ['late' => true]);
                $this->mark($c, $in, (int) ($mr['inbound'] ?? 0) === $in ? (int) ($mr['tries'] ?? 0) + 1 : 1, true);
                BotTrace::log($t->id, 'recover', $from, 'missed_replied', null);
                $this->info("  {$from}: replied late");
            } catch (\Throwable $e) {
                $this->mark($c, $in, (int) ($mr['inbound'] ?? 0) === $in ? (int) ($mr['tries'] ?? 0) + 1 : 1, false);
                BotTrace::log($t->id, 'recover', $from, 'missed_reply_failed', mb_substr($e->getMessage(), 0, 200));
                $this->warn("  {$from}: failed — " . mb_substr($e->getMessage(), 0, 120));
            } finally {
                $lock->release();
            }
        }
    }

    /** Remember the attempt on the chat itself (fresh read, so the AI's own state changes are kept). */
    private function mark(Conversation $c, int $inbound, int $tries, bool $done): void
    {
        $fresh = Conversation::withoutGlobalScopes()->find($c->id);
        if (! $fresh) {
            return;
        }
        $st = is_array($fresh->state) ? $fresh->state : [];
        $st['missed_reply'] = ['inbound' => $inbound, 'tries' => $tries, 'done' => $done, 'at' => time()];
        $fresh->state = $st;
        $fresh->save();
    }
}
