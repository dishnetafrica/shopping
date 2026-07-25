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
        $costNote = $d['cost_note'] ?? self::describeNote($d);

        // BOS Slice 6 — idempotent replay: a payment queued offline may POST more than once on reconnect.
        // If we've already recorded this client key, return that same receipt instead of a second one.
        $idem = ! empty($d['idempotency_key']) ? (string) $d['idempotency_key'] : null;
        if ($idem !== null) {
            $seen = CustomerPayment::withoutGlobalScopes()
                ->where('tenant_id', $t->id)->where('idempotency_key', $idem)->first();
            if ($seen) return ['id' => $seen->id, 'receipt_no' => $seen->receipt_no, 'duplicate' => true];
        }

        $result = DB::transaction(function () use ($t, $d, $orderNo, $costNote, $customer, $idem) {
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
                'idempotency_key' => $idem,
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

        // Optional discount at collection ("give USD X off, close the invoice"). Applied HERE in the one
        // recorder — after the payment records (fresh only; a duplicate returned early above) — so EVERY
        // payment path (customer-timeline Collect, invoice "Pay", the order button, Payments, staff-cash)
        // gets it consistently. It reduces the invoice via its OWN discount field AND mirrors the same
        // reduction on the linked order's total, so invoice-based and order-based views agree. Capped so it
        // can never write off more than what's still due. Guarded + never-throws: a discount hiccup can
        // never affect the payment that was just recorded.
        $discount = round((float) ($d['discount'] ?? 0), 2);
        if ($discount > 0 && ! empty($d['invoice_no'])) {
            try {
                $inv = \App\Models\Invoice::where('tenant_id', $t->id)->where('invoice_no', (string) $d['invoice_no'])
                    ->where('status', '!=', 'void')->first();
                if ($inv) {
                    $svc = app(InvoiceService::class);
                    $svc->syncPaidFromPayments($inv);              // balance now reflects the payment just taken
                    $inv->refresh();
                    $dd = min($discount, round((float) $inv->balance, 2));  // never more than what's left
                    if ($dd > 0) {
                        $inv->discount = round((float) $inv->discount + $dd, 2);
                        $inv->total    = round((float) $inv->total - $dd, 2);
                        $inv->save();
                        $svc->syncPaidFromPayments($inv);          // recompute balance/status → closes it
                        $ord = $inv->order_id
                            ? \App\Models\Order::where('tenant_id', $t->id)->find((int) $inv->order_id)
                            : \App\Models\Order::where('tenant_id', $t->id)->where('order_no', $orderNo)->first();
                        if ($ord) {
                            $ord->total = round(max(0, (float) $ord->total - $dd), 2);
                            $ord->save();
                            $this->syncOrderPaid($t, (string) $ord->order_no);
                        }
                        $result['discount_applied'] = true;
                    }
                }
            } catch (\Throwable $e) { \Log::warning('collect discount failed: ' . $e->getMessage()); }
        }

        // BOS spine — Ticket 3B: the money-in fact now flows through the ONE money.receive capability
        // (dual-write). The authoritative rows above are unchanged; only the EVENT moves. Guarded so an
        // emit can never break the payment path (ADR-012). R3 is OBSERVE-ONLY here — a violation is
        // logged as evidence but never blocks the payment or the event; the fact still lands (exact
        // parity with the prior unconditional emit). Fatal R3 arrives at the front door in Ticket 3C.
        //
        // Resolve the SAME customer subject the prior Emit bridge used, so the Customer-360 timeline is
        // byte-for-byte unchanged (transitional glue; subject resolution moves into the capability in 3C).
        $subjectId = 0;
        $digits = preg_replace('/[^0-9]/', '', (string) ($d['phone'] ?? ''));
        if ($digits !== '') {
            $subjectId = (int) (\App\Models\CustomerProfile::withoutGlobalScopes()
                ->where('tenant_id', $t->id)->where('phone', $digits)->value('id') ?? 0);
        }

        $emitted = false;
        try {
            $res = (new \App\Kernel\Capabilities\Money\ReceiveMoney())->run([
                'customer'        => $customer,
                'phone'           => $d['phone'] ?? null,
                'amount'          => (float) $d['amount'],
                'currency'        => $d['currency'],
                'usd'             => (float) $d['usd'],
                'rate'            => (float) ($d['rate'] ?? 0),
                'method'          => $d['method'] ?? null,
                'reference'       => $d['reference'] ?? null,
                'invoice_no'      => $d['invoice_no'] ?? null,
                'order_no'        => $orderNo,
                'kind'            => $d['kind'] ?? 'settlement',
                'holder'          => $d['holder'] ?? null,
                'created_by'      => $d['created_by'] ?? '',
                'receipt_no'      => $result['receipt_no'] ?? null,
                'tenant_id'       => $t->id,
                'subject_type'    => 'customer',
                'subject_id'      => $subjectId,
                'idempotency_key' => $idem ?: ($result['receipt_no'] ?? null),
            ]);
            $emitted = ! empty($res['ok']);
            if (! $emitted) {
                \Log::warning('money.receive did not emit (3B schema): ' . implode('; ', $res['errors'] ?? []));
            }
        } catch (\App\BOS\Rules\RuleViolation $e) {
            \Log::warning('money.receive R3 observed (3B dual-write, not enforced): ' . $e->getMessage());
        } catch (\Throwable $e) {
            \Log::warning('money.receive emit error (3B, payment unaffected): ' . $e->getMessage());
        }

        if (! $emitted) {
            // Parity fallback — mirror the prior unconditional, guarded emit so no fact is ever lost.
            \App\BOS\Emit::customerEvent($t->id, 'PaymentReceived', $d['phone'] ?? null, [
                'receipt_no' => $result['receipt_no'] ?? null, 'amount' => (float) $d['amount'],
                'currency' => $d['currency'], 'usd' => (float) $d['usd'], 'invoice_no' => $d['invoice_no'] ?? null,
                'kind' => $d['kind'] ?? 'settlement',
            ], ['actor' => ['type' => 'user', 'name' => (string) ($d['created_by'] ?? '')]]);
        }

        return $result;
    }

    /**
     * The ONE cashbook description for a customer receipt — used by every money-in path so the
     * cashbook line reads the same everywhere and carries all the useful context, not just the
     * customer name. Reads whatever the caller has: customer, what it's allocated to (invoice first,
     * matching the receipt, else the order, else "on account"), the method, and any reference.
     *
     * Examples:
     *   Payment from Shop no 58 · Invoice INV-2026-0025 · Cash
     *   Payment from Adel Al-Jili · Order ORD-59 · Bank · ref MTN 771…
     *   Advance from Shop no 58 · on account · Cash
     *
     * Display-only text (nothing parses it), so it is safe to enrich without touching any money value.
     */
    public static function describeNote(array $d): string
    {
        $customer = trim((string) ($d['customer'] ?? ''));
        $invoice  = trim((string) ($d['invoice_no'] ?? ''));
        $order    = trim((string) ($d['order_no'] ?? ''));
        $method   = trim((string) ($d['method'] ?? ''));
        $ref      = trim((string) ($d['reference'] ?? ''));
        $kind     = (string) ($d['kind'] ?? 'settlement');

        $parts = [];
        $parts[] = ($kind === 'advance' ? 'Advance from ' : 'Payment from ') . ($customer !== '' ? $customer : 'customer');

        // What the money was booked against — invoice first (mirrors the receipt), else the order, else on account.
        if ($invoice !== '') {
            $parts[] = 'Invoice ' . $invoice . ($order !== '' ? ' (' . $order . ')' : '');
        } elseif ($order !== '') {
            $parts[] = 'Order ' . $order;
        } else {
            $parts[] = 'on account';
        }

        if ($method !== '') $parts[] = $method;
        if ($ref !== '') {
            $ref = preg_replace('/\s+/', ' ', $ref);
            if (mb_strlen($ref) > 48) $ref = mb_substr($ref, 0, 47) . '…';
            $parts[] = 'ref ' . $ref;
        }

        return implode(' · ', $parts);
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
