<?php

namespace App\Jobs;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Finance\CustomerBillingNotifier;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * v4.25 — WhatsApp the CUSTOMER their invoice, or their receipt with the running balance.
 *
 * Queued on purpose: rendering a PDF and calling the WhatsApp API must never sit inside the database
 * transaction that issues an invoice or records a payment. If this job dies, the money records are
 * already safe and correct — only the courtesy message is lost.
 *
 * kind: 'invoice' (id = invoices.id) | 'receipt' (id = customer_payments.id)
 */
class NotifyCustomerBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId, public string $kind, public int $id) {}

    public function handle(TenantContext $ctx, CustomerBillingNotifier $notifier): void
    {
        try {
            $ctx->set($this->tenantId);
            $t = Tenant::find($this->tenantId);
            if (! $t) {
                return;
            }

            if ($this->kind === 'invoice') {
                $inv = Invoice::with('items')->where('tenant_id', $t->id)->find($this->id);
                if ($inv) {
                    $notifier->invoiceIssued($t, $inv);
                }

                return;
            }

            if ($this->kind === 'receipt') {
                $pay = CustomerPayment::where('tenant_id', $t->id)->find($this->id);
                if ($pay) {
                    $notifier->paymentReceived($t, $pay);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('NotifyCustomerBilling failed: ' . $e->getMessage());
        }
    }
}
