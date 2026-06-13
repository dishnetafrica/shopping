<?php
namespace App\Contracts;

/**
 * One interface for all WhatsApp providers. Today: Evolution.
 * Tomorrow: official Cloud API — swap the driver, nothing else changes.
 */
interface WhatsAppGateway
{
    /** @param string $instance Evolution instance / Cloud phone id for the tenant */
    public function sendText(string $instance, string $to, string $message, ?array $quoted = null): array;

    public function sendImage(string $instance, string $to, string $media, string $caption = ''): array;

    public function markRead(string $instance, string $messageId): void;

    /** Normalise an incoming webhook payload to: [instance, from, text, messageId, raw] */
    public function parseIncoming(array $payload): ?array;
}
