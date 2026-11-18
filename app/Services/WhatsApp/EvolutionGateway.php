<?php
namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /**
     * POST a send to Evolution, addressed so WhatsApp accepts it. A customer whose number WhatsApp hides is
     * known to us only by a "hidden id" (LID) — the digits we key the chat by. Sent as plain digits,
     * Evolution reads them as a phone number, finds none and refuses (HTTP 400, exists:false): the bot's
     * replies to such a customer never arrived (DishNet, 18 Nov 2026 — "Jel Kuot": 6 refused replies).
     * The same digits addressed as "<digits>@lid" are accepted. So: on that refusal, send once more as
     * "@lid", and remember it for this chat so later sends go there first (and back to plain digits if
     * the remembered form is ever refused). Any other failure is returned as it is.
     */
    protected function deliver(string $path, string $to, array $fields): \Illuminate\Http\Client\Response
    {
        if (str_ends_with($to, '@lid')) {                 // already addressed as a hidden id
            return $this->http()->post($path, ['number' => $to] + $fields);
        }
        $digits = preg_replace('/[^0-9]/', '', $to) ?? '';
        $key = 'wa_lid:' . $digits;
        $lidFirst = $digits !== '' && \Illuminate\Support\Facades\Cache::has($key);
        $first = $lidFirst ? $digits . '@lid' : $digits;
        $resp = $this->http()->post($path, ['number' => $first] + $fields);
        if ($digits === '' || $resp->successful() || ! self::unknownNumber((array) ($resp->json() ?? []))) {
            return $resp;
        }
        $retry = $this->http()->post($path, ['number' => $lidFirst ? $digits : $digits . '@lid'] + $fields);
        if ($retry->successful()) {
            $lidFirst ? \Illuminate\Support\Facades\Cache::forget($key) : \Illuminate\Support\Facades\Cache::forever($key, 1);
            Log::info('evolution.send addressed ' . ($lidFirst ? 'as a number' : 'as a hidden id (@lid)'), ['to' => $digits]);

            return $retry;
        }

        return $resp;
    }

    /** PURE. Did Evolution refuse because WhatsApp has no such number ({"response":{"message":[{"exists":false…}]}})? */
    public static function unknownNumber(array $body): bool
    {
        $list = $body['response']['message'] ?? null;
        if (! is_array($list)) {
            return false;
        }
        foreach ($list as $m) {
            if (is_array($m) && array_key_exists('exists', $m) && $m['exists'] === false) {
                return true;
            }
        }

        return false;
    }

    public function sendText(string $instance, string $to, string $message, ?array $quoted = null): array
    {
        $payload = ['text' => $message];
        if ($quoted) {
            $payload['quoted'] = $quoted;   // e.g. ['key' => ['id' => '<wa msg id>']]
        }

        // v5.05 (audit P1-2) — http() is built without ->throw(), and Laravel does not throw on
        // 4xx/5xx. So "instance not connected", a bad apikey and an unknown instance all returned
        // an error BODY, no exception was raised, and NotifyOwnerNewOrder recorded the send as
        // delivered. The status is now reported alongside the body instead of being discarded.
        //
        // Additive on purpose: callers reading $res['key']['id'] are unaffected and callers that
        // ignore the return value are unchanged. Only a caller that ASKS is told.
        $resp = $this->deliver("/message/sendText/{$instance}", $to, $payload);
        $body = $resp->json() ?? [];
        if (! $resp->successful()) {
            \Illuminate\Support\Facades\Log::warning('evolution.sendText http', [
                'instance' => $instance, 'status' => $resp->status(),
                'body' => mb_substr((string) $resp->body(), 0, 300),
            ]);
            $body['_ok']     = false;
            $body['_status'] = $resp->status();
        }

        return $body;
    }

    public function sendImage(string $instance, string $to, string $media, string $caption = ''): array
    {
        return $this->deliver("/message/sendMedia/{$instance}", $to, [
            'mediatype' => 'image',
            'media'     => $media,
            'caption'   => $caption,
        ])->json() ?? [];
    }

    public function markRead(string $instance, string $messageId): void
    {
        $this->http()->post("/chat/markMessageAsRead/{$instance}", ['readMessages' => [['id' => $messageId]]]);
    }

    /** Send a document (e.g. a PDF quotation). $media is a URL or raw base64. Not in the interface;
     *  callers guard with method_exists() (same pattern as getMediaBase64). */
    public function sendDocument(string $instance, string $to, string $media, string $fileName, string $caption = ''): array
    {
        return $this->deliver("/message/sendMedia/{$instance}", $to, [
            'mediatype' => 'document',
            'media'     => $media,
            'fileName'  => $fileName,
            'caption'   => $caption,
        ])->json() ?? [];
    }

    /** Send a location pin (lat/lng + optional place name/address). Not in the interface;
     *  callers guard with method_exists() (same additive pattern as sendDocument). */
    public function sendLocation(string $instance, string $to, float $lat, float $lng, string $name = '', string $address = ''): array
    {
        return $this->deliver("/message/sendLocation/{$instance}", $to, [
            'latitude'  => $lat,
            'longitude' => $lng,
            'name'      => $name,
            'address'   => $address,
        ])->json() ?? [];
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

    /** Evolution instance connection state: 'open' (connected), 'connecting', 'close', or null. */
    public function connectionState(string $instance): ?string
    {
        try {
            $resp = $this->http()->get("/instance/connectionState/{$instance}")->json();
            $state = data_get($resp, 'instance.state') ?? data_get($resp, 'state');
            return is_string($state) ? $state : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Download a media message (image/audio/…) as base64 via Evolution. Used when the
     * webhook delivers an image without inline base64. Returns null on any failure so the
     * caller can fall back to asking the customer to type the product name.
     */
    public function getMediaBase64(string $instance, array $key): ?string
    {
        // Evolution's getBase64FromMediaMessage is version-finicky: an empty `participant` on a
        // 1:1 chat, or a slightly-off body shape, makes it return media-not-found. Clean the key
        // (drop empty participant), try the accepted shapes, and LOG the real error instead of
        // swallowing it — otherwise voice/image fetch fails silently and looks like the bot
        // ignoring the message. Best fix is still WEBHOOK_BASE64=true on the Evolution side so the
        // bytes arrive inline and this callback is never needed.
        $k = [
            'id'        => (string) ($key['id'] ?? ''),
            'remoteJid' => (string) ($key['remoteJid'] ?? ''),
            'fromMe'    => (bool) ($key['fromMe'] ?? false),
        ];
        if (! empty($key['participant'])) {
            $k['participant'] = (string) $key['participant'];
        }

        $bodies = [
            ['message' => ['key' => $k], 'convertToMp4' => false],
            ['message' => ['key' => $k]], // some builds reject convertToMp4 on audio
        ];

        foreach ($bodies as $body) {
            try {
                $resp = $this->http()->post("/chat/getBase64FromMediaMessage/{$instance}", $body);
                if (! $resp->successful()) {
                    Log::warning('evolution.getBase64 http', [
                        'instance' => $instance,
                        'status'   => $resp->status(),
                        'body'     => mb_substr($resp->body(), 0, 300),
                    ]);
                    continue;
                }
                $j   = $resp->json();
                $b64 = data_get($j, 'base64')
                    ?? data_get($j, 'media')
                    ?? data_get($j, 'data.base64');
                if (is_string($b64) && $b64 !== '') {
                    return $b64;
                }
                Log::warning('evolution.getBase64 empty', [
                    'instance'  => $instance,
                    'resp_keys' => is_array($j) ? array_keys($j) : gettype($j),
                ]);
            } catch (\Throwable $e) {
                Log::warning('evolution.getBase64 exception: ' . $e->getMessage());
            }
        }

        return null;
    }

    public function parseIncoming(array $payload): ?array
    {
        // Evolution "messages.upsert" shape (defensive — varies by version).
        $instance = $payload['instance'] ?? data_get($payload, 'data.instance');
        $msg      = data_get($payload, 'data.messages.0') ?? data_get($payload, 'data');
        if (!$msg) return null;

        $remote = data_get($msg, 'key.remoteJid', '');
        $fromMe = (bool) data_get($msg, 'key.fromMe', false);
        $participant = (string) data_get($msg, 'key.participant', '');

        // Forwarded marketing posters / broadcast images carry a forward marker in contextInfo.
        $isForwarded = (bool) (
            data_get($msg, 'message.imageMessage.contextInfo.isForwarded')
            ?? data_get($msg, 'message.extendedTextMessage.contextInfo.isForwarded')
            ?? false
        ) || ((int) (
            data_get($msg, 'message.imageMessage.contextInfo.forwardingScore')
            ?? data_get($msg, 'message.extendedTextMessage.contextInfo.forwardingScore')
            ?? 0
        ) > 0);

        // A WhatsApp Status post that carries an IMAGE is an owner's published menu/offer poster.
        // Carve it out of the blanket status/broadcast drop so the job can verify the poster is an
        // authorized owner and ingest it. Everything else status/broadcast/group is still dropped.
        $statusOffer = \App\Services\Bot\Offers\StatusIngestGate::isStatusImage(
            (string) $remote,
            is_array(data_get($msg, 'message.imageMessage'))
        );

        // Drop groups, broadcasts and (non-image) status posts entirely. Keep 1:1 fromMe messages —
        // they let us detect when the shop owner replied by hand (manual takeover).
        if (! $statusOffer && (
            str_contains((string) $remote, '@g.us')
            || str_contains((string) $remote, '@broadcast')
            || str_contains((string) $remote, 'status@broadcast')
        )) {
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
            // A quotation goes out as a PDF whose caption carries "Quotation ORD-Q… Total USD …
            // Reply to confirm". A reply to that bubble must keep the caption, or the bot loses
            // the quote reference the customer is replying about.
            ?? data_get($ctx, 'quotedMessage.documentMessage.caption')
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

        // Product photo: a customer sent an image, often instead of typing a name.
        // Capture it so the bot can identify the product by vision and search the
        // catalogue. Inline base64 is used when Evolution includes it; otherwise the
        // job fetches it via getBase64FromMediaMessage() using the message key below.
        $img          = data_get($msg, 'message.imageMessage');
        $hasImage     = is_array($img);
        $imageCaption = $hasImage ? (string) data_get($img, 'caption', '') : '';
        $imageB64     = $hasImage ? (string) (
            data_get($msg, 'message.base64')
            ?? data_get($msg, 'base64')
            ?? data_get($payload, 'data.base64')
            ?? ''
        ) : '';

        // Facebook click-to-WhatsApp ad referral: when a customer arrives from (or replies to)
        // one of our Facebook/Instagram ads, WhatsApp attaches the ad's headline + body in
        // contextInfo.externalAdReply. The headline usually names the advertised product, so
        // capturing it lets the bot answer about THAT product instead of generically.
        // Best-effort: present in different message types; never fatal.
        $adReply = data_get($msg, 'message.extendedTextMessage.contextInfo.externalAdReply')
            ?? data_get($msg, 'message.imageMessage.contextInfo.externalAdReply')
            ?? data_get($msg, 'message.videoMessage.contextInfo.externalAdReply')
            ?? data_get($msg, 'message.buttonsResponseMessage.contextInfo.externalAdReply')
            ?? data_get($msg, 'message.listResponseMessage.contextInfo.externalAdReply')
            ?? data_get($payload, 'data.contextInfo.externalAdReply')
            ?? null;
        $adHeadline  = is_array($adReply) ? trim((string) (data_get($adReply, 'title') ?: '')) : '';
        $adBody      = is_array($adReply) ? trim((string) (data_get($adReply, 'body') ?: '')) : '';
        $adSourceUrl = is_array($adReply) ? (string) (data_get($adReply, 'sourceUrl') ?: '') : '';

        // Voice note: a customer spoke their order instead of typing. Capture it so the job
        // can transcribe it (inline base64 if Evolution sent it, else fetched via media_key).
        $aud      = data_get($msg, 'message.audioMessage');
        $hasAudio = is_array($aud);

        // WhatsApp-Web mode (2026-07-18, additive): videos and documents (PDFs etc.)
        // were invisible to the transcript — capture their presence, mime, filename and
        // caption so the inbox can show and stream them. Bot behaviour unchanged.
        $vid    = data_get($msg, 'message.videoMessage');
        $doc    = data_get($msg, 'message.documentMessage');
        $hasVideo = is_array($vid);
        $hasDoc   = is_array($doc);
        $mediaMime = (string) (
            ($hasImage ? data_get($img, 'mimetype') : null)
            ?? ($hasAudio ? data_get($aud, 'mimetype') : null)
            ?? ($hasVideo ? data_get($vid, 'mimetype') : null)
            ?? ($hasDoc ? data_get($doc, 'mimetype') : null)
            ?? ''
        );
        $mediaCaption = trim((string) (
            ($imageCaption !== '' ? $imageCaption : null)
            ?? ($hasVideo ? data_get($vid, 'caption') : null)
            ?? ($hasDoc ? data_get($doc, 'caption') : null)
            ?? ''
        ));
        $docName = $hasDoc ? trim((string) (data_get($doc, 'fileName') ?: '')) : '';
        $audioB64 = $hasAudio ? (string) (
            data_get($msg, 'message.base64') ?? data_get($msg, 'base64') ?? data_get($payload, 'data.base64') ?? ''
        ) : '';

        // WhatsApp hides this customer's number: the chat id is a "hidden id" (@lid), not a phone number.
        // Some messages still carry the real number alongside (remoteJidAlt / senderPn) — keep it, so a
        // salesperson can call them (App\Services\Isp\CallbackNumber).
        $hidden = str_ends_with((string) $remote, '@lid');
        $altJid = $hidden ? (string) (data_get($msg, 'key.remoteJidAlt') ?: data_get($msg, 'key.senderPn') ?: '') : '';
        $phoneAlt = str_contains($altJid, '@s.whatsapp.net') ? (preg_replace('/\D/', '', explode('@', $altJid)[0]) ?? '') : '';

        return [
            'instance'  => (string) $instance,
            'from'      => \App\Services\Bot\Offers\StatusIngestGate::senderNumber((string) $remote, $participant),
            'hidden'    => $hidden,
            'phone_alt' => $phoneAlt,
            'text'      => (string) $text,
            'lat'       => $lat !== null ? (float) $lat : null,
            'lng'       => $lng !== null ? (float) $lng : null,
            'loc_name'  => $locName !== null ? (string) $locName : null,
            'loc_address' => $locAddr !== null ? (string) $locAddr : null,
            'messageId' => (string) data_get($msg, 'key.id', ''),
            'from_me'         => $fromMe,
            'is_status_post'  => $statusOffer,
            'is_forwarded'    => $isForwarded,
            'is_status_reply' => $isStatusReply,
            'quoted_text'     => $quotedText,
            'has_image'       => $hasImage,
            'image_caption'   => $imageCaption,
            'image_b64'       => $imageB64,
            'has_audio'       => $hasAudio,
            'audio_b64'       => $audioB64,
            'ad_headline'     => $adHeadline,
            'ad_body'         => $adBody,
            'ad_source_url'   => $adSourceUrl,
            'is_ad_referral'  => ($adHeadline !== '' || $adBody !== ''),
            'has_video'       => $hasVideo,
            'has_document'    => $hasDoc,
            'doc_name'        => $docName,
            'media_mime'      => $mediaMime,
            'media_caption'   => $mediaCaption,
            'media_key'       => ($hasImage || $hasAudio || $hasVideo || $hasDoc) ? [
                'id'          => (string) data_get($msg, 'key.id', ''),
                'remoteJid'   => (string) $remote,
                'participant' => $participant,
                'fromMe'      => $fromMe,
            ] : null,
            'raw'       => $payload,
        ];
    }
}
