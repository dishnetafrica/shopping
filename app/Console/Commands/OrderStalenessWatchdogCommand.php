<?php
namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Forces sellers to keep order data current. If an order sits in an active status past its SLA, the
 * shop's alert number gets a nudge to update it; if it's still stuck after another SLA window, the
 * nudge escalates (capped, so it never spams). This keeps delivered_at — and therefore the customer
 * confirmation loop and every downstream metric — from silently going missing.
 */
class OrderStalenessWatchdogCommand extends Command
{
    protected $signature = 'orders:staleness-watchdog';
    protected $description = 'Nudge sellers about orders stuck in an active status past their SLA';

    /** Default minutes an order may sit in each active status before a nudge (tenant-overridable via setting order_sla). */
    private const SLA = [
        'New' => 15, 'Accepted' => 45, 'Confirmed' => 45, 'Preparing' => 90,
        'Ready' => 45, 'Packed' => 120, 'Dispatched' => 120, 'Out for delivery' => 120,
    ];
    private const DISPATCH = ['Packed', 'Dispatched', 'Out for delivery'];
    private const MAX_LEVEL = 2;

    public function handle(): int
    {
        // DISABLED. Replaced by the vertical-aware `orders:pending-digest`, which sends ONE digest per
        // tenant using per-vertical SLAs (a manufacturer's "New" window is ~2 days, not 15 minutes).
        // The old per-order nudges below spammed manufacturers with restaurant-speed thresholds and
        // restaurant wording. Kept as a no-op so any lingering schedule/cron cannot resurrect that.
        $this->info('orders:staleness-watchdog is disabled — use orders:pending-digest.');
        return self::SUCCESS;
    }

    private function isTransport(Order $order): bool
    {
        return Shipment::withoutGlobalScopes()->where('order_id', $order->id)->exists();
    }
}
