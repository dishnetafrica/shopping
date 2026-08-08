<?php

namespace App\Services\Finance;

use App\Models\OrderCost;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Money\Fx;
use Illuminate\Support\Carbon;

/**
 * Supplier payables statement (PDF) — opening balance, every credit bill (charge) and payment,
 * chronological, with a running "balance owed" in USD. Read-only; mirrors the debt computation.
 */
final class SupplierStatement
{
    public function collect(Tenant $t, int $supplierId): ?array
    {
        $s = Supplier::where('tenant_id', $t->id)->find($supplierId);
        if (! $s) { return null; }
        $fx = Fx::forTenant($t);

        $openUsd = 0.0;
        if ($s->opening_balance > 0) {
            $orate = $fx->ratePerUsd(strtoupper((string) ($s->opening_currency ?: 'USD'))) ?: 1;
            $openUsd = (float) $s->opening_balance / ($orate ?: 1);
        }

        // credit bills (charges) + payments — the two things that move what we owe
        $items = OrderCost::where('tenant_id', $t->id)->where('supplier_id', $s->id)
            ->whereNotIn('status', ['void', 'rejected'])
            ->where(function ($q) {
                $q->where(fn ($w) => $w->where('stage', '!=', 'supplier_payment')->where('pay_status', 'credit'))
                    ->orWhere('stage', 'supplier_payment');
            })
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'order_no', 'note', 'stage', 'currency', 'native_amount', 'usd_amount', 'created_at', 'product_id', 'qty', 'unit_cost']);

        // v4.64.1 — the unit label per product, so the statement can say "70 CTN" and not just "70".
        // One query. A supplier statement that shows what was spent and not what arrived is the same
        // complaint the screens answered; the PDF is a second renderer and had to be told separately.
        $units = [];
        try {
            $pids = $items->pluck('product_id')->filter()->unique()->values()->all();
            if ($pids) {
                \App\Models\Product::withoutGlobalScopes()->where('tenant_id', $t->id)->whereIn('id', $pids)
                    ->get(['id', 'unit_label'])
                    ->each(fn ($p) => $units[(int) $p->id] = trim((string) ($p->unit_label ?: '')) ?: 'units');
            }
        } catch (\Throwable $e) {
        }

        $rows = []; $bal = $openUsd; $charges = 0.0; $payments = 0.0;
        foreach ($items as $c) {
            $isPay = $c->stage === 'supplier_payment';
            $usd = (float) $c->usd_amount;
            if ($isPay) { $bal -= $usd; $payments += $usd; } else { $bal += $usd; $charges += $usd; }
            $ref = (string) ($c->order_no ?: (($isPay ? 'PAY-' : 'PUR-') . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)));
            $rows[] = [
                'when' => $c->created_at ? Carbon::parse($c->created_at)->format('Y-m-d') : '',
                'ref' => $ref,
                'desc' => (string) ($c->note ?: ($isPay ? 'Payment' : 'Purchase')),
                // Quantity in the native currency it was bought in, because that is what the
                // supplier's own invoice says and the point of a statement is that the two agree.
                'qty' => $isPay ? '' : \App\Services\Inventory\PurchaseStock::describe(
                    $c->qty, $units[(int) $c->product_id] ?? null, $c->unit_cost, (string) $c->currency
                ),
                'charge' => $isPay ? null : $usd,
                'payment' => $isPay ? $usd : null,
                'balance' => round($bal, 2),
            ];
        }

        return [
            'business' => (string) ($t->name ?: 'Company'),
            'generated' => now()->format('d M Y, H:i'),
            'supplier' => (string) ($s->business_name ?: $s->name),
            'meta' => trim(implode(' · ', array_filter([$s->contact_id ? ('#' . $s->contact_id) : '', (string) $s->contact_name, (string) $s->phone, $s->pay_term_days ? ('Net ' . $s->pay_term_days) : '']))),
            'opening' => round($openUsd, 2),
            'charges' => round($charges, 2),
            'payments' => round($payments, 2),
            'closing' => round($bal, 2),
            'rows' => $rows,
        ];
    }

    public function pdf(Tenant $t, array $d): ?string
    {
        if (! class_exists(\Dompdf\Dompdf::class)) { return null; }
        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->loadHtml($this->html($d));
            $dompdf->render();
            return $dompdf->output();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function html(array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $usd = fn ($v) => '$' . number_format((float) $v, 2);

        $rows = '<tr class="tot"><td colspan="5">Opening balance</td><td class="r b">' . $e($usd($d['opening'])) . '</td></tr>';
        foreach ($d['rows'] as $r) {
            $qty = trim((string) ($r['qty'] ?? ''));
            $rows .= '<tr><td>' . $e($r['when']) . '</td><td>' . $e($r['ref']) . '</td><td>' . $e($r['desc'])
                . ($qty !== '' ? '<div class="q">' . $e($qty) . '</div>' : '')
                . '</td><td class="r out">' . ($r['charge'] !== null ? $e($usd($r['charge'])) : '')
                . '</td><td class="r in">' . ($r['payment'] !== null ? $e($usd($r['payment'])) : '')
                . '</td><td class="r">' . $e($usd($r['balance'])) . '</td></tr>';
        }
        $rows .= '<tr class="tot"><td colspan="5">Balance owed</td><td class="r b">' . $e($usd($d['closing'])) . '</td></tr>';

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1f2933; font-size: 11px; }
            h1 { font-size: 18px; margin: 0 0 2px; color: #15803d; }
            .sub { color: #6b7280; font-size: 10.5px; margin-bottom: 14px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; background: #f3f4f6; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 5px 7px; border-bottom: 1px solid #e5e7eb; }
            th.r { text-align: right; }
            td { padding: 5px 7px; border-bottom: 1px solid #eef1f4; } td.r { text-align: right; } td.b { font-weight: bold; }
            tr.tot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
            .q { color: #6b7280; font-size: 9.5px; margin-top: 2px; }
            .in { color: #15803d; } .out { color: #b91c1c; }
            .foot { margin-top: 16px; color: #9ca3af; font-size: 9px; text-align: center; }
        </style></head><body>
            <h1>' . $e($d['business']) . ' — Supplier Statement</h1>
            <div class="sub">' . $e($d['supplier']) . ($d['meta'] ? ' &nbsp;·&nbsp; ' . $e($d['meta']) : '') . ' &nbsp;·&nbsp; ' . $e($d['generated']) . '</div>
            <table><thead><tr><th>Date</th><th>Ref</th><th>Description</th><th class="r">Charge (USD)</th><th class="r">Payment (USD)</th><th class="r">Balance owed</th></tr></thead>
            <tbody>' . $rows . '</tbody></table>
            <div class="foot">Charges ' . $e($usd($d['charges'])) . ' · Payments ' . $e($usd($d['payments'])) . ' · Balance owed ' . $e($usd($d['closing'])) . ' — USD at each entry\'s recorded rate (cost basis)</div>
        </body></html>';
    }
}
