<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Tenant;
use App\Services\Sales\OrderConfirmationService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * v4.43 — WhatsApp the customer the itemised order they are agreeing to.
 *
 * Queued for the same reason the billing messages are: calling the WhatsApp API must never sit
 * inside the save that confirms an order. If this job dies the order is still confirmed and the
 * team still ships — only the message is lost, and `stateFor()` will show the order as unconfirmed
 * so nobody mistakes silence for agreement.
 *
 * A console/queue run has no request-bound tenant, so TenantContext is set explicitly — the same
 * trap that once made `cashbook:digest` read $0.00 for every tenant.
 */
class NotifyOrderConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId, public int $orderId, public string $trigger = 'confirmed') {}

    public function handle(TenantContext $ctx, OrderConfirmationService $svc): void
    {
        try {
            $ctx->set($this->tenantId);
            $t = Tenant::find($this->tenantId);
            if (! $t) {
                return;
            }
            $o = Order::withoutGlobalScopes()->where('tenant_id', $t->id)->find($this->orderId);
            if (! $o) {
                return;
            }
            $svc->send($t, $o, $this->trigger);
        } catch (\Throwable $e) {
            \Log::warning('NotifyOrderConfirmation failed: ' . $e->getMessage());
        }
    }
}
