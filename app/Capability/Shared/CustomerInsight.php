<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\CapabilityContext;
use App\Models\CustomerProfile;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Order;
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

        // A blank phone must match NOTHING (an empty regexp target would otherwise match every
        // phone-less record in the tenant and wrongly attribute them to this customer).
        $byPhone = fn ($q) => $digits === '' ? $q->whereRaw('1=0')
            : $q->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits]);

        // --- orders: lifetime spend, count, last order ---
        $orders = $byPhone(Order::where('tenant_id', $t))
            ->get(['total', 'created_at']);
        $orderCount = $orders->count();
        $spend = (float) $orders->sum(fn ($o) => (float) $o->total);
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
            $bal = (float) $inv->balance;
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

        // --- credit usage ---
        $credit = $c->credit_limit !== null ? (float) $c->credit_limit : null;
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
     * @return array{rows:array<int,array<string,mixed>>,balance:float}
     */
    public function statement(CapabilityContext $ctx, CustomerProfile $c): array
    {
        $t = $ctx->tenantId();
        $digits = preg_replace('/[^0-9]/', '', (string) $c->phone);
        if ($digits === '') {
            return ['rows' => [], 'balance' => 0.0];
        }
        $byPhone = fn ($q) => $q->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits]);

        $rows = [];
        $byPhone(Invoice::where('tenant_id', $t))
            ->where('status', '!=', 'void')->get()
            ->each(function ($i) use (&$rows) {
                $rows[] = ['t' => $i->issued_at ? Carbon::parse($i->issued_at) : Carbon::parse($i->created_at),
                    'detail' => 'Invoice ' . $i->invoice_no, 'dr' => (float) $i->total, 'cr' => 0.0,
                    'link' => '/api/v1/workspace/invoice/' . $i->id];
            });
        $byPhone(CustomerPayment::where('tenant_id', $t))->get()
            ->each(function ($p) use (&$rows) {
                $rows[] = ['t' => Carbon::parse($p->created_at), 'detail' => 'Payment · ' . ($p->method ?: 'cash'),
                    'dr' => 0.0, 'cr' => (float) $p->amount, 'link' => null];
            });

        usort($rows, fn ($a, $b) => $a['t']->timestamp <=> $b['t']->timestamp);
        $bal = 0.0; $out = [];
        foreach ($rows as $r) {
            $bal += $r['dr'] - $r['cr'];
            $out[] = ['date' => $r['t']->format('d M Y'), 'detail' => $r['detail'],
                'dr' => $r['dr'], 'cr' => $r['cr'], 'balance' => round($bal, 2), 'link' => $r['link']];
        }

        return ['rows' => $out, 'balance' => round($bal, 2)];
    }
}
