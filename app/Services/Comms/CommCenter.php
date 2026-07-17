<?php

namespace App\Services\Comms;

use App\Models\CommMessage;
use App\Models\Tenant;

/**
 * The Notification Engine seam (spec: "Communication Center").
 *
 * Modules publish an EVENT ("invoice_created", "order_placed", …); this router
 * decides which CHANNELS carry it, per tenant settings:
 *
 *   settings.comms_routes = { "invoice_created": ["email"],
 *                             "payment_overdue": ["email","whatsapp"] }
 *
 * Milestone 1 shipped the EMAIL channel; Milestone 3 adds WHATSAPP as a second
 * transport (WhatsAppService → WhatsAppDispatcher wrapping the frozen
 * WhatsAppManager, public API only). The routing matrix (Email Center →
 * Routing) is the explicit per-event opt-in — defaults stay email-only.
 */
class CommCenter
{
    /** Default routing when a tenant hasn't customised comms_routes. */
    public const DEFAULT_ROUTES = [
        'welcome'            => ['email'],
        'quotation'          => ['email'],
        'sales_order'        => ['email'],
        'invoice'            => ['email'],
        'receipt'            => ['email'],
        'payment_reminder'   => ['email'],
        'delivery_update'    => ['email'],
        'shipment'           => ['email'],
        'seller_settlement'  => ['email'],
        'password_reset'     => ['email'],
        'otp'                => ['email'],
        'support_ticket'     => ['email'],
    ];

    public function __construct(protected EmailService $email, protected WhatsAppService $whatsapp)
    {
    }

    /**
     * Publish a communication event. Returns the CommMessage rows created
     * (one per channel that produced a message).
     *
     * $recipient: ['email' => ..., 'name' => ..., 'phone' => ...]
     */
    public function publish(Tenant $tenant, string $event, array $recipient, array $vars = [], array $opts = []): array
    {
        $routes   = (array) $tenant->setting('comms_routes', []);
        $channels = $routes[$event] ?? (self::DEFAULT_ROUTES[$event] ?? ['email']);
        $out      = [];

        foreach ($channels as $channel) {
            if ($channel === 'email' && ! empty($recipient['email'])) {
                $out[] = $this->email->queueTemplate(
                    $tenant, $event, $recipient['email'], $vars,
                    array_merge($opts, ['to_name' => $recipient['name'] ?? null])
                );
            }
            // M3: WhatsApp transport is live. Opt-in is EXPLICIT — the default
            // routing matrix is email-only, so nothing sends on WhatsApp until
            // the owner ticks it per event in Email Center → Routing.
            if ($channel === 'whatsapp' && ! empty($recipient['phone'])) {
                $out[] = $this->whatsapp->queueTemplate(
                    $tenant, $event, (string) $recipient['phone'], $vars,
                    array_merge($opts, ['to_name' => $recipient['name'] ?? null])
                );
            }
            // 'sms' / 'push': future transports on the same spine.
        }

        return $out;
    }
}
