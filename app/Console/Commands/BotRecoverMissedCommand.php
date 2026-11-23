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
 * Also a message that came in while the bot was paused because someone had just replied from the phone, once
 * that pause ends and nobody has replied (MissedReply::HELD).
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
            $ctx->set($t->id);
            $this->recoverTenant($t, $wa, $evo, $dry);
            $ctx->reset();
        }

        return self::SUCCESS;
    }

    private function recoverTenant(Tenant $t, WhatsAppManager $wa, EvolutionAdmin $evo, bool $dry): void
    {
        $evolution = ($t->whatsapp_driver ?: 'evolution') === 'evolution';
        $states = [];                                           // instance => state, asked once per run
        $cfg = [
            'wait_min' => (int) $t->setting('bot_recover_wait_min', MissedReply::DEFAULTS['wait_min']),
            'window_min' => (int) $t->setting('bot_recover_window_min', MissedReply::DEFAULTS['window_min']),
            'max_tries' => (int) $t->setting('bot_recover_max_tries', MissedReply::DEFAULTS['max_tries']),
        ];
        $muted = array_map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p), (array) $t->setting('bot_muted', []));
        $now = time();

        $chats = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            // Unread, or paused for someone on the phone — opening a chat in the panel clears "unread", so a
            // paused chat someone only looked at still counts. Either way: nothing sent since their message.
            // Only chats whose latest message was really HELD (Codex on #177: a stale pause must not fill the batch).
            ->where(fn ($q) => $q->where('unread', '>', 0)->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('bot_events')
                ->whereColumn('bot_events.tenant_id', 'conversations.tenant_id')
                ->whereColumn('bot_events.phone', 'conversations.customer_phone')
                ->where('bot_events.stage', 'skipped')->where('bot_events.detail', MissedReply::HELD)
                ->whereColumn('bot_events.created_at', '>=', 'conversations.last_inbound_at')))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('messages')
                ->whereColumn('messages.tenant_id', 'conversations.tenant_id')
                ->whereColumn('messages.customer_phone', 'conversations.customer_phone')
                ->whereColumn('messages.instance', 'conversations.instance')
                ->where('messages.direction', 'out')
                ->whereColumn('messages.created_at', '>=', 'conversations.last_inbound_at'))
            ->where('agent_active', false)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '>=', now()->subMinutes(max($cfg['window_min'], $cfg['wait_min'] + 1)))
            ->where('last_inbound_at', '<=', now()->subMinutes(max(2, $cfg['wait_min'])))
            ->orderBy('last_inbound_at')
            ->limit(20)
            ->get();

        foreach ($chats as $c) {
            $from = preg_replace('/[^0-9]/', '', (string) $c->customer_phone);
            // The customer's latest words in THIS chat (same number they wrote to — a customer may write to two).
            $lastIn = DB::table('messages')->where('tenant_id', $t->id)->where('customer_phone', $c->customer_phone)
                ->where('instance', $c->instance)->where('direction', 'in')->where('sender', 'customer')->orderByDesc('id')
                ->first(['body', 'wa_message_id']);
            $text = (string) ($lastIn->body ?? '');
            $waId = (string) ($lastIn->wa_message_id ?? '');
            $st = is_array($c->state) ? $c->state : [];
            $mr = is_array($st['missed_reply'] ?? null) ? $st['missed_reply'] : [];
            $in = $c->last_inbound_at ? $c->last_inbound_at->getTimestamp() : 0;
            // The bot's steps for THAT message (its WhatsApp id is the step log's trace); without an id, this
            // phone's steps since the message — the step log does not record which number it came in on.
            $eq = DB::table('bot_events')->where('tenant_id', $t->id);
            $waId !== '' ? $eq->where('trace', $waId) : $eq->where('phone', $from)->where('created_at', '>=', $c->last_inbound_at);
            $rows = $eq->orderBy('id')->limit(200)->get(['stage', 'detail', 'created_at']);
            $events = $rows->map(fn ($e) => ['stage' => (string) $e->stage, 'detail' => $e->detail])->all();
            // When the message ARRIVED (the webhook's "queued" step): a reply typed on the phone while the
            // message waited to be processed is logged before it, yet answers it (Codex on #177).
            $queued = $rows->firstWhere('stage', 'queued')?->created_at;
            $arrived = $queued !== null && $c->last_inbound_at !== null
                ? min(\Illuminate\Support\Carbon::parse($queued), $c->last_inbound_at) : $c->last_inbound_at;

            // Paused for someone on the phone (HELD): "unread" may be cleared by just opening the chat; the
            // query already checked that nothing was sent since their message.
            $held = MissedReply::held($events);
            if ($held && $this->answeredSince($t, $c, $arrived)) {
                $this->line("  {$from}: skip — answered");
                continue;
            }
            $why = MissedReply::skipReason([
                'unread' => $held ? 1 : (int) $c->unread, 'agent_active' => (bool) $c->agent_active, 'last_inbound_at' => $in,
                'human_until' => (int) ($st['human_until'] ?? 0), 'muted' => in_array($from, $muted, true), 'text' => $text,
                'done_for' => ! empty($mr['done']) ? (int) ($mr['inbound'] ?? 0) : 0,
                'tries_for' => (int) ($mr['inbound'] ?? 0), 'tries' => (int) ($mr['tries'] ?? 0),
                'bot_finished' => MissedReply::botFinished($events),
            ], $now, $cfg);
            if ($why !== null) {
                $this->line("  {$from}: skip — {$why}");
                continue;
            }
            // Reply from the same number a normal reply would use (the one they wrote to, or the connected
            // fallback while the primary is down) — and only once that number is connected again.
            $replyInst = ProcessIncomingMessage::replyInstance($t, (string) $c->instance);
            if ($evolution) {
                $states[$replyInst] ??= ($replyInst !== '' && $evo->configured()) ? $evo->state($replyInst) : 'missing';
                if ($states[$replyInst] !== 'open') {
                    $this->line("  {$from}: wait — WhatsApp {$replyInst} is {$states[$replyInst]}");
                    continue;
                }
            }
            if ($dry) {
                $this->line("  {$from}: WOULD reply late from {$replyInst} to \"" . mb_substr($text, 0, 60) . '"');
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
                if ($c->agent_active || ($held ? $this->answeredSince($t, $c, $arrived) : (int) $c->unread <= 0)) {
                    continue;                                   // answered while we were looking
                }
                $trace = $waId !== '' ? $waId : 'recover';
                BotTrace::log($t->id, $trace, $from, 'missed_reply_try', mb_substr($text, 0, 120));
                $sendAs = clone $t;                                 // in memory only, never saved — as the message job does
                $sendAs->whatsapp_instance = $replyInst;
                app(AiBrain::class)->handle($sendAs, $c, $from, $text, $wa->forTenant($sendAs), ['late' => true]);
                $this->mark($c, $in, (int) ($mr['inbound'] ?? 0) === $in ? (int) ($mr['tries'] ?? 0) + 1 : 1, true);
                BotTrace::log($t->id, $trace, $from, 'missed_replied', null);
                $this->info("  {$from}: replied late");
            } catch (\Throwable $e) {
                $this->mark($c, $in, (int) ($mr['inbound'] ?? 0) === $in ? (int) ($mr['tries'] ?? 0) + 1 : 1, false);
                BotTrace::log($t->id, $waId !== '' ? $waId : 'recover', $from, 'missed_reply_failed', mb_substr($e->getMessage(), 0, 200));
                $this->warn("  {$from}: failed — " . mb_substr($e->getMessage(), 0, 120));
            } finally {
                $lock->release();
            }
        }
    }

    /** Has anything been sent to this chat since the customer's last message arrived (bot, staff, phone)? */
    private function answeredSince(Tenant $t, Conversation $c, ?\DateTimeInterface $since): bool
    {
        return $since !== null && DB::table('messages')->where('tenant_id', $t->id)->where('customer_phone', $c->customer_phone)
            ->where('instance', $c->instance)->where('direction', 'out')->where('created_at', '>=', $since)->exists();
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
