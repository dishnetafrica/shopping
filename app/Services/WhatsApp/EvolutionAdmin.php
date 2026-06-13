<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;

/**
 * Manages Evolution instances FROM our portal, so a business owner never opens
 * the Evolution dashboard. Flow for "Connect WhatsApp": create instance -> fetch
 * a QR to scan -> poll connection state until 'open'.
 *
 * Evolution's JSON shapes drift across v2.x, so every read pulls from the known
 * alternative paths rather than assuming one.
 */
class EvolutionAdmin
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $cfg = config('whatsapp.drivers.evolution');
        $this->baseUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $this->apiKey  = (string) ($cfg['api_key'] ?? '');
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    protected function http()
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['apikey' => $this->apiKey])
            ->acceptJson()->timeout(20);
    }

    /** open | connecting | close | missing */
    public function state(string $instance): string
    {
        try {
            $r = $this->http()->get("/instance/connectionState/{$instance}");
            if ($r->status() === 404) return 'missing';
            $d = $r->json() ?? [];
            $s = data_get($d, 'instance.state') ?? data_get($d, 'state') ?? '';
            return $s !== '' ? (string) $s : 'close';
        } catch (\Throwable $e) {
            return 'missing';
        }
    }

    /** Create the instance if it doesn't exist yet. */
    public function createIfMissing(string $instance): array
    {
        if ($this->state($instance) !== 'missing') return ['exists' => true];
        try {
            return $this->http()->post('/instance/create', [
                'instanceName' => $instance,
                'integration'  => 'WHATSAPP-BAILEYS',
                'qrcode'       => true,
            ])->json() ?? [];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Point this instance's webhook at our app (best-effort; a global webhook also works). */
    public function setWebhook(string $instance, string $url): void
    {
        $events = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE', 'QRCODE_UPDATED'];
        // v2 wrapped shape
        try {
            $this->http()->post("/webhook/set/{$instance}", [
                'webhook' => ['enabled' => true, 'url' => $url, 'byEvents' => false, 'base64' => false, 'events' => $events],
            ]);
        } catch (\Throwable $e) {
        }
        // older flat shape (harmless if the first one already took)
        try {
            $this->http()->post("/webhook/set/{$instance}", [
                'url' => $url, 'webhook_by_events' => false, 'webhook_base64' => false, 'events' => $events,
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** Fetch a QR to scan. Returns ['base64'=>?, 'code'=>?, 'pairingCode'=>?]. */
    public function qr(string $instance): array
    {
        $out = ['base64' => null, 'code' => null, 'pairingCode' => null];
        try {
            $d = $this->http()->get("/instance/connect/{$instance}")->json() ?? [];
            $out['base64']      = data_get($d, 'qrcode.base64') ?? data_get($d, 'base64');
            $out['code']        = data_get($d, 'qrcode.code') ?? data_get($d, 'code');
            $out['pairingCode'] = data_get($d, 'qrcode.pairingCode') ?? data_get($d, 'pairingCode');
        } catch (\Throwable $e) {
        }
        return $out;
    }

    public function disconnect(string $instance): void
    {
        try {
            $this->http()->delete("/instance/logout/{$instance}");
        } catch (\Throwable $e) {
        }
    }

    /**
     * Fetch a page of stored messages for an instance. Evolution's remoteJid
     * filter is unreliable, so we pull everything and bucket by chat ourselves.
     * `offset` is the page size. Returns the raw Baileys-style records.
     */
    public function findMessages(string $instance, int $page = 1, int $offset = 200): array
    {
        try {
            $d = $this->http()->post("/chat/findMessages/{$instance}", [
                'where'  => (object) [],
                'page'   => $page,
                'offset' => $offset,
            ])->json() ?? [];
            $recs = data_get($d, 'messages.records');
            if (is_array($recs)) return $recs;
            $recs = data_get($d, 'records');
            if (is_array($recs)) return $recs;
            return (is_array($d) && array_is_list($d)) ? $d : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Diagnostic: return the raw status + decoded body so we can see the shape. */
    public function findMessagesRaw(string $instance, int $page = 1, int $offset = 5): array
    {
        try {
            $resp = $this->http()->post("/chat/findMessages/{$instance}", [
                'where'  => (object) [],
                'page'   => $page,
                'offset' => $offset,
            ]);
            return ['status' => $resp->status(), 'body' => $resp->json() ?? []];
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => ['error' => $e->getMessage()]];
        }
    }

    /** Current webhook config for the instance (so we can see where events go). */
    public function getWebhook(string $instance): array
    {
        try {
            return $this->http()->get("/webhook/find/{$instance}")->json() ?? [];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Contacts / chats list for the instance (one entry per conversation). */
    public function findChats(string $instance): array
    {
        try {
            $d = $this->http()->post("/chat/findChats/{$instance}", ['where' => (object) []])->json() ?? [];
            if (is_array($d) && array_is_list($d)) return $d;
            $recs = data_get($d, 'chats.records') ?? data_get($d, 'records');
            return is_array($recs) ? $recs : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
