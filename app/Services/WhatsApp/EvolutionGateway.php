<?php
namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Http;

class EvolutionGateway implements WhatsAppGateway
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
    ) {}

    protected function http()
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders(['apikey' => $this->apiKey])
            ->acceptJson()->timeout(20);
    }

    protected function jid(string $to): string
    {
        $to = preg_replace('/[^0-9]/', '', $to);
        return str_contains($to, '@') ? $to : $to.'@s.whatsapp.net';
    }

    public function sendText(string $instance, string $to, string $message, ?array $quoted = null): array
    {
        $payload = [
            'number' => preg_replace('/[^0-9]/', '', $to),
            'text'   => $message,
        ];
        if ($quoted) {
            $payload['quoted'] = $quoted;   // e.g. ['key' => ['id' => '<wa msg id>']]
        }
        return $this->http()->post("/message/sendText/{$instance}", $payload)->json() ?? [];
    }

    public function sendImage(string $instance, string $to, string $media, string $caption = ''): array
    {
        return $this->http()->post("/message/sendMedia/{$instance}", [
            'number'    => preg_replace('/[^0-9]/', '', $to),
            'mediatype' => 'image',
            'media'     => $media,
            'caption'   => $caption,
        ])->json() ?? [];
    }

    public function markRead(string $instance, string $messageId): void
    {
        $this->http()->post("/chat/markMessageAsRead/{$instance}", ['readMessages' => [['id' => $messageId]]]);
    }

    public function fetchContacts(string $instance): array
    {
        $rows = [];
        // Evolution v2: POST /chat/findContacts/{instance} with an empty filter.
        try {
            $resp = $this->http()->post("/chat/findContacts/{$instance}", ['where' => (object) []])->json();
            $rows = $this->normalizeContacts($resp);
        } catch (\Throwable $e) {
            $rows = [];
        }
        // Older builds expose GET /chat/fetchContacts/{instance}.
        if (! $rows) {
            try {
                $resp = $this->http()->get("/chat/fetchContacts/{$instance}")->json();
                $rows = $this->normalizeContacts($resp);
            } catch (\Throwable $e) {
                // leave empty
            }
        }
        return $rows;
    }

    protected function normalizeContacts($resp): array
    {
        if (! is_array($resp)) return [];
        $list = $resp;
        if (isset($resp['contacts']) && is_array($resp['contacts'])) $list = $resp['contacts'];
        elseif (isset($resp['data']) && is_array($resp['data'])) $list = $resp['data'];

        $out = []; $seen = [];
        foreach ($list as $c) {
            if (! is_array($c)) continue;
            $jid = (string) ($c['remoteJid'] ?? $c['id'] ?? $c['jid'] ?? '');
            if ($jid === '' || str_contains($jid, '@g.us') || str_contains($jid, 'broadcast') || str_contains($jid, '@newsletter')) continue;
            $phone = preg_replace('/[^0-9]/', '', explode('@', $jid)[0]);
            if ($phone === '' || strlen($phone) < 7 || isset($seen[$phone])) continue;
            $seen[$phone] = true;
            $name = trim((string) ($c['pushName'] ?? $c['name'] ?? $c['verifiedName'] ?? ''));
            $out[] = ['phone' => $phone, 'name' => $name];
        }
        return $out;
    }

    public function parseIncoming(array $payload): ?array
    {
        // Evolution "messages.upsert" shape (defensive — varies by version).
        $instance = $payload['instance'] ?? data_get($payload, 'data.instance');
        $msg      = data_get($payload, 'data.messages.0') ?? data_get($payload, 'data');
        if (!$msg) return null;

        $remote = data_get($msg, 'key.remoteJid', '');
        $fromMe = (bool) data_get($msg, 'key.fromMe', false);
        // Drop groups, broadcasts and status posts entirely. Keep 1:1 fromMe messages —
        // they let us detect when the shop owner replied by hand (manual takeover).
        if (str_contains((string) $remote, '@g.us')
            || str_contains((string) $remote, '@broadcast')
            || str_contains((string) $remote, 'status@broadcast')) {
            return null;
        }

        $text = data_get($msg, 'message.conversation')
            ?? data_get($msg, 'message.extendedTextMessage.text', '');

        // Status-reply context: when a customer replies to the shop's WhatsApp Status,
        // only the bare reply ("1 Dish") arrives as text — the status itself sits in
        // contextInfo (remoteJid = status@broadcast). Capture both so the bot can make
        // sense of it instead of treating "1 Dish" as a product.
        $ctx = data_get($msg, 'message.extendedTextMessage.contextInfo');
        $isStatusReply = is_array($ctx)
            && str_contains((string) data_get($ctx, 'remoteJid', ''), 'status@broadcast');
        $quotedText = is_array($ctx) ? (string) (
            data_get($ctx, 'quotedMessage.conversation')
            ?? data_get($ctx, 'quotedMessage.extendedTextMessage.text')
            ?? data_get($ctx, 'quotedMessage.imageMessage.caption')
            ?? data_get($ctx, 'quotedMessage.videoMessage.caption')
            ?? ''
        ) : '';

        // Location pin (static or live). Coordinates let the bot build a Google Maps link
        // and snap to a delivery zone; without this the message has no text and is dropped.
        $locMsg = data_get($msg, 'message.locationMessage')
            ?? data_get($msg, 'message.liveLocationMessage');
        $lat = $lng = null; $locName = $locAddr = null;
        if (is_array($locMsg)) {
            $lat = data_get($locMsg, 'degreesLatitude');
            $lng = data_get($locMsg, 'degreesLongitude');
            $locName = data_get($locMsg, 'name');
            $locAddr = data_get($locMsg, 'address');
            if ($lat !== null && $lng !== null && (string) $text === '') {
                $text = trim((string) ($locName ?: $locAddr)) ?: '📍 location';
            }
        }

        return [
            'instance'  => (string) $instance,
            'from'      => preg_replace('/[^0-9]/', '', explode('@', (string) $remote)[0]),
            'text'      => (string) $text,
            'lat'       => $lat !== null ? (float) $lat : null,
            'lng'       => $lng !== null ? (float) $lng : null,
            'loc_name'  => $locName !== null ? (string) $locName : null,
            'loc_address' => $locAddr !== null ? (string) $locAddr : null,
            'messageId' => (string) data_get($msg, 'key.id', ''),
            'from_me'         => $fromMe,
            'is_status_reply' => $isStatusReply,
            'quoted_text'     => $quotedText,
            'raw'       => $payload,
        ];
    }
}
