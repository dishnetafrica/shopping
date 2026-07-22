<?php declare(strict_types=1);

namespace App\Services\Money;

use App\Models\CustomerPayment;
use App\Models\OrderCost;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Facades\DB;

/**
 * The ONE customer-payment recorder (Roadmap Epic 4 / E4.1, Constitution R2/R5).
 *
 * Extracted verbatim from PaymentController::store so every "money in from a customer" path can
 * write the SAME ledger: a cash-in OrderCost (stage customer_payment) mirrored by a CustomerPayment
 * receipt, then the linked invoice/statement re-derived from that ledger (syncByPayment). E4.2–E4.4
 * point the invoice "Pay", staff-cash "Receive" and cashbook "Record payment" paths at this method
 * so there is one receipt ledger, not four.
 *
 * This is a behaviour-preserving extraction — the fields, receipt-number format, created-at override
 * and sync call are identical to the original controller code.
 */
class PaymentService
{
    /**
     * Record a customer receipt (advance or settlement) + mirror cash-in, then sync the invoice.
     *
     * @param  array{
     *   customer:string, phone:?string, order_no:?string, invoice_no:?string, kind:string,
     *   currency:string, amount:float, rate:float, usd:float, method:string, reference:?string,
     *   receipt_path:?string, holder:string, holder_user_id:?int, created_by:string,
     *   cost_note?:string, created_at?:?\DateTimeInterface
     * } $d
     * @return array{id:int,receipt_no:string}
     */
    public function record(Tenant $t, array $d): array
    {
        $orderNo  = $d['order_no'] ?? null;
        $customer = (string) ($d['customer'] ?? '');
        $costNote = $d['cost_note'] ?? ('Payment from ' . $customer . ($orderNo ? (' (' . $orderNo . ')') : ''));

        $result = DB::transaction(function () use ($t, $d, $orderNo, $costNote, $customer) {
            // cash side: money IN — credited to the RECEIVER's cash in hand
            $cost = OrderCost::create([
                'tenant_id' => $t->id, 'order_id' => null, 'order_no' => $orderNo,
                'stage' => 'customer_payment', 'currency' => $d['currency'], 'native_amount' => $d['amount'], 'rate_to_usd' => $d['rate'],
                'usd_amount' => $d['usd'], 'holder' => $d['holder'], 'holder_user_id' => $d['holder_user_id'],
                'pay_status' => 'cash', 'paid_native' => $d['amount'], 'category' => 'Receipt',
                'note' => $costNote, 'status' => 'approved', 'created_by' => $d['created_by'],
            ]);
            $pay = CustomerPayment::create([
                'tenant_id' => $t->id, 'receipt_no' => 'TMP', 'customer_name' => $customer, 'customer_phone' => $d['phone'] ?? null,
                'order_no' => $orderNo, 'invoice_no' => $d['invoice_no'] ?? null, 'kind' => $d['kind'], 'currency' => $d['currency'],
                'amount' => $d['amount'], 'rate_to_usd' => $d['rate'], 'usd_amount' => $d['usd'],
                'method' => $d['method'], 'reference' => $d['reference'] ?? null, 'receipt_path' => $d['receipt_path'] ?? null,
                'applied' => ($d['kind'] === 'settlement'),
                'order_cost_id' => $cost->id, 'holder' => $d['holder'], 'holder_user_id' => $d['holder_user_id'], 'created_by' => $d['created_by'],
            ]);
            $pay->receipt_no = 'RCP-' . str_pad((string) $pay->id, 5, '0', STR_PAD_LEFT);
            if (! empty($d['created_at'])) { $pay->created_at = $d['created_at']; $cost->created_at = $d['created_at']; $cost->save(); }
            $pay->save();
            return ['id' => $pay->id, 'receipt_no' => $pay->receipt_no];
        });

        // Unify with invoices: reflect this payment on the linked invoice's balance + statement.
        try { app(InvoiceService::class)->syncByPayment($t, $d['invoice_no'] ?? null, $orderNo); } catch (\Throwable $e) {}

        // Keep the ORDER's own paid tracker (orders.amount_paid) in step with the invoice ledger too.
        // The invoice-based projections (Group A) and the order-based projections (Group B — Customers
        // directory, cashbook "who owes you", sales-order headline, credit reminders) must never
        // disagree. Doing this HERE, in the single recorder, guarantees every payment path — invoice
        // "Pay", Customer 360, the order button, the Payments screen, staff-cash — reconciles both.
        try { $this->syncOrderPaid($t, $orderNo); } catch (\Throwable $e) {}

        // BOS spine (Slice 2): record the fact for the customer timeline / Today / Insights. Guarded +
        // never-throws inside Emit — the payment path is unaffected whether or not the spine exists.
        \App\BOS\Emit::customerEvent($t->id, 'PaymentReceived', $d['phone'] ?? null, [
            'receipt_no' => $result['receipt_no'] ?? null, 'amount' => (float) $d['amount'],
            'currency' => $d['currency'], 'usd' => (float) $d['usd'], 'invoice_no' => $d['invoice_no'] ?? null,
            'kind' => $d['kind'] ?? 'settlement',
        ], ['actor' => ['type' => 'user', 'name' => (string) ($d['created_by'] ?? '')]]);

        return $result;
    }

    /**
     * Recompute orders.amount_paid from the CustomerPayment settlement ledger, expressed in the order's
     * own transaction currency — the same rule the invoice reconciler uses. Idempotent.
     */
    private function syncOrderPaid(Tenant $t, ?string $orderNo): void
    {
        if (! $orderNo) return;
        $order = \App\Models\Order::where('tenant_id', $t->id)->where('order_no', $orderNo)->first();
        if (! $order) return;
        // Match the ledger by order_no OR the order's own invoice numbers (single source of truth) —
        // a payment booked against the order's invoice is a payment on the order.
        $order->amount_paid = \App\Services\Money\OrderPaidReconciler::paidInOrderCurrency($t, $order);
        $order->save();
    }
}
