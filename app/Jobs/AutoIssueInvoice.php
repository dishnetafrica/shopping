<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Issue a numbered invoice for an order that just reached "Delivered", off the request path so the
 * PDF render never slows the status change. Idempotent (InvoiceService returns the existing invoice
 * if one already exists for the order), so retries / double-fires are harmless.
 */
class AutoIssueInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId, public int $orderId) {}

    public function handle(TenantContext $ctx, InvoiceService $svc): void
    {
        $t = Tenant::find($this->tenantId);
        if (! $t) return;
        if (! (bool) $t->setting('invoice_auto_on_delivery', true)) return;
        if (! class_exists(\Dompdf\Dompdf::class)) return;

        $ctx->set($t->id);
        $order = Order::where('tenant_id', $t->id)->find($this->orderId);
        if (! $order) return;

        try {
            $svc->issueForOrder($t, $order, 'auto');
        } catch (\Throwable $e) {
            \Log::warning('AutoIssueInvoice failed: ' . $e->getMessage());
        }
    }
}
