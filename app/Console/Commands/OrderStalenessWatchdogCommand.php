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
        $orders = Order::withoutGlobalScopes()
            ->whereIn('status', array_keys(self::SLA))
            ->where('stale_level', '<', self::MAX_LEVEL)
            ->orderBy('id')
            ->limit(500)->get();

        $nudged = 0;
        $slaOverrides = [];   // per-tenant cache
        foreach ($orders as $o) {
            $tenant = Tenant::find($o->tenant_id);
            if (! $tenant) continue;

            $sla = $slaOverrides[$o->tenant_id] ??= array_merge(self::SLA, (array) $tenant->setting('order_sla', []));
            $mins = max(5, (int) ($sla[$o->status] ?? 60));

            $since = $o->status_changed_at ?: $o->created_at;
            if (! $since || $since->gt(now()->subMinutes($mins))) continue;          // not stale yet

            // Long-haul transit is expected — the shipment chain owns dispatch-state timing.
            if (in_array($o->status, self::DISPATCH, true) && $this->isTransport($o)) continue;

            // Don't re-nudge within one SLA window of the last nudge.
            if ($o->stale_nudged_at && $o->stale_nudged_at->gt(now()->subMinutes($mins))) continue;

            $stuck = $since->diffForHumans(now(), ['parts' => 2, 'short' => true, 'syntax' => true]);
            $level = (int) $o->stale_level;
            $text = $level === 0
                ? "\u{26A0}\u{FE0F} Order {$o->order_no} has been \"{$o->status}\" for {$stuck} with no update. Please update its status."
                : "\u{23F0} Order {$o->order_no} is STILL \"{$o->status}\" ({$stuck}). Please action it or mark it delivered/cancelled.";

            NotifyOwner::dispatch($o->tenant_id, $text);
            $o->stale_level = $level + 1;
            $o->stale_nudged_at = now();
            $o->saveQuietly();   // no status change — don't trigger customer notifications
            $nudged++;
        }
        $this->info("orders:staleness-watchdog — nudged {$nudged}.");
        return self::SUCCESS;
    }

    private function isTransport(Order $order): bool
    {
        return Shipment::withoutGlobalScopes()->where('order_id', $order->id)->exists();
    }
}
