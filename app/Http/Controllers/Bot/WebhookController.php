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
        // Meta Cloud API verification handshake (GET): echo hub.challenge if the
        // verify token matches the platform's. Tenants paste this token into Meta.
        if ($request->isMethod('get') && $request->has('hub_challenge')) {
            $ok = $request->query('hub_mode') === 'subscribe'
                && hash_equals((string) config('whatsapp.cloud_verify_token'), (string) $request->query('hub_verify_token'));
            return $ok
                ? response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain')
                : response('forbidden', 403);
        }

        $incoming = $this->wa->driver($driver)->parseIncoming($request->all());
        if (!$incoming || $incoming['text'] === '') {
            \App\Support\BotTrace::log(null, uniqid('m_'), null, 'ignored', 'empty / status / group / fromMe');
            return response()->json(['ok' => true]); // ack & ignore (status events, media-only, etc.)
        }

        $trace = $incoming['messageId'] ?: uniqid('m_');
        $incoming['trace'] = $trace;

        // Resolve tenant by the instance/number that RECEIVED the message.
        $tenant = Tenant::where('whatsapp_instance', $incoming['instance'])->first();
        if (!$tenant) {
            \App\Support\BotTrace::log(null, $trace, $incoming['from'], 'no_tenant', 'instance=' . $incoming['instance']);
            return response()->json(['ok' => true, 'note' => 'no tenant for instance']);
        }

        \App\Support\BotTrace::log($tenant->id, $trace, $incoming['from'], 'queued');
        $incoming['t_recv'] = microtime(true);   // for end-to-end latency logging
        ProcessIncomingMessage::dispatch($tenant->id, $driver, $incoming);
        return response()->json(['ok' => true]);
    }
}
