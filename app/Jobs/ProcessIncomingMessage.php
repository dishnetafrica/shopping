<?php
namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\BotBrain;
use App\Services\Bot\MarketingBrain;
use App\Services\Bot\VisionProductFinder;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\BotTrace;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Generous attempts: WithoutOverlapping releases (not fails) while a sibling runs. */
    public int $tries = 25;
    public int $backoff = 3;

    public function __construct(
        public int $tenantId,
        public string $driver,
        public array $incoming,
    ) {}

    /**
     * 2B — Conversation serialization. Only one message per conversation is
     * processed at a time; others release and retry, preserving order.
     */
    public function middleware(): array
    {
        $key = \App\Support\Idempotency::conversationLock(
            $this->tenantId,
            (string) ($this->incoming['instance'] ?? ''),
            (string) ($this->incoming['from'] ?? ''),
        );

        return [(new WithoutOverlapping($key))->releaseAfter(3)->expireAfter(30)];
    }

    public function handle(TenantContext $ctx, WhatsAppManager $wa, BotBrain $brain, MarketingBrain $marketing, VisionProductFinder $finder): void
    {
        $ctx->set($this->tenantId);                 // scope everything to this tenant
        $tStart = microtime(true);                  // when this job started processing
        $tenant = Tenant::findOrFail($this->tenantId);
        $gateway = $wa->forTenant($tenant);

        $convo = Conversation::firstOrCreate(
            ['customer_phone' => $this->incoming['from'], 'instance' => $this->incoming['instance']],
            ['state' => [], 'cart' => []]
        );

        // 2A — Message idempotency. Claim this WhatsApp message id exactly once.
        // A duplicate delivery / queue retry that reaches here is dropped before it
        // can log, reply, or touch the cart. (Concurrency is already prevented by
        // the per-conversation lock in middleware(), so claiming here is safe.)
        $mid = (string) ($this->incoming['messageId'] ?? '');
        if ($mid !== '' && ! \App\Models\MessageReceipt::claim($this->tenantId, $convo->id, $mid)) {
            BotTrace::log($this->tenantId, (string) $mid, (string) $this->incoming['from'], 'skipped', 'duplicate message id');
            return;
        }

        // Owner replied by hand (fromMe). If it's just the bot's own message echoing back,
        // ignore it. If it's something the owner typed, treat it as a manual takeover and
        // soft-pause auto-replies for this chat for a while, so the bot doesn't talk over a
        // live human conversation ("Good morning, I'll reach now" → "Ok let me come").
        if (! empty($this->incoming['from_me'])) {
            $txt   = trim((string) $this->incoming['text']);
            $state = is_array($convo->state) ? $convo->state : [];
            if ($txt !== '' && $this->norm($txt) !== ($state['lg_last_out'] ?? '')) {
                $mins = (int) $tenant->setting('human_takeover_minutes', 60);
                if ($mins > 0) {
                    $state['human_until'] = time() + $mins * 60;
                    $convo->state = $state;
                }
                $convo->last_message_at = now();
                $convo->save();
                MessageLog::record($this->tenantId, $this->incoming['from'], $this->incoming['instance'], 'out', 'human', $txt);
                BotTrace::log($this->tenantId, (string) ($this->incoming['trace'] ?? $mid), (string) $this->incoming['from'],
                    'manual_takeover', 'owner replied by hand — bot paused ' . ((int) $tenant->setting('human_takeover_minutes', 60)) . 'm');
            }
            return; // never auto-reply to our own number
        }

        // Always log what the customer said — even if the bot is off or a human
        // has taken over. This is what powers the live web inbox.
        MessageLog::record(
            $this->tenantId, $this->incoming['from'], $this->incoming['instance'],
            'in', 'customer', $this->incoming['text'], $this->incoming['messageId'] ?: null
        );

        if ($this->incoming['messageId']) {
            $gateway->markRead($tenant->whatsapp_instance, $this->incoming['messageId']);
        }

        // Should the bot answer automatically?
        //  - 'auto'  : bot replies (default)
        //  - anything else (e.g. 'off'/'monitor') : log only, a human handles it
        //  - agent_active : a staff member has taken this chat over from the web
        $trace = (string) ($this->incoming['trace'] ?? ($this->incoming['messageId'] ?: 'm_?'));
        $from  = (string) $this->incoming['from'];
        BotTrace::log($this->tenantId, $trace, $from, 'started');

        $botMode = (string) $tenant->setting('bot_mode', 'auto');
        $convo->refresh();
        if ($convo->agent_active) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'a person is handling this chat (Take over)');
            return;
        }
        if ((int) (is_array($convo->state) ? ($convo->state['human_until'] ?? 0) : 0) > time()) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'owner is handling this chat (recent manual reply)');
            return;
        }
        if ($botMode !== 'auto') {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'bot is switched off');
            return;
        }
        $muted = (array) $tenant->setting('bot_muted', []);
        $mutedDigits = array_map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p), $muted);
        if (in_array($from, $mutedDigits, true)) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'number is muted (no auto-reply)');
            return;
        }

        $text  = (string) $this->incoming['text'];
        $state = is_array($convo->state) ? $convo->state : [];
        $now   = time();

        // Status reply: the customer replied to our WhatsApp Status, so the bare text
        // ("1 Dish") is meaningless on its own. Rebuild it with the status caption (e.g.
        // "Today special lunch menu") so the bot routes it to the menu instead of doing
        // a product search and saying "we don't stock 1 dish". No caption → show the menu.
        if (! empty($this->incoming['is_status_reply'])) {
            $q = trim((string) ($this->incoming['quoted_text'] ?? ''));
            $text = $q !== '' ? trim($q . ' ' . $text) : 'menu';
            BotTrace::log($this->tenantId, $trace, $from, 'status_reply', 'reading as: ' . $text);
        }

        // ---- Loop guard: stop runaway bot-to-bot / echo conversations ----
        // 1) Echo: the incoming message is identical to what we just sent.
        if (! empty($state['lg_last_out']) && $this->norm($text) !== '' && $this->norm($text) === $state['lg_last_out']) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'echo of our own last message');
            return;
        }
        // 2) Debounce: we already replied to this chat under 2s ago.
        if (! empty($state['lg_last_out_at']) && ($now - (int) $state['lg_last_out_at']) < 2) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'debounced (replied <2s ago)');
            return;
        }
        // 3) Rate breaker: too many auto-replies in a short window = likely a loop.
        $outTimes = array_values(array_filter(
            is_array($state['lg_out_times'] ?? null) ? $state['lg_out_times'] : [],
            fn ($t) => ($now - (int) $t) <= 600
        ));
        $in45 = count(array_filter($outTimes, fn ($t) => ($now - (int) $t) <= 45));
        if ($in45 >= 5 || count($outTimes) >= 12) {
            $convo->agent_active   = true;        // pause auto-reply on this chat
            $state['lg_paused']    = true;
            $state['lg_out_times'] = $outTimes;
            $alerted               = ! empty($state['lg_alerted']);
            $state['lg_alerted']   = true;
            $convo->state = $state;
            $convo->save();
            if (! $alerted) {
                NotifyOwner::dispatch($this->tenantId,
                    "⚠️ Possible message loop with +{$this->incoming['from']} — auto-reply paused for this chat. Open Chats to take over or switch the bot back on.");
            }
            BotTrace::log($this->tenantId, $trace, $from, 'paused', "loop guard: {$in45} replies in 45s");
            Log::warning('bot.loop_paused', [
                'tenant' => $this->tenantId,
                'from'   => $this->incoming['from'],
                'in45s'  => $in45,
                'in600s' => count($outTimes),
            ]);
            return;
        }

        $tBrain = microtime(true);

        // ---- Image search: customer sent a product photo instead of typing ----
        // Turn the photo into a catalogue query with vision, then let the normal
        // product search + numbered pick-list + cart flow handle it. If we can't
        // identify it, ask for the name rather than guessing.
        $fromImage = false;
        if (! empty($this->incoming['has_image'] ?? false) && trim($text) === '' && ! $tenant->isMarketing()) {
            $featureOn = (bool) $tenant->setting('feature_image_search', true);
            if (! $featureOn || ! $finder->enabled()) {
                $ask = "📷 Please type the product name (e.g. \"rice 5kg\") and I'll find it for you.";
                $gateway->sendText($tenant->whatsapp_instance, $from, $ask);
                MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $ask);
                BotTrace::log($this->tenantId, $trace, $from, 'image_skipped', $featureOn ? 'no AI key' : 'feature off');
                $convo->save();
                return;
            }

            $b64 = (string) ($this->incoming['image_b64'] ?? '');
            if ($b64 === '' && ! empty($this->incoming['media_key'] ?? null) && method_exists($gateway, 'getMediaBase64')) {
                $b64 = (string) ($gateway->getMediaBase64($tenant->whatsapp_instance, $this->incoming['media_key']) ?? '');
            }

            $query = $b64 !== '' ? $finder->identify($b64, (string) ($this->incoming['image_caption'] ?? '')) : null;
            if ($query === null || $query === '') {
                $miss = "📷 I couldn't make out the product in that photo. Please type the name (e.g. \"sugar 2kg\") and I'll find it.";
                $gateway->sendText($tenant->whatsapp_instance, $from, $miss);
                MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $miss);
                BotTrace::log($this->tenantId, $trace, $from, 'image_unidentified', 'vision returned nothing');
                $convo->save();
                return;
            }

            $text = $query;
            $fromImage = true;
            BotTrace::log($this->tenantId, $trace, $from, 'image_query', $query);
        }

        try {
            $hasPin = isset($this->incoming['lat'], $this->incoming['lng'])
                && $this->incoming['lat'] !== null && $this->incoming['lng'] !== null;
            if ($hasPin && ! $tenant->isMarketing()) {
                $reply = $brain->handleLocationPin(
                    $tenant, $convo,
                    (float) $this->incoming['lat'], (float) $this->incoming['lng'],
                    $this->incoming['loc_name'] ?? null, $this->incoming['loc_address'] ?? null
                );
            } else {
                $reply = $tenant->isMarketing()
                    ? $marketing->respond($tenant, $convo, $text)
                    : $brain->respond($tenant, $convo, $text);
            }
        } catch (\Throwable $e) {
            BotTrace::log($this->tenantId, $trace, $from, 'error', 'brain: ' . $e->getMessage());
            return;
        }
        $brainMs = (microtime(true) - $tBrain) * 1000;
        $convo->last_message_at = now();

        // Let the customer know the reply came from their photo.
        if ($fromImage && $reply !== '') {
            $reply = "📸 From your photo, here's what I found:\n\n".$reply;
        }

        if ($reply === '') {
            BotTrace::log($this->tenantId, $trace, $from, 'empty', 'bot produced no reply');
            $convo->save();
            return;
        }

        // ---- Repeat guard: never send the same message twice in a row. ----
        // If the brain has nothing new to say (e.g. it already escalated "the shop
        // will reply"), staying silent is better than repeating + re-spamming the
        // website link. After a couple of repeats, hand the chat to a human so the
        // customer isn't left hanging.
        if (! empty($state['lg_last_out']) && $this->norm($reply) === $state['lg_last_out']) {
            $st = is_array($convo->state) ? $convo->state : [];
            $rep = (int) ($st['lg_repeat'] ?? 0) + 1;
            $st['lg_repeat'] = $rep;
            if ($rep >= 2) {
                $convo->agent_active = true;                 // hand to a human
                $alerted = ! empty($st['lg_alerted']);
                $st['lg_alerted'] = true;
                $convo->state = $st;
                $convo->save();
                if (! $alerted) {
                    NotifyOwner::dispatch($this->tenantId,
                        "💬 +{$this->incoming['from']} keeps asking something the bot can't answer — auto-reply is paused for this chat. Open Chats to reply.");
                }
                BotTrace::log($this->tenantId, $trace, $from, 'paused', 'repeated reply ×' . $rep . ' — handed to human');
            } else {
                $convo->state = $st;
                $convo->save();
                BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'suppressed duplicate reply');
            }
            return;
        }

        $tSend = microtime(true);
        try {
            $gateway->sendText($tenant->whatsapp_instance, $this->incoming['from'], $reply);
        } catch (\Throwable $e) {
            BotTrace::log($this->tenantId, $trace, $from, 'error', 'send: ' . $e->getMessage());
            $convo->save();
            return;
        }
        $sendMs = (microtime(true) - $tSend) * 1000;
        MessageLog::record(
            $this->tenantId, $this->incoming['from'], $this->incoming['instance'],
            'out', 'bot', $reply
        );
        // Record this auto-reply for the loop guard (re-read state: the brain may have changed it).
        $st = is_array($convo->state) ? $convo->state : [];
        $outTimes[] = $now;
        $st['lg_out_times']   = array_values(array_filter($outTimes, fn ($t) => ($now - (int) $t) <= 600));
        $st['lg_last_out']    = $this->norm($reply);
        $st['lg_last_out_at'] = $now;
        $st['lg_repeat']      = 0;
        $convo->state = $st;

        // Timing: webhook-received → reply-sent (queue wait + AI + send).
        $recv    = (float) ($this->incoming['t_recv'] ?? $tStart);
        $totalMs = (int) round((microtime(true) - $recv) * 1000);
        Log::info('bot.latency', [
            'tenant'   => $this->tenantId,
            'from'     => $this->incoming['from'],
            'mode'     => $tenant->isMarketing() ? 'marketing' : 'shop',
            'queue_ms' => (int) round(($tStart - $recv) * 1000),
            'brain_ms' => (int) round($brainMs),
            'send_ms'  => (int) round($sendMs),
            'total_ms' => $totalMs,
        ]);
        BotTrace::log($this->tenantId, $trace, $from, 'replied', mb_substr($reply, 0, 80), $totalMs);
        $convo->save();
    }

    /** Normalise text for echo comparison. */
    private function norm(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }
}
