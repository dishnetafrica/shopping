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
        $hasPin = $incoming && ! empty($incoming['lat']) && ! empty($incoming['lng']);
        if (!$incoming || ($incoming['text'] === '' && !$hasPin)) {
            // Not a customer message. Evolution also sends connection.update here —
            // use it to detect a dropped WhatsApp link and alert the shop owner.
            $this->maybeHandleConnection($request->all());
            $this->maybeHandleSendStatus($request->all());
            return response()->json(['ok' => true]); // status / presence / group / fromMe — not a customer message
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

    /**
     * Evolution emits connection.update {state: open|connecting|close}. We track
     * the last state per tenant and fire ONE alert when a live link drops, and a
     * "back online" note when it recovers. Manual disconnects from the panel are
     * marked 'manual_off' so they never trigger an alert.
     */
    private function maybeHandleConnection(array $payload): void
    {
        $event = strtolower((string) ($payload['event'] ?? data_get($payload, 'data.event', '')));
        if (! str_contains($event, 'connection')) return;

        $instance = (string) ($payload['instance'] ?? data_get($payload, 'data.instance', ''));
        $state    = strtolower((string) (data_get($payload, 'data.state', data_get($payload, 'state', ''))));
        if ($instance === '' || $state === '') return;

        $t = Tenant::where('whatsapp_instance', $instance)->first();
        if (! $t) return;

        $prev = (string) $t->setting('wa_conn_state', '');

        if ($state === 'open') {
            $alerted = (bool) $t->setting('wa_down_alerted', false);
            $t->putSetting('wa_conn_state', 'open');
            if ($alerted) {
                $t->putSetting('wa_down_alerted', false);
                \App\Jobs\WhatsappConnectionAlert::dispatch($t->id, false); // back online
            }
            return;
        }

        if ($state === 'close' || $state === 'closed') {
            if ($prev === 'manual_off') { return; }            // deliberate disconnect — stay quiet
            $t->putSetting('wa_conn_state', 'close');
            if (! $t->setting('wa_down_alerted', false)) {
                $t->putSetting('wa_down_alerted', true);
                \App\Jobs\WhatsappConnectionAlert::dispatch($t->id, true); // went down
            }
            return;
        }

        // connecting / qr / other transient states — record without alerting.
        if ($prev !== 'manual_off') $t->putSetting('wa_conn_state', $state);
    }

    /**
     * Evolution emits messages.update with the delivery status of an OUTBOUND message
     * (SERVER_ACK → DELIVERY_ACK → READ, or ERROR when the send fails). We record this as
     * our own signal — Evolution's findMessages doesn't reliably carry the status — so the
     * panel can show whether the connected number is actually able to deliver. ERROR is the
     * exact failure mode that made a "Connected" number look healthy while every send died.
     */
    private function maybeHandleSendStatus(array $payload): void
    {
        $event = strtolower((string) ($payload['event'] ?? data_get($payload, 'data.event', '')));
        if (! str_contains($event, 'messages.update') && ! str_contains($event, 'messages_update')) return;

        $instance = (string) ($payload['instance'] ?? data_get($payload, 'data.instance', ''));
        if ($instance === '') return;

        $t = Tenant::where('whatsapp_instance', $instance)->first();
        if (! $t) return;

        // data may be a single update object or a list of them
        $data = data_get($payload, 'data', []);
        $updates = (is_array($data) && (isset($data['status']) || isset($data['update']) || isset($data['keyId']) || isset($data['key'])))
            ? [$data] : $data;
        if (! is_array($updates)) return;

        foreach ($updates as $u) {
            if (! is_array($u)) continue;
            $fromMe = data_get($u, 'fromMe', data_get($u, 'key.fromMe'));
            if ($fromMe === false) continue;   // only our outbound messages

            $status = strtoupper((string) (data_get($u, 'status', data_get($u, 'update.status', ''))));
            if ($status === '') continue;

            $to = preg_replace('/@.*/', '', (string) (data_get($u, 'remoteJid', data_get($u, 'key.remoteJid', ''))));
            $id = (string) (data_get($u, 'keyId', data_get($u, 'key.id', ''))) ?: uniqid('s_');

            if ($status === 'ERROR') {
                \App\Support\BotTrace::log($t->id, $id, $to, 'send_failed', 'WhatsApp delivery status = ERROR');
                $t->putSetting('wa_send_err_at', now()->toIso8601String());
            } elseif (in_array($status, ['DELIVERY_ACK', 'READ', 'PLAYED'], true)) {
                // throttle the "delivering ok" stamp so we don't rewrite settings on every ack
                $last = (string) $t->setting('wa_send_ok_at', '');
                if ($last === '' || now()->diffInSeconds(\Illuminate\Support\Carbon::parse($last)) >= 30) {
                    $t->putSetting('wa_send_ok_at', now()->toIso8601String());
                }
            }
        }
    }
}
