<?php

namespace App\Services\Finance;

use App\Models\OrderCost;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Money\Fx;
use Illuminate\Support\Carbon;

/**
 * Supplier payables statement — opening balance, every credit bill (charge) and payment,
 * chronological, with a running "balance owed". Read-only; mirrors the debt computation.
 *
 * Rendering currency:
 *   - USD (default): every row converted to USD at its recorded rate — the consolidated view.
 *   - Native (e.g. UGX): amounts taken straight from each bill's native_amount, so the statement
 *     reconciles line-for-line against the supplier's own invoice. Single-currency by construction —
 *     rows billed in another currency are skipped and noted.
 *
 * Optional date range [$from, $to]: transactions before $from are folded into the opening balance
 * (so the running balance is correct "as of" the period start); transactions after $to are ignored.
 *
 * Output: pdf() (Dompdf, paginated) or csv() (no PDF engine needed).
 */
final class SupplierStatement
{
    public function collect(Tenant $t, int $supplierId, string $currency = 'USD', ?string $from = null, ?string $to = null): ?array
    {
        $s = Supplier::where('tenant_id', $t->id)->find($supplierId);
        if (! $s) { return null; }

        $mode = strtoupper(trim($currency)) ?: 'USD';
        $native = ($mode !== 'USD');
        $fx = Fx::forTenant($t);

        $fromTs = $this->parseDay($from, false);
        $toTs = $this->parseDay($to, true);

        // Base opening balance, expressed in the statement currency.
        $opening = 0.0;
        if ($s->opening_balance > 0) {
            if ($native) {
                if (strtoupper((string) ($s->opening_currency ?: 'USD')) === $mode) {
                    $opening = (float) $s->opening_balance;
                }
            } else {
                $orate = $fx->ratePerUsd(strtoupper((string) ($s->opening_currency ?: 'USD'))) ?: 1;
                $opening = (float) $s->opening_balance / ($orate ?: 1);
            }
        }

        // credit bills (charges) + payments move what we owe; cash purchases are pulled too, only so we
        // can note them as excluded (they were settled at the till and are not payables).
        $items = OrderCost::where('tenant_id', $t->id)->where('supplier_id', $s->id)
            ->whereNotIn('status', ['void', 'rejected'])
            ->where(function ($q) {
                $q->where(fn ($w) => $w->where('stage', '!=', 'supplier_payment')->whereIn('pay_status', ['credit', 'cash']))
                    ->orWhere('stage', 'supplier_payment');
            })
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'order_no', 'note', 'stage', 'currency', 'pay_status', 'native_amount', 'usd_amount', 'created_at']);

        $rows = []; $bal = $opening; $charges = 0.0; $payments = 0.0;
        $excluded = 0; $others = [];
        $cashCount = 0; $cashTotal = 0.0;
        foreach ($items as $c) {
            // Native statement is single-currency: skip other-currency rows (regardless of date).
            if ($native && strtoupper((string) $c->currency) !== $mode) {
                $excluded++;
                $others[strtoupper((string) ($c->currency ?: '?'))] = true;
                continue;
            }
            $isPay = $c->stage === 'supplier_payment';
            $isCash = ! $isPay && strtolower((string) $c->pay_status) === 'cash';
            $amt = $native ? (float) $c->native_amount : (float) $c->usd_amount;
            $signed = $isPay ? -$amt : $amt;
            $when = $c->created_at ? Carbon::parse($c->created_at) : null;

            // Cash purchases are never payables — count them only if they fall in the shown period.
            if ($isCash) {
                if ($fromTs && $when && $when->lt($fromTs)) { continue; }
                if ($toTs && $when && $when->gt($toTs)) { continue; }
                $cashCount++;
                $cashTotal += $amt;
                continue;
            }

            // Before the period → fold into opening so the running balance starts correct.
            if ($fromTs && $when && $when->lt($fromTs)) {
                $bal += $signed;
                $opening = $bal;
                continue;
            }
            // After the period → not on this statement.
            if ($toTs && $when && $when->gt($toTs)) {
                continue;
            }

            $bal += $signed;
            if ($isPay) { $payments += $amt; } else { $charges += $amt; }
            $ref = (string) ($c->order_no ?: (($isPay ? 'PAY-' : 'PUR-') . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)));
            $rows[] = [
                'when' => $when ? $when->format('Y-m-d') : '',
                'ref' => $ref,
                'desc' => (string) ($c->note ?: ($isPay ? 'Payment' : 'Purchase')),
                'charge' => $isPay ? null : $amt,
                'payment' => $isPay ? $amt : null,
                'balance' => round($bal, 2),
            ];
        }

        $period = '';
        if ($fromTs || $toTs) {
            $period = ($fromTs ? $fromTs->format('d M Y') : 'start') . ' – ' . ($toTs ? $toTs->format('d M Y') : 'today');
        }

        return [
            'business' => (string) ($t->name ?: 'Company'),
            'generated' => now()->format('d M Y, H:i'),
            'supplier' => (string) ($s->business_name ?: $s->name),
            'meta' => trim(implode(' · ', array_filter([$s->contact_id ? ('#' . $s->contact_id) : '', (string) $s->contact_name, (string) $s->phone, $s->pay_term_days ? ('Net ' . $s->pay_term_days) : '']))),
            'currency' => $mode,
            'native' => $native,
            'period' => $period,
            'opening' => round($opening, 2),
            'charges' => round($charges, 2),
            'payments' => round($payments, 2),
            'closing' => round($bal, 2),
            'rows' => $rows,
            'cash_count' => $cashCount,
            'cash_total' => round($cashTotal, 2),
            'excluded' => $excluded,
            'other_currencies' => array_keys($others),
        ];
    }

    /** Safe day parse: returns start-of-day (or end-of-day when $end) Carbon, or null on empty/invalid. */
    private function parseDay(?string $d, bool $end): ?Carbon
    {
        $d = trim((string) $d);
        if ($d === '') { return null; }
        try {
            $c = Carbon::parse($d);
            return $end ? $c->endOfDay() : $c->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function pdf(Tenant $t, array $d): ?string
    {
        if (! class_exists(\Dompdf\Dompdf::class)) { return null; }
        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->loadHtml($this->html($d));
            $dompdf->render();
            // Page numbers — a long statement flows across pages; number them.
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
            $canvas->page_text(500, 812, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.6, 0.6, 0.6]);
            return $dompdf->output();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Spreadsheet-friendly export of the same statement — works without a PDF engine. */
    public function csv(array $d): string
    {
        $code = (string) ($d['currency'] ?? 'USD');
        $q = fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $n = fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '');
        $out = [];
        $out[] = $q($d['business'] . ' — Supplier Statement');
        $out[] = $q('Supplier') . ',' . $q($d['supplier']);
        if (! empty($d['meta'])) { $out[] = $q('Details') . ',' . $q($d['meta']); }
        $out[] = $q('Currency') . ',' . $q($code);
        $out[] = $q('Period') . ',' . $q($d['period'] ?: 'All transactions');
        $out[] = $q('Generated') . ',' . $q($d['generated']);
        $out[] = '';
        $out[] = implode(',', array_map($q, ['Date', 'Ref', 'Description', 'Charge (' . $code . ')', 'Payment (' . $code . ')', 'Balance owed (' . $code . ')']));
        $out[] = implode(',', [$q(''), $q(''), $q('Opening balance'), '', '', $n($d['opening'])]);
        foreach ($d['rows'] as $r) {
            $out[] = implode(',', [$q($r['when']), $q($r['ref']), $q($r['desc']), $n($r['charge']), $n($r['payment']), $n($r['balance'])]);
        }
        $out[] = implode(',', [$q(''), $q(''), $q('Balance owed'), $n($d['charges']), $n($d['payments']), $n($d['closing'])]);
        if ((int) ($d['cash_count'] ?? 0) > 0) {
            $out[] = '';
            $out[] = $q((int) $d['cash_count'] . ' cash purchase(s) totalling ' . $n($d['cash_total']) . ' ' . $code . ' are excluded (settled at the till, not payables).');
        }
        if (($d['native'] ?? false) && (int) ($d['excluded'] ?? 0) > 0) {
            $out[] = '';
            $out[] = $q((int) $d['excluded'] . ' bill(s) in other currencies (' . implode(', ', (array) ($d['other_currencies'] ?? [])) . ') are excluded — see the USD statement for a consolidated view.');
        }
        return implode("\r\n", $out) . "\r\n";
    }

    private function html(array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $code = (string) ($d['currency'] ?? 'USD');
        // USD keeps the classic $#,###.## form; native currencies show as "UGX 4,950,000",
        // dropping minor units when the amount is whole (as UGX invoices are).
        $money = function ($v) use ($code) {
            $v = (float) $v;
            if ($code === 'USD') { return '$' . number_format($v, 2); }
            $dec = (abs(fmod($v, 1.0)) < 0.005) ? 0 : 2;
            return $code . ' ' . number_format($v, $dec);
        };

        $rows = '<tr class="tot"><td colspan="5">Opening balance</td><td class="r b">' . $e($money($d['opening'])) . '</td></tr>';
        foreach ($d['rows'] as $r) {
            $rows .= '<tr><td>' . $e($r['when']) . '</td><td>' . $e($r['ref']) . '</td><td>' . $e($r['desc'])
                . '</td><td class="r out">' . ($r['charge'] !== null ? $e($money($r['charge'])) : '')
                . '</td><td class="r in">' . ($r['payment'] !== null ? $e($money($r['payment'])) : '')
                . '</td><td class="r">' . $e($money($r['balance'])) . '</td></tr>';
        }
        $rows .= '<tr class="tot"><td colspan="5">Balance owed</td><td class="r b">' . $e($money($d['closing'])) . '</td></tr>';

        $basis = ($d['native'] ?? false)
            ? 'In ' . $e($code) . ' — the supplier\'s invoicing currency, as billed'
            : 'USD at each entry\'s recorded rate (cost basis)';
        $excludedNote = '';
        if (($d['native'] ?? false) && (int) ($d['excluded'] ?? 0) > 0) {
            $oc = implode(', ', array_map($e, (array) ($d['other_currencies'] ?? [])));
            $excludedNote = '<div class="foot">Note: ' . (int) $d['excluded'] . ' bill(s) in other currencies (' . $oc
                . ') are not shown on this ' . $e($code) . ' statement — use the USD statement for a consolidated view.</div>';
        }
        $periodBit = ! empty($d['period']) ? ' &nbsp;·&nbsp; Period: ' . $e($d['period']) : '';

        // Branded header bits (Dompdf renders tables well, flexbox poorly — so everything is tables).
        $words = preg_split('/\s+/', trim((string) $d['business'])) ?: ['S'];
        $initials = strtoupper(substr($words[0] ?? 'S', 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
        $statedFor = $e($d['supplier']) . ($d['meta'] ? '<div class="sm">' . $e($d['meta']) . '</div>' : '');
        $periodLbl = ! empty($d['period']) ? $e($d['period']) : 'All transactions';

        $cashNote = '';
        if ((int) ($d['cash_count'] ?? 0) > 0) {
            $cashNote = '<div class="note">Cash purchases settled at the till are not payables and are excluded from this statement — '
                . (int) $d['cash_count'] . ' cash bill(s) of <b>' . $e($money($d['cash_total'])) . '</b>.</div>';
        }

        $cards = '<table class="cards"><tr>'
            . '<td class="card"><div class="ck">Charges (credit bills)</div><div class="cv out">' . $e($money($d['charges'])) . '</div></td>'
            . '<td class="card"><div class="ck">Payments</div><div class="cv in">' . $e($money($d['payments'])) . '</div></td>'
            . '<td class="card owe"><div class="ck">Balance owed</div><div class="cv owe">' . $e($money($d['closing'])) . '</div></td>'
            . '</tr></table>';

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1f2933; font-size: 11px; }
            table { width: 100%; border-collapse: collapse; }
            .hd td { border: none; padding: 0; vertical-align: middle; }
            .logo { width: 34px; height: 34px; background: #15803d; color: #fff; font-size: 15px; font-weight: bold; text-align: center; vertical-align: middle; border-radius: 7px; }
            .bn { font-size: 17px; font-weight: bold; color: #15803d; padding-left: 9px; }
            .bs { font-size: 10px; color: #6b7280; padding-left: 9px; }
            .rt { text-align: right; }
            .doc { font-size: 15px; font-weight: bold; }
            .cur { display: inline-block; margin-top: 4px; background: #ecfdf5; color: #15803d; border: 1px solid #a7f3d0; font-weight: bold; font-size: 10px; padding: 2px 9px; border-radius: 20px; }
            .rule { border-bottom: 3px solid #15803d; height: 0; margin: 10px 0 12px; }
            .pr td { border: none; padding: 0; font-size: 10.5px; }
            .lbl { font-size: 8.5px; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; margin-bottom: 2px; }
            .val { font-size: 12px; font-weight: bold; }
            .sm { font-weight: normal; color: #6b7280; font-size: 10px; margin-top: 1px; }
            .main { margin-top: 12px; }
            .main th { text-align: left; background: #f3f4f6; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 6px 7px; border-bottom: 1px solid #e5e7eb; }
            .main th.r { text-align: right; }
            .main td { padding: 6px 7px; border-bottom: 1px solid #eef1f4; } .main td.r { text-align: right; } .main td.b { font-weight: bold; }
            .main tr.tot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
            .in { color: #15803d; } .out { color: #b91c1c; }
            .cards { border-collapse: separate; border-spacing: 8px 0; margin-top: 16px; }
            .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 9px 11px; width: 33%; vertical-align: top; }
            .card.owe { background: #fef2f2; border-color: #fecaca; }
            .ck { font-size: 8.5px; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; }
            .cv { font-size: 15px; font-weight: bold; margin-top: 3px; } .cv.owe { color: #b91c1c; }
            .note { margin-top: 12px; font-size: 9.5px; color: #6b7280; background: #f9fafb; border-left: 3px solid #d1d5db; padding: 7px 10px; }
            .foot { margin-top: 14px; color: #9ca3af; font-size: 9px; text-align: center; }
        </style></head><body>
            <table class="hd"><tr>
                <td><table class="hd"><tr><td class="logo">' . $e($initials) . '</td>
                    <td><div class="bn">' . $e($d['business']) . '</div><div class="bs">Reports in supplier currency</div></td></tr></table></td>
                <td class="rt"><div class="doc">Supplier Statement</div><div class="cur">Currency: ' . $e($code) . '</div></td>
            </tr></table>
            <div class="rule"></div>
            <table class="pr"><tr>
                <td><div class="lbl">Statement for</div><div class="val">' . $statedFor . '</div></td>
                <td class="rt"><div class="lbl">Period · Generated</div><div class="val">' . $periodLbl . '</div><div class="sm">Generated ' . $e($d['generated']) . '</div></td>
            </tr></table>
            <table class="main"><thead><tr><th>Date</th><th>Ref</th><th>Description</th><th class="r">Charge (' . $e($code) . ')</th><th class="r">Payment (' . $e($code) . ')</th><th class="r">Balance owed</th></tr></thead>
            <tbody>' . $rows . '</tbody></table>
            ' . $cards . '
            ' . $cashNote . '
            <div class="foot">' . $basis . '</div>'
            . $excludedNote . '
        </body></html>';
    }
}
