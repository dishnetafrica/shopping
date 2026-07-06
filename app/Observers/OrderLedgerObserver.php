<?php declare(strict_types=1);

namespace App\Observers;

use App\Models\Order;
use App\Services\Ledger\OrderLedgerService;

/**
 * A SEPARATE observer (registered alongside the existing OrderObserver) that posts an order to the
 * CloudBSS ledger the moment it becomes delivered/complete. Kept separate so it never entangles the
 * operational OrderObserver, and so it can be enabled per tenant. Idempotent + best-effort inside
 * OrderLedgerService, so double-fires and failures are harmless.
 */
class OrderLedgerObserver
{
    public function saved(Order $order): void
    {
        // Fire only on the transition into a complete state (delivered or customer-confirmed).
        $becameComplete =
            ($order->wasChanged('delivered_at') && $order->delivered_at !== null) ||
            ($order->wasChanged('customer_confirmed_at') && $order->customer_confirmed_at !== null) ||
            ($order->wasChanged('status') && strcasecmp((string) $order->status, 'Delivered') === 0);

        if (! $becameComplete) return;

        app(OrderLedgerService::class)->postSale($order);
    }
}
