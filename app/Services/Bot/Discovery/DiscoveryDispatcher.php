<?php

namespace App\Services\Bot\Discovery;

use App\Models\BusinessDiscovery;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Support\Facades\Log;

/**
 * Business Discovery — owner dispatch. Sends the report summary to the configured owner/admin
 * number(s) and marks the scan 'sent'. Never activates anything.
 */
class DiscoveryDispatcher
{
    public function __construct(protected WhatsAppManager $wa) {}

    public function send(Tenant $tenant, BusinessDiscovery $discovery): bool
    {
        $numbers = $tenant->ownerAlertNumbers();
        if (! $numbers) {
            Log::warning("DiscoveryDispatcher: tenant {$tenant->id} has no owner_alert_phone configured.");
            return false;
        }

        $msg = DiscoveryReport::toWhatsApp((array) $discovery->report);
        $gateway = $this->wa->forTenant($tenant);

        $sentAny = false;
        foreach ($numbers as $to) {
            try {
                $gateway->sendText($tenant->whatsapp_instance, $to, $msg);
                $sentAny = true;
            } catch (\Throwable $e) {
                Log::warning("DiscoveryDispatcher send to {$to} failed: " . $e->getMessage());
            }
        }

        if ($sentAny) {
            $discovery->update(['status' => 'sent', 'sent_to' => implode(',', $numbers), 'sent_at' => now()]);
        }
        return $sentAny;
    }
}
