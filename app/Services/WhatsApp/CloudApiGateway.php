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
}
