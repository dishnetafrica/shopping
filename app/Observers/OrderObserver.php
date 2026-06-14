<?php
namespace App\Observers;

use App\Jobs\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class OrderObserver
{
    /** Per-tenant continuous order numbers: <PREFIX>-1024 */
    public function creating(Order $order): void
    {
        if (empty($order->order_no) && $order->tenant_id) {
            $prefix = Tenant::find($order->tenant_id)?->order_prefix ?: 'ORD';
            try { $seq = Redis::incr("tenant:{$order->tenant_id}:order_seq"); }
            catch (\Throwable $e) { $seq = Order::withoutGlobalScopes()->where('tenant_id', $order->tenant_id)->count() + 1; }
            $order->order_no = $prefix.'-'.$seq;
        }
        if (empty($order->track_token)) {
            $order->track_token = Str::lower(Str::random(12));
        }
    }

    /**
     * Stamp the delivery date the moment the status becomes "Delivered" (from any
     * path: panel dropdown, Filament, or the bot). Runs before the write so the
     * timestamp persists in the same save. Clears it if the order is moved back
     * out of Delivered.
     */
    public function updating(Order $order): void
    {
        if (! $order->isDirty('status')) {
            return;
        }
        $isDelivered   = strcasecmp((string) $order->status, 'Delivered') === 0;
        $wasDelivered  = strcasecmp((string) $order->getOriginal('status'), 'Delivered') === 0;

        if ($isDelivered && empty($order->delivered_at)) {
            $order->delivered_at = now();
        } elseif (! $isDelivered && $wasDelivered) {
            $order->delivered_at = null;
        }
    }

    /** Alert the owner the moment a new order lands (bot or phone orders). */
    public function created(Order $order): void
    {
        // Skip POS: the owner is standing at the counter, they made it themselves.
        if ($order->tenant_id && $order->channel !== 'pos') {
            \App\Jobs\NotifyOwnerNewOrder::dispatch($order->tenant_id, $order->id);
        }
    }

    /** When status changes (in the panel or by the bot), notify the customer. */
    public function updated(Order $order): void
    {
        if ($order->wasChanged('status') && $order->customer_phone) {
            SendOrderStatusNotification::dispatch($order->tenant_id, $order->id, $order->status);
        }
    }
}
