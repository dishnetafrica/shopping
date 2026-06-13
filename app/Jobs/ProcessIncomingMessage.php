<?php
namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\BotBrain;
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

    public function handle(TenantContext $ctx, WhatsAppManager $wa, BotBrain $brain): void
    {
        $ctx->set($this->tenantId);                 // scope everything to this tenant
        $tenant = Tenant::findOrFail($this->tenantId);
        $gateway = $wa->driver($tenant->whatsapp_driver ?: $this->driver);

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

        $reply = $brain->respond($tenant, $convo, $this->incoming['text']);
        $convo->last_message_at = now();
        $convo->save();

        if ($reply !== '') {
            $gateway->sendText($tenant->whatsapp_instance, $this->incoming['from'], $reply);
            MessageLog::record(
                $this->tenantId, $this->incoming['from'], $this->incoming['instance'],
                'out', 'bot', $reply
            );
        }
    }
}
