<?php

namespace App\Services\Delivery;

use App\Models\Delivery;

/** Pure delivery status machine + mapping to the customer-facing order status. */
final class DeliveryStatus
{
    /** Allowed forward transitions. */
    private const NEXT = [
        Delivery::ASSIGNED  => [Delivery::PICKED, Delivery::OUT, Delivery::FAILED],
        Delivery::PICKED    => [Delivery::OUT, Delivery::FAILED],
        Delivery::OUT       => [Delivery::DELIVERED, Delivery::FAILED],
        Delivery::DELIVERED => [],
        Delivery::FAILED    => [],
    ];

    public static function all(): array
    {
        return [Delivery::ASSIGNED, Delivery::PICKED, Delivery::OUT, Delivery::DELIVERED, Delivery::FAILED];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::NEXT[$from] ?? [], true);
    }

    /** Customer-facing order status for a given delivery status (null = leave unchanged). */
    public static function orderStatusFor(string $deliveryStatus): ?string
    {
        return match ($deliveryStatus) {
            Delivery::ASSIGNED  => 'Confirmed',
            Delivery::OUT       => 'Out for delivery',
            Delivery::DELIVERED => 'Delivered',
            default             => null,   // picked / failed -> don't change the order's customer status
        };
    }
}
