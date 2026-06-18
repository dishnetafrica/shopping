<?php
namespace App\Services\Winworld;

/**
 * Customer-facing WhatsApp messages tied to sales-order milestones.
 * Pure: maps a stage/event to a rendered message. Templates are overridable
 * per tenant via settings (ww_cust_<event>); these are the defaults.
 */
final class CustomerMessages
{
    public const DEFAULTS = [
        'order_received'   => "✅ Hi {customer}, we've received your order {order_no} for {product}. We'll keep you posted. — Win World",
        'in_production'    => "🏭 Update: your order {order_no} ({product}) is now in production.",
        'out_for_delivery' => "🚚 Your order {order_no} ({product}) is out for delivery.",
        'delivered'        => "📦 Order {order_no} ({product}) has been delivered. Thank you for your business! — Win World",
    ];

    /** Sales stage the order is ENTERING → the customer event to send. */
    public const STAGE_EVENT = [
        'order_received' => 'order_received',
        'order_indent'   => 'in_production',
        'delivery'       => 'out_for_delivery',
        // 'delivered' is sent when the order is marked Won (handled by the controller)
    ];

    public static function eventForStage(string $stage): ?string
    {
        return self::STAGE_EVENT[$stage] ?? null;
    }

    public static function events(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** Render a message for an event. $override = tenant template (optional). */
    public static function render(string $event, array $ctx, ?string $override = null): ?string
    {
        $tpl = $override !== null && $override !== '' ? $override : (self::DEFAULTS[$event] ?? null);
        if (! $tpl) return null;
        return strtr($tpl, [
            '{order_no}' => (string) ($ctx['order_no'] ?? ''),
            '{customer}' => (string) ($ctx['customer'] ?? ''),
            '{product}'  => (string) ($ctx['product'] ?? ''),
        ]);
    }
}
