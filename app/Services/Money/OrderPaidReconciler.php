<?php declare(strict_types=1);

namespace App\Services\Money;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;

/**
 * Single source of truth for "how much has this ORDER been paid", expressed in the order's own
 * transaction currency.
 *
 * WHY THIS EXISTS (reconciliation root cause #2):
 * A receipt taken via the invoice "Pay" button (or the Customer-360 record-payment path) writes a
 * CustomerPayment carrying only `invoice_no` — no `order_no`. The old order-paid recompute matched
 * the ledger by `order_no` ONLY, so orders.amount_paid stayed stale whenever a payment was booked
 * against the order's invoice. That is exactly why the order-based (Group B) receivable — Customers
 * directory, cashbook "who owes you", sales-order headline, credit reminders — disagreed with the
 * invoice-based (Group A) receivable for the same customer even though the invoice ledger was correct.
 *
 * The fix: match the settlement ledger to an order by EITHER its order_no OR any of the order's own
 * (non-void) invoice numbers. A single SQL OR returns each payment row once, so there is no
 * double-counting when a row happens to carry both. Every place that recomputes orders.amount_paid
 * (PaymentService, PaymentController CRUD, resync-balances, reconcile-audit) now routes through here,
 * so the two projection families can never drift apart again.
 */
final class OrderPaidReconciler
{
    /**
     * Paid-to-date on the order, in the order's transaction currency. Read-only, idempotent.
     * Returns 0.0 when the order can be matched to nothing (no order_no and no live invoice) —
     * never an unfiltered tenant-wide sum.
     */
    public static function paidInOrderCurrency(Tenant $t, Order $order): float
    {
        $orderNo = trim((string) ($order->order_no ?? ''));

        // The order's own LIVE invoice numbers — a payment against any of these is a payment on the order.
        $invNos = Invoice::where('tenant_id', $t->id)
            ->where('order_id', $order->id)
            ->where('status', '!=', 'void')
            ->pluck('invoice_no')
            ->map(fn ($n) => trim((string) $n))
            ->filter(fn ($n) => $n !== '')
            ->unique()->values()->all();

        // Nothing to match against → paid is 0 (guard against an unfiltered OR selecting the whole tenant).
        if ($orderNo === '' && empty($invNos)) {
            return 0.0;
        }

        $oc   = SalesBaseCurrency::txnCurrency($order, $t);
        $base = SalesBaseCurrency::baseCurrency($t);
        $fx   = Fx::forTenant($t);

        $rows = CustomerPayment::where('tenant_id', $t->id)
            ->where('kind', 'settlement')->where('applied', true)
            ->where(function ($w) use ($orderNo, $invNos) {
                if ($orderNo !== '') { $w->where('order_no', $orderNo); }
                if (! empty($invNos)) { $w->orWhereIn('invoice_no', $invNos); }
            })
            ->get();

        $paid = 0.0;
        foreach ($rows as $p) {
            if (strtoupper((string) $p->currency) === $oc) {
                $paid += (float) $p->amount;
            } elseif ($oc === $base) {
                $paid += (float) $p->usd_amount;
            } else {
                $paid += (float) $p->usd_amount * ($fx->ratePerUsd($oc) ?: 1);
            }
        }

        return round($paid, 2);
    }
}
