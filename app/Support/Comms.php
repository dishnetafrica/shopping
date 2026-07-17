<?php

namespace App\Support;

use App\Models\CommMessage;
use App\Models\Tenant;
use App\Services\Comms\CommCenter;
use App\Services\Comms\EmailService;

/**
 * One-line helpers for modules (spec: Email::sendInvoice() style).
 * Thin sugar over CommCenter/EmailService — no logic lives here.
 *
 *   Comms::sendInvoice($tenant, 'x@y.com', ['invoice_number' => 'INV-1', ...], $pdfB64);
 *   Comms::event($tenant, 'payment_reminder', ['email' => $c->email], $vars);
 */
class Comms
{
    /** Publish an event through the Communication Center router. @return CommMessage[] */
    public static function event(Tenant $tenant, string $event, array $recipient, array $vars = [], array $opts = []): array
    {
        return app(CommCenter::class)->publish($tenant, $event, $recipient, $vars, $opts);
    }

    /** Queue one template email directly (bypasses channel routing). */
    public static function email(Tenant $tenant, string $templateKey, string $to, array $vars = [], array $opts = []): CommMessage
    {
        return app(EmailService::class)->queueTemplate($tenant, $templateKey, $to, $vars, $opts);
    }

    public static function sendInvoice(Tenant $t, string $to, array $vars, ?string $pdfB64 = null, array $opts = []): CommMessage
    {
        if ($pdfB64) $opts['attachments'][] = ['name' => ($vars['invoice_number'] ?? 'invoice').'.pdf', 'mime' => 'application/pdf', 'data' => $pdfB64];
        return self::email($t, 'invoice', $to, $vars, $opts + ['related_type' => 'invoice', 'related_id' => $vars['invoice_id'] ?? null]);
    }

    public static function sendQuotation(Tenant $t, string $to, array $vars, ?string $pdfB64 = null, array $opts = []): CommMessage
    {
        if ($pdfB64) $opts['attachments'][] = ['name' => ($vars['quotation_number'] ?? 'quotation').'.pdf', 'mime' => 'application/pdf', 'data' => $pdfB64];
        return self::email($t, 'quotation', $to, $vars, $opts + ['related_type' => 'quotation', 'related_id' => $vars['quotation_id'] ?? null]);
    }

    public static function sendReceipt(Tenant $t, string $to, array $vars, array $opts = []): CommMessage
    {
        return self::email($t, 'receipt', $to, $vars, $opts + ['related_type' => 'receipt', 'related_id' => $vars['receipt_id'] ?? null]);
    }

    public static function sendPasswordReset(Tenant $t, string $to, array $vars, array $opts = []): CommMessage
    {
        return self::email($t, 'password_reset', $to, $vars, $opts);
    }

    public static function sendOtp(Tenant $t, string $to, string $code, array $opts = []): CommMessage
    {
        return self::email($t, 'otp', $to, ['otp_code' => $code], $opts + ['max_attempts' => 2]);
    }

    public static function sendShipment(Tenant $t, string $to, array $vars, array $opts = []): CommMessage
    {
        return self::email($t, 'shipment', $to, $vars, $opts + ['related_type' => 'shipment', 'related_id' => $vars['shipment_id'] ?? null]);
    }
}
