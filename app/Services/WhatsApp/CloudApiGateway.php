<?php
namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Http;

/**
 * Official WhatsApp Cloud API driver. Implemented later when migrating a
 * tenant to the official API. Same interface, so the bot/panel never change.
 */
class CloudApiGateway implements WhatsAppGateway
{
    public function __construct(
        protected string $token,
        protected string $baseUrl,
    ) {}

    public function sendText(string $instance, string $to, string $message, ?array $quoted = null): array
    {
        // $instance == Cloud phone number id
        return Http::withToken($this->token)->post("{$this->baseUrl}/{$instance}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => preg_replace('/[^0-9]/', '', $to),
            'type' => 'text',
            'text' => ['body' => $message],
        ])->json() ?? [];
    }

    public function sendImage(string $instance, string $to, string $media, string $caption = ''): array
    {
        return Http::withToken($this->token)->post("{$this->baseUrl}/{$instance}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => preg_replace('/[^0-9]/', '', $to),
            'type' => 'image',
            'image' => ['link' => $media, 'caption' => $caption],
        ])->json() ?? [];
    }

    /**
     * Send a document (e.g. a PDF quotation). Meta Cloud API requires a PUBLIC https link (or a
     * pre-uploaded media id) — it cannot accept raw base64 inline. Callers guard with method_exists()
     * (same optional-capability pattern as EvolutionGateway::sendDocument). Pass a public URL such as
     * the token route /q/{token}/pdf; a base64/data-URI is rejected by Meta and logged as unsupported.
     */
    public function sendDocument(string $instance, string $to, string $media, string $fileName, string $caption = ''): array
    {
        if (! preg_match('#^https?://#i', $media)) {
            \Log::warning('CloudApiGateway::sendDocument needs a public URL; got non-URL media — skipping', ['file' => $fileName]);
            return ['error' => 'cloud_api_requires_public_url'];
        }
        return Http::withToken($this->token)->post("{$this->baseUrl}/{$instance}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => preg_replace('/[^0-9]/', '', $to),
            'type' => 'document',
            'document' => ['link' => $media, 'filename' => $fileName, 'caption' => $caption],
        ])->json() ?? [];
    }

    public function markRead(string $instance, string $messageId): void
    {
        Http::withToken($this->token)->post("{$this->baseUrl}/{$instance}/messages", [
            'messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $messageId,
        ]);
    }

    public function parseIncoming(array $payload): ?array
    {
        $entry = data_get($payload, 'entry.0.changes.0.value');
        $msg = data_get($entry, 'messages.0');
        if (!$msg) return null;
        return [
            'instance'  => (string) data_get($entry, 'metadata.phone_number_id'),
            'from'      => preg_replace('/[^0-9]/', '', (string) data_get($msg, 'from')),
            'text'      => (string) data_get($msg, 'text.body', ''),
            'messageId' => (string) data_get($msg, 'id', ''),
            'raw'       => $payload,
        ];
    }

    public function fetchContacts(string $instance): array
    {
        return []; // Cloud API has no contact-list endpoint
    }
}
