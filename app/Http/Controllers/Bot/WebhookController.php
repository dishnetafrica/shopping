<?php
namespace App\Http\Controllers\Bot;

use App\Jobs\ProcessIncomingMessage;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\Request;

class WebhookController
{
    public function __construct(protected WhatsAppManager $wa) {}

    /** One webhook for all tenants. /api/webhook/whatsapp/{driver} */
    public function handle(Request $request, string $driver = 'evolution')
    {
        $incoming = $this->wa->driver($driver)->parseIncoming($request->all());
        if (!$incoming || $incoming['text'] === '') {
            return response()->json(['ok' => true]); // ack & ignore (status events, media-only, etc.)
        }

        // Resolve tenant by the instance/number that RECEIVED the message.
        $tenant = Tenant::where('whatsapp_instance', $incoming['instance'])->first();
        if (!$tenant) {
            return response()->json(['ok' => true, 'note' => 'no tenant for instance']);
        }

        ProcessIncomingMessage::dispatch($tenant->id, $driver, $incoming);
        return response()->json(['ok' => true]);
    }
}
