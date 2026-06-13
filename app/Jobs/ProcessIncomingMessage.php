<?php
namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\BotBrain;
use App\Services\WhatsApp\WhatsAppManager;
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

        if ($this->incoming['messageId']) {
            $gateway->markRead($tenant->whatsapp_instance, $this->incoming['messageId']);
        }

        // The "brain" turns the message + cart into a reply (OpenAI + catalogue).
        $reply = $brain->respond($tenant, $convo, $this->incoming['text']);

        $convo->last_message_at = now();
        $convo->save();

        if ($reply !== '') {
            $gateway->sendText($tenant->whatsapp_instance, $this->incoming['from'], $reply);
        }
    }
}
