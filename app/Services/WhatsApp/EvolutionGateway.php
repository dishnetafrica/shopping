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

    public function parseIncoming(array $payload): ?array
    {
        // Evolution "messages.upsert" shape (defensive — varies by version).
        $instance = $payload['instance'] ?? data_get($payload, 'data.instance');
        $msg      = data_get($payload, 'data.messages.0') ?? data_get($payload, 'data');
        if (!$msg) return null;

        $remote = data_get($msg, 'key.remoteJid', '');
        $fromMe = (bool) data_get($msg, 'key.fromMe', false);
        if ($fromMe || str_contains((string) $remote, '@g.us')) return null; // ignore self + groups

        $text = data_get($msg, 'message.conversation')
            ?? data_get($msg, 'message.extendedTextMessage.text', '');

        return [
            'instance'  => (string) $instance,
            'from'      => preg_replace('/[^0-9]/', '', explode('@', (string) $remote)[0]),
            'text'      => (string) $text,
            'messageId' => (string) data_get($msg, 'key.id', ''),
            'raw'       => $payload,
        ];
    }
}
