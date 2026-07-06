<?php declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\Order;
use App\Models\Product;
use CloudBSS\Infra\SalesLedger;
use CloudBSS\Kernel\Money\Currency;
use Illuminate\Support\Facades\Log;

/**
 * Bridges a ShopBot Order to the CloudBSS event-sourced ledger. When an order is delivered/complete
 * (revenue recognized), it posts a sale: AR + Sales + output VAT + COGS/Inventory, per the tenant's
 * accounting policy. Best-effort and idempotent — it never breaks the order flow, and re-firing on
 * the same order is a no-op (SalesLedger keys on the order id).
 *
 * Per-tenant opt-in via settings:
 *   ledger_enabled   (bool)  turn posting on for this tenant
 *   ledger_tenant    (string) kernel tenant id  (e.g. 't_ug' for Patronics)
 *   ledger_entity    (string) kernel entity id  (e.g. 'e1')
 *   prices_include_vat, quote_vat_pct   → to back out net revenue from the VAT-inclusive total
 *   credit_terms_days (int)  due date = delivered date + N days (0 = due immediately)
 */
class OrderLedgerService
{
    public function __construct(private ?SalesLedger $sales = null) {}

    /** Post a delivered order to the ledger. Safe to call more than once. */
    public function postSale(Order $order): void
    {
        try {
            $tenant = $order->tenant;
            if (! $tenant || ! $this->enabled($tenant)) return;
            if (! $this->isComplete($order)) return;

            $sales = $this->sales ?? app(SalesLedger::class);

            $cur    = (string) ($tenant->setting('currency', 'UGX'));
            $factor = Currency::of($cur)->subunit();                 // UGX=1, USD=100, …
            $gross  = (float) $order->total;

            // Net (ex-VAT) revenue: back VAT out of the inclusive total when the tenant prices inc. VAT.
            $vatPct = (float) $tenant->setting('quote_vat_pct', 0);
            $incVat = (bool) $tenant->setting('prices_include_vat', false);
            $net    = ($incVat && $vatPct > 0) ? $gross / (1 + $vatPct / 100) : $gross;

            $revenueMinor = (int) round($net * $factor);
            $costMinor    = (int) round($this->cogs($order) * $factor);
            if ($revenueMinor <= 0) return;                          // nothing to post

            $ledgerTenant = (string) $tenant->setting('ledger_tenant', 't_ug');
            $ledgerEntity = (string) $tenant->setting('ledger_entity', 'e1');
            $terms        = (int) $tenant->setting('credit_terms_days', 0);
            $delivered    = $order->delivered_at ?: now();
            $due          = $delivered->copy()->addDays(max(0, $terms))->toDateString();

            $sales->recordSale(
                tenantId:     $ledgerTenant,
                entityId:     $ledgerEntity,
                saleId:       (string) $order->id,
                revenueMinor: $revenueMinor,
                costMinor:    $costMinor,
                currency:     $cur,
                occurredAt:   $delivered->toDateTimeImmutable(),
                party:        (string) ($order->customer_phone ?: ('order_' . $order->id)),
                partyName:    (string) ($order->customer_name ?: ''),
                dueDate:      $due,
            );
        } catch (\Throwable $e) {
            // Accounting must never break selling. Log and move on; a nightly reconcile can catch gaps.
            Log::warning('OrderLedgerService.postSale failed for order ' . ($order->id ?? '?') . ': ' . $e->getMessage());
        }
    }

    private function enabled($tenant): bool
    {
        return (bool) $tenant->setting('ledger_enabled', false);
    }

    /** Revenue is recognized once the order is operationally complete (delivered or confirmed). */
    private function isComplete(Order $order): bool
    {
        return $order->delivered_at !== null || $order->customer_confirmed_at !== null;
    }

    /** COGS from product base_price × qty. Returns 0 if cost isn't tracked (entry still balances). */
    private function cogs(Order $order): float
    {
        try {
            $items = $order->items()->get(['product_id', 'qty']);
            if ($items->isEmpty()) return 0.0;
            $ids   = $items->pluck('product_id')->filter()->unique()->all();
            if (! $ids) return 0.0;
            $cost  = Product::withoutGlobalScopes()->whereIn('id', $ids)->pluck('base_price', 'id');
            $total = 0.0;
            foreach ($items as $it) {
                $total += (float) ($cost[$it->product_id] ?? 0) * (float) $it->qty;
            }
            return $total;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
