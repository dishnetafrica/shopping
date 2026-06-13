<?php
namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGateway;
use App\Models\Tenant;
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

    /**
     * Resolve the right gateway for a specific tenant. Same interface, but for
     * the Cloud API driver we build it with THIS tenant's own access token
     * (BYO Cloud API), falling back to the global token if none is set.
     */
    public function forTenant(Tenant $t): WhatsAppGateway
    {
        $name = $t->whatsapp_driver ?: config('whatsapp.default');

        if ($name === 'cloud') {
            $base  = config('whatsapp.drivers.cloud.base_url');
            $token = (string) ($t->setting('cloud_token') ?: config('whatsapp.drivers.cloud.token', ''));
            return new CloudApiGateway($token, $base);
        }

        return $this->driver($name);
    }
}
