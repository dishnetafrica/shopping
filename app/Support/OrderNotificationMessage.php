<?php

namespace App\Support;

/**
 * Pure builder for the new-order notification text. No I/O, so it is unit-testable
 * and produces identical output everywhere.
 */
final class OrderNotificationMessage
{
    /**
     * @param array $order ['order_no','customer_name','customer_phone','items_text'|'items_json','total','location','created_at'(epoch|string)]
     */
    public static function build(array $order, string $currency = 'UGX', string $tz = 'Africa/Kampala'): string
    {
        $items = trim((string) ($order['items_text'] ?? ''));
        if ($items === '' && ! empty($order['items_json']) && is_array($order['items_json'])) {
            $x = "\u{00D7}";
            $items = implode(', ', array_map(
                fn ($l) => (($l['qty'] ?? 1) . ' ' . $x . ' ' . ($l['name'] ?? '')),
                $order['items_json']
            ));
        }

        $phone = (string) ($order['customer_phone'] ?? '');
        if ($phone !== '' && $phone[0] !== '+') $phone = '+' . preg_replace('/\D+/', '', $phone);

        $when = self::formatTime($order['created_at'] ?? null, $tz);

        $lines = [];
        $lines[] = "\u{1F6D2} New Order";
        $lines[] = 'Order #: ' . ($order['order_no'] ?? '');
        $lines[] = 'Customer: ' . ($order['customer_name'] ?: 'Customer');
        $lines[] = 'Phone: ' . $phone;
        $lines[] = 'Items:';
        $lines[] = $items;
        $lines[] = 'Total: ' . $currency . ' ' . number_format((float) ($order['total'] ?? 0));
        if (! empty($order['location'])) $lines[] = 'Delivery: ' . $order['location'];
        if ($when !== '') $lines[] = 'Order Time: ' . $when;

        return implode("\n", $lines);
    }

    private static function formatTime($ts, string $tz): string
    {
        if ($ts === null || $ts === '') return '';
        try {
            $dt = is_numeric($ts)
                ? (new \DateTime('@' . (int) $ts))
                : new \DateTime((string) $ts);
            $dt->setTimezone(new \DateTimeZone($tz));
            return $dt->format('d M Y h:i A');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
