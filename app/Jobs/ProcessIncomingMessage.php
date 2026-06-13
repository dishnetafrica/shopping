<?php
namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\BotBrain;
use App\Services\Bot\MarketingBrain;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public string $driver,
        public array $incoming,
    ) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa, BotBrain $brain, MarketingBrain $marketing): void
    {
        $ctx->set($this->tenantId);                 // scope everything to this tenant
        $tenant = Tenant::findOrFail($this->tenantId);
        $gateway = $wa->forTenant($tenant);

        $convo = Conversation::firstOrCreate(
            ['customer_phone' => $this->incoming['from'], 'instance' => $this->incoming['instance']],
            ['state' => [], 'cart' => []]
        );

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
        $botMode = (string) $tenant->setting('bot_mode', 'auto');
        $convo->refresh();
        if ($convo->agent_active || $botMode !== 'auto') {
            return;
        }

        $text  = (string) $this->incoming['text'];
        $state = is_array($convo->state) ? $convo->state : [];
        $now   = time();

        // ---- Loop guard: stop runaway bot-to-bot / echo conversations ----
        // 1) Echo: the incoming message is identical to what we just sent.
        if (! empty($state['lg_last_out']) && $this->norm($text) !== '' && $this->norm($text) === $state['lg_last_out']) {
            return;
        }
        // 2) Debounce: we already replied to this chat under 2s ago.
        if (! empty($state['lg_last_out_at']) && ($now - (int) $state['lg_last_out_at']) < 2) {
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
            return;
        }

        $reply = $tenant->isMarketing()
            ? $marketing->respond($tenant, $convo, $text)
            : $brain->respond($tenant, $convo, $text);
        $convo->last_message_at = now();

        if ($reply !== '') {
            $gateway->sendText($tenant->whatsapp_instance, $this->incoming['from'], $reply);
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
            $convo->state = $st;
        }
        $convo->save();
    }

    /** Normalise text for echo comparison. */
    private function norm(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }
}
