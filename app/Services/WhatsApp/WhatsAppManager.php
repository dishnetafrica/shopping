<?php
namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGateway;
use InvalidArgumentException;

/** Resolves a WhatsAppGateway driver by name (evolution|cloud). */
class WhatsAppManager
{
    public function driver(?string $name = null): WhatsAppGateway
    {
        $name ??= config('whatsapp.default');
        $cfg = config("whatsapp.drivers.{$name}");

        return match ($name) {
            'evolution' => new EvolutionGateway($cfg['base_url'] ?? '', $cfg['api_key'] ?? ''),
            'cloud'     => new CloudApiGateway($cfg['token'] ?? '', $cfg['base_url'] ?? ''),
            default     => throw new InvalidArgumentException("Unknown WhatsApp driver [{$name}]"),
        };
    }
}
