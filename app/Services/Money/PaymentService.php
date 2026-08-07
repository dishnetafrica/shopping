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
        // v4.53 — a settlement that names nothing is given something to settle.
        //
        // Record payment on a CUSTOMER (rather than on an invoice) sends no invoice number and no
        // order number, because at that moment nobody has picked one. What used to happen is that the
        // row landed as a settlement attached to nothing: it reduced no balance, and it was not held
        // credit either, so the money was recorded and then did nothing at all. Now it goes against
        // their oldest open invoice, which is what a bookkeeper would do, and any excess falls through
        // to the split below. A customer with nothing open is paying money onto their account, so the
        // receipt says exactly that.
        $this->resolveTarget($t, $d);

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

        // v4.53 — an overpayment becomes credit instead of disappearing.
        //
        // A USD 170 bill paid with USD 200 used to record one USD 200 settlement. The invoice balance
        // is clamped at zero (`max(0, total - paid)`), so the extra USD 30 could not show as negative
        // and nothing else held it: the cash was right and the customer's account was wrong. Rule R3
        // has said "any excess must become an advance/credit, never a negative balance" since it was
        // written, and was never enforced anywhere.
        //
        // It is enforced here, by construction rather than by rejection: the settlement leg is capped
        // at what is actually due and the remainder is written as held credit in the same breath. No
        // payment is ever refused for being too large, which matters — money that arrived must be
        // recorded, and a customer standing at the counter is not the moment for a validation error.
        //
        // Deliberately in THIS method and not in a caller: it is the one recorder every path goes
        // through, so the till, the invoice screen, Customer 360, staff cash and the bot all get the
        // same treatment without any of them knowing about it.
        // splitExcess answers one question: of this receipt, how much settles the invoice and how much
        // is held? It returns settle = amount, credit = 0 whenever there is nothing to split, so the
        // ordinary payment path below is byte-for-byte what it always was.
        $split = $this->splitExcess($t, $d);
        if ($split['settle'] <= 0.005 && $split['credit'] > 0.005) {
            $d['kind'] = 'advance';                    // nothing was due — the whole receipt is credit
        }

        $result = DB::transaction(function () use ($t, $d, $orderNo, $costNote, $customer, $idem, $split) {
            // cash side: money IN — credited to the RECEIVER's cash in hand
            $cost = OrderCost::create([
                'tenant_id' => $t->id, 'order_id' => null, 'order_no' => $orderNo,
                'stage' => 'customer_payment', 'currency' => $d['currency'], 'native_amount' => $d['amount'], 'rate_to_usd' => $d['rate'],
                'usd_amount' => $d['usd'], 'holder' => $d['holder'], 'holder_user_id' => $d['holder_user_id'],
                'pay_status' => 'cash', 'paid_native' => $d['amount'], 'category' => 'Receipt',
                'note' => $costNote, 'status' => 'approved', 'created_by' => $d['created_by'],
            ]);
            // The FIRST leg. Normally the whole receipt; when an overpayment was split, only the part
            // that settles the invoice. Note the cash row above is the full amount either way — one
            // handover of money, one cash entry. The payment rows are the allocation of it, which is
            // a different question and deserves its own rows.
            $legAmount = $split['credit'] > 0.005 ? $split['settle'] : (float) $d['amount'];
            $pay = CustomerPayment::create([
                'tenant_id' => $t->id, 'receipt_no' => 'TMP', 'customer_name' => $customer, 'customer_phone' => $d['phone'] ?? null,
                'order_no' => $orderNo, 'invoice_no' => $d['invoice_no'] ?? null, 'kind' => $d['kind'], 'currency' => $d['currency'],
                'amount' => $legAmount, 'rate_to_usd' => $d['rate'], 'usd_amount' => self::usdFor($d, $legAmount),
                'method' => $d['method'], 'reference' => $d['reference'] ?? null, 'receipt_path' => $d['receipt_path'] ?? null,
                'applied' => ($d['kind'] === 'settlement'),
                'order_cost_id' => $cost->id, 'holder' => $d['holder'], 'holder_user_id' => $d['holder_user_id'], 'created_by' => $d['created_by'],
                'idempotency_key' => $idem,
            ]);
            $pay->receipt_no = 'RCP-' . str_pad((string) $pay->id, 5, '0', STR_PAD_LEFT);
            if (! empty($d['created_at'])) { $pay->created_at = $d['created_at']; $cost->created_at = $d['created_at']; $cost->save(); }
            $pay->save();
            $out = ['id' => $pay->id, 'receipt_no' => $pay->receipt_no];

            // The SECOND leg, when the customer handed over more than was due: the remainder, held.
            // Carries no invoice number, because it is settling nothing yet, and no order number,
            // because a stale one would let a later sync credit it to an invoice it never paid.
            if ($split['credit'] > 0.005 && $legAmount > 0.005) {
                $credit = CustomerPayment::create([
                    'tenant_id' => $t->id, 'receipt_no' => 'TMP', 'customer_name' => $customer, 'customer_phone' => $d['phone'] ?? null,
                    'order_no' => null, 'invoice_no' => null, 'kind' => 'advance', 'currency' => $d['currency'],
                    // The remainder of the USD figure, not a second proportional calculation of it —
                    // so the two legs always add back to exactly what the caller passed in, with no
                    // stray cent appearing or vanishing at the rounding boundary.
                    'amount' => $split['credit'], 'rate_to_usd' => $d['rate'],
                    'usd_amount' => round((float) $d['usd'] - self::usdFor($d, $legAmount), 2),
                    'method' => $d['method'], 'reference' => $d['reference'] ?? null, 'receipt_path' => $d['receipt_path'] ?? null,
                    'applied' => false,
                    'order_cost_id' => $cost->id, 'holder' => $d['holder'], 'holder_user_id' => $d['holder_user_id'], 'created_by' => $d['created_by'],
                    // A distinct key: sharing one would make the replay check above return whichever
                    // row the database happened to hand back first.
                    'idempotency_key' => $idem ? $idem . ':credit' : null,
                ]);
                $credit->receipt_no = 'RCP-' . str_pad((string) $credit->id, 5, '0', STR_PAD_LEFT);
                if (! empty($d['created_at'])) { $credit->created_at = $d['created_at']; }
                $credit->save();
                $out['credit_id'] = $credit->id;
                $out['credit_receipt_no'] = $credit->receipt_no;
                $out['credit_amount'] = $split['credit'];
                $out['credit_currency'] = strtoupper((string) $d['currency']);
            }

            return $out;
        });

        // Unify with invoices + keep the order's paid tracker in step. A caller may pass 'sync' => false
        // to preserve its own gating (3C: staff-cash honours the tenant's pay_unify flag). Every other
        // caller omits it → default true → always reconcile, exactly as before.
        if ($d['sync'] ?? true) {
            // Reflect this payment on the linked invoice's balance + statement.
            try { app(InvoiceService::class)->syncByPayment($t, $d['invoice_no'] ?? null, $orderNo); } catch (\Throwable $e) {}

            // Keep the ORDER's own paid tracker (orders.amount_paid) in step with the invoice ledger too.
            // The invoice-based projections (Group A) and the order-based projections (Group B — Customers
            // directory, cashbook "who owes you", sales-order headline, credit reminders) must never
            // disagree. Doing this HERE, in the single recorder, guarantees every payment path — invoice
            // "Pay", Customer 360, the order button, the Payments screen, staff-cash — reconciles both.
            try { $this->syncOrderPaid($t, $orderNo); } catch (\Throwable $e) {}
        }

        // v4.25 — the customer's side of the loop: receipt PDF + what they paid now, what they have
        // paid in total against the invoice, and what is still due. Dispatched AFTER the sync above so
        // the balance quoted to them is the real one. Never inside the transaction, never fatal:
        // a payment that is recorded must stay recorded even if WhatsApp is unreachable.
        if (empty($result['duplicate'])) {
            try {
                \App\Jobs\NotifyCustomerBilling::dispatch($t->id, 'receipt', (int) $result['id']);
            } catch (\Throwable $e) {
                \Log::warning('customer receipt notice not queued: ' . $e->getMessage());
            }
        }

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
                // v4.53 — R3 finally gets the number it was written to check. It used to be called
                // with no balance at all, so `amount <= balance` was `amount <= 0` and every ordinary
                // payment logged a violation; the warning meant nothing and nobody read it.
                //
                // A receipt that was SPLIT is reported as an advance here, and deliberately. R3's
                // subject is "a settlement applied to an invoice", and a split receipt is not one —
                // it is a settlement and a held remainder. The settlement leg it produced satisfies
                // R3 by construction, because the split capped it at exactly what was due. Reporting
                // the whole 200 as a settlement against a 170 balance would raise an alarm about the
                // very mechanism that prevents the problem.
                'kind'            => $split['credit'] > 0.005 ? 'advance' : ($d['kind'] ?? 'settlement'),
                'balance'         => $split['due'] ?? (float) $d['amount'],
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
    /**
     * v4.53 — of this receipt, how much settles the invoice and how much is held as credit?
     *
     * Returns `settle = the whole amount, credit = 0` for every case it cannot judge, so the ordinary
     * payment path is untouched. It declines to judge in four situations, each on purpose:
     *
     *  - the caller already said `advance` — they meant to hold it, and this is not the place to argue
     *  - no invoice resolves — there is nothing to overpay, so there is no excess to find
     *  - the payment and the invoice are in different currencies — the difference between them is an
     *    exchange rate, not a credit, and inventing one here would be worse than leaving it whole
     *  - anything at all threw — a payment that arrived must be recorded, and a clever feature is
     *    never worth losing one over
     *
     * `due` comes back whenever an invoice was found, whether or not there was an excess, because R3
     * downstream needs a real number to check against rather than a zero that makes it meaningless.
     *
     * @return array{settle:float, credit:float, due:?float}
     */
    private function splitExcess(Tenant $t, array $d): array
    {
        $amount = round((float) ($d['amount'] ?? 0), 2);
        $none   = ['settle' => $amount, 'credit' => 0.0, 'due' => null];

        if ($amount <= 0.005) {
            return $none;
        }
        if (strtolower((string) ($d['kind'] ?? 'settlement')) !== 'settlement') {
            return $none;
        }
        if (($d['hold_excess'] ?? true) === false) {
            return $none;                         // an explicit opt-out, for a caller that knows better
        }

        try {
            $inv = $this->targetInvoice($t, $d);
            if (! $inv) {
                return $none;
            }
            $icur = strtoupper((string) SalesBaseCurrency::txnCurrency($inv, $t));
            if ($icur !== strtoupper((string) ($d['currency'] ?? ''))) {
                return $none;
            }

            app(InvoiceService::class)->syncPaidFromPayments($inv);   // judge against a fresh balance
            $inv->refresh();

            // A discount given at collection reduces what is owed, so it reduces what counts as excess.
            // Without this, "pay 200, write off 20 of a 190 bill" would hold 10 instead of 30.
            $discount = max(0.0, round((float) ($d['discount'] ?? 0), 2));
            $due = round(max(0.0, (float) $inv->balance - $discount), 2);

            // The arithmetic itself lives in CreditOnAccount, with the arithmetic that spends credit
            // again later. One place to read, one place to test, no chance of the two disagreeing
            // about what a rounding step means.
            return CreditOnAccount::split($amount, $due) + ['due' => $due];
        } catch (\Throwable $e) {
            \Log::warning('overpayment split skipped, payment recorded whole: ' . $e->getMessage());

            return $none;
        }
    }

    /**
     * v4.53 — give an untargeted settlement something to settle, or admit it is credit.
     *
     * Only acts when the caller named neither an invoice nor an order AND said `settlement`. A caller
     * that asked for an advance meant it. Oldest open invoice first, by issue date then id, which is
     * both the conventional allocation and the one a customer can follow on their statement.
     *
     * Mutates `$d` by reference on purpose: everything downstream — the cash note, the payment row,
     * the split, the sync, the receipt — should see one consistent decision, made once, here.
     */
    private function resolveTarget(Tenant $t, array &$d): void
    {
        if (strtolower((string) ($d['kind'] ?? 'settlement')) !== 'settlement') {
            return;
        }
        if (trim((string) ($d['invoice_no'] ?? '')) !== '' || trim((string) ($d['order_no'] ?? '')) !== '') {
            return;
        }
        $digits = preg_replace('/[^0-9]/', '', (string) ($d['phone'] ?? ''));
        if ($digits === '') {
            return;                      // no way to know whose money this is; leave it exactly as sent
        }

        try {
            $inv = \App\Models\Invoice::where('tenant_id', $t->id)
                ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
                ->where('status', '!=', 'void')
                ->where('balance', '>', 0.005)
                ->orderBy('issued_at')->orderBy('id')
                ->first();

            if ($inv) {
                $d['invoice_no'] = $inv->invoice_no;

                return;
            }

            // Nothing open. This is not a settlement of anything — it is money on account.
            $d['kind'] = 'advance';
        } catch (\Throwable $e) {
            \Log::warning('payment target not resolved, recorded as sent: ' . $e->getMessage());
        }
    }

    /** The invoice this receipt is against, by number or through its order. Same resolution as syncByPayment. */
    private function targetInvoice(Tenant $t, array $d): ?\App\Models\Invoice
    {
        $invoiceNo = trim((string) ($d['invoice_no'] ?? ''));
        if ($invoiceNo !== '') {
            $inv = \App\Models\Invoice::where('tenant_id', $t->id)->where('invoice_no', $invoiceNo)
                ->where('status', '!=', 'void')->first();
            if ($inv) {
                return $inv;
            }
        }
        $orderNo = trim((string) ($d['order_no'] ?? ''));
        if ($orderNo !== '') {
            $order = \App\Models\Order::where('tenant_id', $t->id)->where('order_no', $orderNo)->first();
            if ($order) {
                return \App\Models\Invoice::where('tenant_id', $t->id)->where('order_id', $order->id)
                    ->where('status', '!=', 'void')->orderByDesc('id')->first();
            }
        }

        return null;
    }

    /**
     * The USD value of one leg of a split receipt.
     *
     * Proportional to the leg, never recomputed from a rate: `rate_to_usd` is stored with different
     * conventions in different corners of this system, and a leg that disagrees with its own receipt
     * total by a rounding step is a reconciliation bug six months later. The two legs of a split
     * always add back to the USD figure the caller passed in.
     */
    private static function usdFor(array $d, float $leg): float
    {
        return CreditOnAccount::usdLeg($leg, (float) ($d['amount'] ?? 0), (float) ($d['usd'] ?? 0));
    }

    /** Public since v4.53 so applying held credit re-derives the order tracker the same way a payment does. */
    public function syncOrderPaid(Tenant $t, ?string $orderNo): void
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
