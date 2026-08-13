<?php declare(strict_types=1);

namespace App\Services\Bot;

use App\Contracts\WhatsAppGateway;

/**
 * v4.78 (M-CHAT-1, ADR-018) — the website becomes a channel by pretending to be WhatsApp.
 *
 * The brain already speaks through the WhatsAppGateway interface; this implementation simply
 * KEEPS the messages instead of delivering them. `AiBrain::handle()` runs completely unchanged —
 * same catalogue, same prices, same MOQ rules, same cart, same honesty rules, same clock — and
 * whatever it "sent" comes back to the HTTP caller as structured messages.
 *
 * It also implements the two additive methods the brains guard with method_exists()
 * (sendDocument for quotation/menu PDFs — sent by URL, so they travel fine — and sendLocation),
 * so no shopping flow dead-ends on the web.
 */
final class CollectorGateway implements WhatsAppGateway
{
    /** @var array<int, array<string, mixed>> */
    private array $out = [];

    public function sendText(string $instance, string $to, string $message, ?array $quoted = null): array
    {
        $this->out[] = ['type' => 'text', 'text' => $message];

        return ['collected' => true];
    }

    public function sendImage(string $instance, string $to, string $media, string $caption = ''): array
    {
        $this->out[] = ['type' => 'image', 'url' => $media, 'caption' => $caption];

        return ['collected' => true];
    }

    public function sendDocument(string $instance, string $to, string $media, string $fileName, string $caption = ''): array
    {
        // v4.92 — a BROWSER is on the other end of this, not WhatsApp. Every document sender in
        // AiBrain prefers raw base64 over a URL, because Evolution embeds base64 reliably while a
        // URL depends on APP_URL being publicly correct. Handed to the widget that base64 became
        // href="JVBERi0x…", the browser read it as a relative path, and nginx answered
        // 414 Request-URI Too Large — a real click, on a real quotation, in production.
        //
        // A data: URI is not the fix either: Chrome has blocked top-level navigation to data: URLs
        // since 2017, so the link would simply do nothing. The only thing that works in a browser is
        // a real URL, so anything else is refused here rather than rendered as a broken link. The
        // caller's accompanying text still reaches the customer.
        $m = trim($media);
        if ($m === '' || ! preg_match('#^(https?://|/)#i', $m)) {
            \Log::warning('web chat: document dropped, not a URL (' . $fileName . ', ' . strlen($m) . ' bytes)');

            return ['collected' => false, 'error' => 'not_a_url'];
        }
        $this->out[] = ['type' => 'document', 'url' => $m, 'name' => $fileName, 'caption' => $caption];

        return ['collected' => true];
    }

    public function sendLocation(string $instance, string $to, float $lat, float $lng, string $name = '', string $address = ''): array
    {
        $this->out[] = ['type' => 'location', 'lat' => $lat, 'lng' => $lng, 'name' => $name, 'address' => $address];

        return ['collected' => true];
    }

    public function markRead(string $instance, string $messageId): void
    {
        // nothing to mark on the web — the caller IS the reader
    }

    public function parseIncoming(array $payload): ?array
    {
        return null;                    // web messages arrive via the endpoint, never a webhook
    }

    public function fetchContacts(string $instance): array
    {
        return [];
    }

    /** Everything the brain "sent", in order. */
    public function messages(): array
    {
        return $this->out;
    }
}
