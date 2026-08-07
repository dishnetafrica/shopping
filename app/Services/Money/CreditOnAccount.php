<?php declare(strict_types=1);

namespace App\Services\Money;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Facades\DB;

/**
 * v4.53 — money the customer has paid that is not yet settling anything.
 *
 * A customer with a USD 170 bill hands over USD 200 and says keep the rest. Before this, the extra
 * USD 30 was recorded as cash (correctly) and then vanished: the invoice balance is clamped at zero,
 * so it could not go negative, and nothing else tracked it. The customer's own statement told him he
 * owed nothing and was owed nothing.
 *
 * The model here is deliberately narrow, and it is the one the payments table already had:
 *
 *   HELD    kind = 'advance',    applied = false   — money received, not settling any invoice
 *   APPLIED kind = 'settlement', applied = true    — money received, settling a named invoice
 *
 * A payment is always exactly one of those. Never both, never neither. Every screen that reports
 * credit reads the first state and every screen that reports an invoice balance reads the second,
 * which is what stops the two disagreeing.
 *
 * Applying credit MOVES a payment between those states. It never receives money again — no new cash
 * row is written, because no new cash arrived. That is the single most important property in this
 * file: get it wrong and every applied credit inflates the cash book by its own value.
 */
final class CreditOnAccount
{
    /** Amounts below this are rounding dust, not money. */
    private const EPS = 0.005;

    /**
     * What this customer is holding, per currency. Keyed by the currency it was received in —
     * credit is not silently converted, because the customer handed over particular notes and will
     * expect them back in the same money.
     *
     * @return array<string,float>
     */
    public static function heldFor(Tenant $t, ?string $phone): array
    {
        $held = [];
        foreach (self::rowsFor($t, $phone) as $p) {
            $cur = strtoupper((string) ($p->currency ?: ''));
            if ($cur === '') {
                continue;
            }
            $held[$cur] = round(($held[$cur] ?? 0) + (float) $p->amount, 2);
        }

        return array_filter($held, fn ($v) => $v > self::EPS);
    }

    /**
     * The held rows, OLDEST FIRST. Credit is spent in the order it was received, which is both the
     * conventional treatment and the one a customer can follow on a statement.
     *
     * @return \Illuminate\Support\Collection<int,CustomerPayment>
     */
    public static function rowsFor(Tenant $t, ?string $phone, ?string $currency = null)
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($digits === '') {
            return collect();          // a blank phone must match nothing, not everything
        }

        $q = CustomerPayment::where('tenant_id', $t->id)
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->where('kind', 'advance')
            ->where('applied', false)
            ->orderBy('id');

        if ($currency !== null) {
            $q->whereRaw('upper(coalesce(currency, \'\')) = ?', [strtoupper($currency)]);
        }

        return $q->get();
    }

    /**
     * PURE — how to spend a list of held amounts against an amount due, oldest first.
     *
     * Separated from the database on purpose: this is the part that must be provably right, and it
     * is the part a harness can hammer without a schema. Returns one entry per held row, in the same
     * order, so the caller can walk rows and plan together.
     *
     * @param  array<int,float>  $held  amounts, oldest first
     * @return array<int,array{take:float,remain:float}>
     */
    public static function plan(array $held, float $due): array
    {
        $due = round(max(0.0, $due), 2);
        $out = [];

        foreach ($held as $amt) {
            $amt = round(max(0.0, (float) $amt), 2);
            if ($due <= self::EPS || $amt <= self::EPS) {
                $out[] = ['take' => 0.0, 'remain' => $amt];
                continue;
            }
            $take = round(min($amt, $due), 2);
            $out[] = ['take' => $take, 'remain' => round($amt - $take, 2)];
            $due   = round($due - $take, 2);
        }

        return $out;
    }

    /**
     * PURE — how a single receipt divides between settling an invoice and being held.
     *
     * The other half of the same idea as plan(): that one spends credit, this one creates it. Kept
     * here rather than in PaymentService so both halves of the arithmetic can be tested in one place
     * without a database, a tenant, or a framework.
     *
     * @return array{settle:float, credit:float}
     */
    public static function split(float $amount, float $due): array
    {
        $amount = round(max(0.0, $amount), 2);
        $due    = round(max(0.0, $due), 2);

        if ($amount <= $due + self::EPS) {
            return ['settle' => $amount, 'credit' => 0.0];   // paid in full or less — nothing held
        }

        return ['settle' => $due, 'credit' => round($amount - $due, 2)];
    }

    /**
     * PURE — the USD value of one leg of a split receipt.
     *
     * Proportional to the leg and never recomputed from a stored rate: `rate_to_usd` follows
     * different conventions in different corners of this system. The caller takes the SECOND leg as
     * the remainder of the total rather than calling this twice, so the two legs always add back to
     * the figure that came in — no cent appears or disappears at the rounding boundary.
     */
    public static function usdLeg(float $leg, float $amount, float $usd): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || abs($leg - $amount) < self::EPS) {
            return round($usd, 2);
        }

        return round($usd * ($leg / $amount), 2);
    }

    /** What a plan spends in total. */
    public static function planTotal(array $plan): float
    {
        return round(array_sum(array_map(fn ($p) => (float) ($p['take'] ?? 0), $plan)), 2);
    }

    /**
     * Put this customer's held credit against an invoice, up to whichever is smaller: what they hold
     * or what the invoice still owes.
     *
     * ONLY credit held in the invoice's own transaction currency is used. Spending UGX credit on a
     * USD invoice is an FX decision with a rate and a date and someone's judgement in it, and this
     * service is not the place to make it silently. Credit in other currencies is reported back
     * untouched so the screen can say so.
     *
     * @return array{ok:bool, applied:float, currency:string, rows:int, message:string, other?:array}
     */
    public function applyTo(Tenant $t, Invoice $inv, ?float $max = null): array
    {
        $cur = strtoupper((string) SalesBaseCurrency::txnCurrency($inv, $t));
        $phone = (string) $inv->customer_phone;

        $svc = app(InvoiceService::class);
        $svc->syncPaidFromPayments($inv);          // decide against a fresh balance, not a stale one
        $inv->refresh();

        $balance = round((float) $inv->balance, 2);
        if ($balance <= self::EPS) {
            return ['ok' => false, 'applied' => 0.0, 'currency' => $cur, 'rows' => 0,
                    'message' => 'Invoice ' . $inv->invoice_no . ' has nothing left to pay.'];
        }

        $rows = self::rowsFor($t, $phone, $cur);
        if ($rows->isEmpty()) {
            $other = self::heldFor($t, $phone);
            unset($other[$cur]);

            return ['ok' => false, 'applied' => 0.0, 'currency' => $cur, 'rows' => 0, 'other' => $other,
                    'message' => $other
                        ? 'No credit held in ' . $cur . '. This customer holds ' . self::describe($other)
                          . ', which cannot be applied to a ' . $cur . ' invoice without an exchange decision.'
                        : 'This customer is not holding any credit.'];
        }

        $due = $max !== null ? round(min($balance, max(0.0, $max)), 2) : $balance;
        $plan = self::plan($rows->map(fn ($p) => (float) $p->amount)->all(), $due);
        $spend = self::planTotal($plan);
        if ($spend <= self::EPS) {
            return ['ok' => false, 'applied' => 0.0, 'currency' => $cur, 'rows' => 0,
                    'message' => 'There is nothing to apply.'];
        }

        $inv->loadMissing('order');
        $orderNo = (string) ($inv->order?->order_no ?? '');
        $touched = 0;

        DB::transaction(function () use ($rows, $plan, $inv, $orderNo, &$touched) {
            foreach ($rows as $idx => $p) {
                $take   = (float) ($plan[$idx]['take'] ?? 0);
                $remain = (float) ($plan[$idx]['remain'] ?? 0);
                if ($take <= self::EPS) {
                    continue;
                }
                $touched++;

                if ($remain <= self::EPS) {
                    // The whole held payment goes. Re-tag it in place — same money, new state.
                    self::settle($p, $inv, $orderNo);
                    continue;
                }

                // Part of it goes. The held row shrinks to what is left and a sibling settlement row
                // carries the applied part, sharing the SAME cash row: one receipt of money, two
                // allocations of it. Writing a new cash row here would invent money that never arrived.
                $origUsd  = round((float) $p->usd_amount, 2);
                $takenUsd = self::usdSlice($p, $take);      // read BEFORE the parent row is touched

                $sib = $p->replicate();
                $sib->kind            = 'settlement';
                $sib->applied         = true;
                $sib->invoice_no      = $inv->invoice_no;
                $sib->order_no        = $orderNo ?: null;
                $sib->amount          = round($take, 2);
                $sib->usd_amount      = $takenUsd;
                $sib->idempotency_key = null;               // a fresh row, not a replay of the old one
                $sib->receipt_no      = 'TMP';
                $sib->save();
                $sib->receipt_no = 'RCP-' . str_pad((string) $sib->id, 5, '0', STR_PAD_LEFT);
                $sib->save();

                // The remainder, not a second proportional sum: the two halves must add back to the
                // USD the row arrived with, or the cash book and the payments ledger drift by a cent
                // for every credit ever applied.
                $p->amount     = round($remain, 2);
                $p->usd_amount = round($origUsd - $takenUsd, 2);
                $p->save();
            }
        });

        // The invoice and its order re-derive from the payment rows, exactly as they do after a
        // normal payment. Nothing here computes a balance by hand.
        try { $svc->syncPaidFromPayments($inv->refresh()); } catch (\Throwable $e) {}
        if ($orderNo !== '') {
            try { app(PaymentService::class)->syncOrderPaid($t, $orderNo); } catch (\Throwable $e) {}
        }

        return ['ok' => true, 'applied' => $spend, 'currency' => $cur, 'rows' => $touched,
                'message' => 'Applied ' . $cur . ' ' . number_format($spend, $cur === 'USD' ? 2 : 0)
                             . ' of held credit to ' . $inv->invoice_no . '.'];
    }

    /** Move a held payment into the settled state against a named invoice. */
    private static function settle(CustomerPayment $p, Invoice $inv, string $orderNo): void
    {
        $p->kind       = 'settlement';
        $p->applied    = true;
        $p->invoice_no = $inv->invoice_no;
        // The OLD order_no must go. syncPaidFromPayments matches on invoice_no OR order_no, so a
        // stale order number would quietly credit this money to the earlier invoice as well.
        $p->order_no   = $orderNo !== '' ? $orderNo : null;
        $p->save();
    }

    /**
     * The USD value of a slice of a held payment — proportional to the slice, never recomputed from
     * a stored rate. `rate_to_usd` follows different conventions in different corners of this system;
     * a proportion of the row's own USD figure cannot disagree with the row it came from.
     */
    private static function usdSlice(CustomerPayment $p, float $slice): float
    {
        $amt = round((float) $p->amount, 2);

        return $amt > 0 ? round(((float) $p->usd_amount) * ($slice / $amt), 2) : 0.0;
    }

    /** "USD 30, UGX 50,000" — for telling someone what they are holding that cannot be spent here. */
    public static function describe(array $map): string
    {
        $parts = [];
        foreach ($map as $cur => $amt) {
            $parts[] = $cur . ' ' . number_format((float) $amt, $cur === 'USD' ? 2 : 0);
        }

        return $parts ? implode(', ', $parts) : '0';
    }
}
