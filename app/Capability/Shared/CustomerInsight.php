<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\CapabilityContext;
use App\Models\CustomerProfile;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Money\ReportingCurrency;
use Illuminate\Support\Carbon;

/**
 * Read-only customer intelligence for the 360. Derives real behaviour metrics — exposure, overdue,
 * aging, credit usage, on-time %, typical pay delay and a blended health score — from the live
 * orders / invoices / payments ledgers. Recomputes no money (reads model balances as-is) and writes
 * nothing. Fields the schema cannot back (rep, follow-up, last contact) come straight off the profile.
 */
final class CustomerInsight
{
    /** @return array<string,mixed> */
    public function for(CapabilityContext $ctx, CustomerProfile $c): array
    {
        $t = $ctx->tenantId();
        $digits = preg_replace('/[^0-9]/', '', (string) $c->phone);
        $now = Carbon::now();

        // All money metrics are consolidated into ONE currency (the report/base currency) so a
        // customer trading in mixed currencies never has UGX + USD naively summed into a wrong total.
        $rc = ReportingCurrency::forContext($ctx);
        $base = $rc->consolidationCurrency();
        // Orders/invoices with no currency tag are in the engine's LOCAL currency (UGX on a converted
        // basis), NOT the display base — otherwise a UGX total gets consolidated as if it were USD.
        $native = $ctx->nativeCurrency();
        $toBase = fn (float $amt, ?string $curc, ?string $date) => $rc->consolidate([[ 'amount' => $amt, 'currency' => $curc ?: $native, 'date' => $date ]])['amount'];

        // A blank phone must match NOTHING (an empty regexp target would otherwise match every
        // phone-less record in the tenant and wrongly attribute them to this customer).
        $byPhone = fn ($q) => $digits === '' ? $q->whereRaw('1=0')
            : $q->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits]);

        // --- orders: lifetime spend, count, last order (each order in its own sell currency) ---
        // Cancelled / rejected / void orders are not sales — exclude them so a cancelled order does not
        // inflate lifetime spend or the order count (matches the customers-list aggregate).
        $orders = $byPhone(Order::where('tenant_id', $t))
            ->whereRaw("lower(coalesce(status,'')) not in ('cancelled','rejected','void')")
            ->get(['total', 'sell_currency', 'created_at']);
        $orderCount = $orders->count();
        $spend = (float) $orders->sum(fn ($o) => $toBase((float) $o->total, (string) ($o->sell_currency ?: $native), $o->created_at ? Carbon::parse($o->created_at)->format('Y-m-d') : null));
        $lastOrderAt = $orders->max('created_at') ? Carbon::parse($orders->max('created_at')) : null;
        $avgOrder = $orderCount > 0 ? $spend / $orderCount : 0.0;

        // --- invoices: exposure, overdue, aging, on-time, typical pay delay ---
        $invoices = $byPhone(Invoice::where('tenant_id', $t))
            ->where('status', '!=', 'void')
            ->get();

        $exposure = 0.0; $overdue = 0.0; $overdueCount = 0; $maxLate = 0;
        $aging = ['cur' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0];
        $settled = 0; $onTime = 0; $paySum = 0; $payN = 0;

        foreach ($invoices as $inv) {
            $idate = $inv->issued_at ? Carbon::parse($inv->issued_at)->format('Y-m-d') : null;
            // Untagged invoices are in the engine's LOCAL currency (UGX on a converted basis), NOT the
            // display base — falling back to $base would show a UGX balance with a '$' (e.g. $603,075).
            $bal = $toBase((float) $inv->balance, (string) ($inv->currency ?: $native), $idate);
            $due = $inv->due_at ? Carbon::parse($inv->due_at) : null;
            if ($bal > 0.01) {
                $exposure += $bal;
                $daysPast = $due ? (int) $due->diffInDays($now, false) : 0;
                if ($due && $due->lt($now)) { $overdue += $bal; $overdueCount++; $maxLate = max($maxLate, $daysPast); }
                if ($daysPast <= 0) { $aging['cur'] += $bal; }
                elseif ($daysPast <= 30) { $aging['d30'] += $bal; }
                elseif ($daysPast <= 60) { $aging['d60'] += $bal; }
                else { $aging['d90'] += $bal; }
            } else {
                // Settled invoice — use it to estimate reliability. paid-time proxied by updated_at.
                $settled++;
                $paidAt = $inv->updated_at ? Carbon::parse($inv->updated_at) : null;
                if ($due && $paidAt) { if ($paidAt->lte($due->copy()->endOfDay())) { $onTime++; } }
                if ($inv->issued_at && $paidAt) {
                    $d = (int) Carbon::parse($inv->issued_at)->diffInDays($paidAt);
                    if ($d >= 0 && $d < 400) { $paySum += $d; $payN++; }
                }
            }
        }

        $onTimePct = $settled > 0 ? (int) round($onTime / $settled * 100) : null;
        $paysInDays = $payN > 0 ? (int) round($paySum / $payN) : null;

        // --- credit usage (credit_limit is stored in the tenant's base currency; show it consolidated) ---
        $credit = $c->credit_limit !== null ? $toBase((float) $c->credit_limit, $ctx->baseCurrency(), null) : null;
        $util = ($credit && $credit > 0) ? $exposure / $credit : 0.0;
        $creditAvail = ($credit && $credit > 0) ? max(0.0, $credit - $exposure) : null;
        $canSell = ! ($credit && $credit > 0 && $exposure >= $credit);

        // --- lifecycle ---
        $lastOrderDays = $lastOrderAt ? (int) $lastOrderAt->diffInDays($now) : null;
        $status = $orderCount === 0 ? 'prospect'
            : (! $canSell ? 'blocked'
            : (($lastOrderDays !== null && $lastOrderDays <= 90) ? 'active' : 'dormant'));

        // --- blended health score (neutral 80 for brand-new; penalise real risk only) ---
        $health = 80;
        if ($orderCount > 0) {
            $health = 92;
            if ($overdue > 0) { $health -= min(38, (int) round(($exposure > 0 ? $overdue / max($exposure, 1) : 1) * 25) + min(15, (int) round($maxLate / 6))); }
            if ($util > 0.85) { $health -= 12; } elseif ($util > 0.6) { $health -= 5; }
            if ($lastOrderDays !== null && $lastOrderDays > 60) { $health -= 12; }
            if ($onTimePct !== null && $onTimePct < 70) { $health -= 10; }
            if (! $canSell) { $health -= 20; }
        }
        $health = max(30, min(99, $health));

        $creditRisk = $health >= 75 ? 'Low' : ($health >= 50 ? 'Medium' : 'High');

        return [
            'currency' => $base,
            'status' => $status,
            'spend' => round($spend, 2),
            'orders' => $orderCount,
            'avgOrder' => round($avgOrder, 2),
            'lastOrderAt' => $lastOrderAt,
            'lastOrderDays' => $lastOrderDays,
            'exposure' => round($exposure, 2),
            'balance' => round($exposure, 2),
            'overdue' => round($overdue, 2),
            'overdueCount' => $overdueCount,
            'maxDaysLate' => $maxLate,
            'aging' => array_map(fn ($v) => round($v, 2), $aging),
            'credit' => $credit,
            'creditAvail' => $creditAvail,
            'util' => round($util, 3),
            'canSell' => $canSell,
            'onTimePct' => $onTimePct,
            'paysInDays' => $paysInDays,
            'health' => $health,
            'creditRisk' => $creditRisk,
            'invoiceCount' => $invoices->count(),
        ];
    }

    /**
     * A statement (debit/credit/running balance) built from the same ledgers, newest maths last.
     * Every entry is consolidated into one currency so the running balance is meaningful; the
     * per-line debit/credit TEXT follows the report mode (native in Original, consolidated otherwise).
     *
     * Each returned row carries: date, detail, drText, crText, balanceText (display strings) plus
     * numeric dr/cr/balance (consolidated) and currency (the consolidation currency), and link.
     *
     * @return array{rows:array<int,array<string,mixed>>,balance:float,balanceText:string,currency:string}
     */
    public function statement(CapabilityContext $ctx, CustomerProfile $c): array
    {
        $rc = ReportingCurrency::forContext($ctx);
        $base = $rc->consolidationCurrency();
        // Untagged invoices/payments are in the engine's LOCAL currency (UGX on a converted basis),
        // NOT the display base — using $base as the fallback would show a UGX amount with a '$'.
        $native = $ctx->nativeCurrency();

        $t = $ctx->tenantId();
        $digits = preg_replace('/[^0-9]/', '', (string) $c->phone);
        if ($digits === '') {
            return ['rows' => [], 'balance' => 0.0, 'balanceText' => \App\Capability\Support\Money::fmt(0, $base), 'currency' => $base];
        }
        $byPhone = fn ($q) => $q->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits]);

        $rows = [];
        $byPhone(Invoice::where('tenant_id', $t))
            ->where('status', '!=', 'void')->get()
            ->each(function ($i) use (&$rows, $native) {
                $rows[] = ['t' => $i->issued_at ? Carbon::parse($i->issued_at) : Carbon::parse($i->created_at),
                    'detail' => 'Invoice ' . $i->invoice_no, 'amt' => (float) $i->total, 'side' => 'dr',
                    'cur' => (string) ($i->currency ?: $native), 'usd' => null,
                    'link' => '/api/v1/workspace/invoice/' . $i->id];
            });
        $byPhone(CustomerPayment::where('tenant_id', $t))->get()
            ->each(function ($p) use (&$rows, $native) {
                $rows[] = ['t' => Carbon::parse($p->created_at), 'detail' => 'Payment · ' . ($p->method ?: 'cash'),
                    'amt' => (float) $p->amount, 'side' => 'cr',
                    'cur' => (string) ($p->currency ?: $native), 'usd' => (float) ($p->usd_amount ?? 0) ?: null,
                    'link' => null];
            });

        usort($rows, fn ($a, $b) => $a['t']->timestamp <=> $b['t']->timestamp);
        $bal = 0.0; $out = [];
        foreach ($rows as $r) {
            $date = $r['t']->format('Y-m-d');
            $consol = $rc->consolidate([[ 'amount' => $r['amt'], 'currency' => $r['cur'], 'usd' => $r['usd'], 'date' => $date ]])['amount'];
            $line = $rc->line($r['amt'], $r['cur'], $r['usd'], $date);   // native in Original, else consolidated
            $isDr = $r['side'] === 'dr';
            $bal += $isDr ? $consol : -$consol;

            $out[] = [
                'date' => $r['t']->format('d M Y'), 'detail' => $r['detail'],
                'drText' => $isDr ? $line['text'] : '', 'crText' => $isDr ? '' : $line['text'],
                'balanceText' => \App\Capability\Support\Money::fmt(round($bal, 2), $base),
                'dr' => $isDr ? $consol : 0.0, 'cr' => $isDr ? 0.0 : $consol,
                'balance' => round($bal, 2), 'currency' => $base, 'link' => $r['link'],
            ];
        }

        return ['rows' => $out, 'balance' => round($bal, 2), 'balanceText' => \App\Capability\Support\Money::fmt(round($bal, 2), $base), 'currency' => $base];
    }
}
