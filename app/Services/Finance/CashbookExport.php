<?php

namespace App\Services\Finance;

use App\Models\OrderCost;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Money\CashService;
use App\Services\Money\Fx;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cashbook export — the on-screen cash position (by currency AND by person) plus every entry,
 * as a printable PDF statement or a CSV (opens in Excel). Read-only; uses the SAME frozen sign
 * rule (CashService::IN_STAGES) as the cashbook screen, so exports never drift from what's shown.
 */
final class CashbookExport
{
    private const CURRENCIES = ['USD', 'UGX', 'SSP'];
    private const MAX_ROWS = 5000;

    private const STAGE_LABELS = [
        'cash_in' => 'Cash In', 'cash_out' => 'Cash Out', 'funding' => 'Funding', 'purchase' => 'Purchase',
        'customer_payment' => 'Receipt', 'drawing' => 'Drawing', 'supplier_payment' => 'Supplier Pay', 'expense' => 'Expense',
    ];

    /** Gather the position + holders + entries, scoped like the screen (staff see only their own). */
    public function collect(Tenant $t, ?User $u = null): array
    {
        $fx = Fx::forTenant($t);
        $rate = ['USD' => 1.0, 'UGX' => (float) $fx->ratePerUsd('UGX'), 'SSP' => (float) $fx->ratePerUsd('SSP')];
        $isOwner = $u && method_exists($u, 'isOwnerLike') ? $u->isOwnerLike() : true;
        $me = (string) ($u->name ?? '');

        $scoped = function ($q) use ($isOwner, $u, $me) {
            if ($isOwner || ! $u) { return $q; }
            return $q->where(fn ($w) => $w->where('holder_user_id', $u->id)->orWhere('holder', $me));
        };

        // --- position by currency and by person (frozen IN_STAGES sign rule) ---
        $posRows = $scoped(OrderCost::where('tenant_id', $t->id)->where('status', 'approved'))
            ->select('currency', 'stage', 'holder', DB::raw('SUM(paid_native) as native'), DB::raw('SUM(paid_native / NULLIF(rate_to_usd,0)) as usd'))
            ->groupBy('currency', 'stage', 'holder')->get();

        $pos = []; $holders = [];
        foreach (self::CURRENCIES as $c) { $pos[$c] = ['native' => 0.0, 'usd' => 0.0]; }
        foreach ($posRows as $r) {
            $sign = in_array($r->stage, CashService::IN_STAGES, true) ? 1 : -1;
            $c = (string) $r->currency;
            if (isset($pos[$c])) { $pos[$c]['native'] += $sign * (float) $r->native; $pos[$c]['usd'] += $sign * (float) $r->usd; }
            $h = trim((string) ($r->holder ?: '')) ?: 'Unassigned';
            if (! isset($holders[$h])) { $holders[$h] = ['USD' => 0.0, 'UGX' => 0.0, 'SSP' => 0.0, 'usd' => 0.0]; }
            if (isset($holders[$h][$c])) { $holders[$h][$c] += $sign * (float) $r->native; }
            $holders[$h]['usd'] += $sign * (float) $r->usd;
        }
        $combinedUsd = round($pos['USD']['usd'] + $pos['UGX']['usd'] + $pos['SSP']['usd'], 2);

        // keep only holders that actually hold something, richest first
        $holders = array_filter($holders, fn ($b) => abs($b['USD']) > 0.01 || abs($b['UGX']) > 0.01 || abs($b['SSP']) > 0.01);
        uasort($holders, fn ($a, $b) => $b['usd'] <=> $a['usd']);

        // --- entries (cash rows only, newest first) ---
        $entries = $scoped(OrderCost::where('tenant_id', $t->id)
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
            ->whereNotIn('status', ['void', 'rejected']))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_ROWS)->get();

        $rows = [];
        foreach ($entries as $c) {
            $isIn = in_array($c->stage, CashService::IN_STAGES, true);
            $ref = in_array($c->stage, ['cash_in', 'cash_out'], true)
                ? 'CB-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)
                : ((string) ($c->order_no ?: strtoupper(substr((string) $c->stage, 0, 3)) . '-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)));
            $when = $c->created_at ? Carbon::parse($c->created_at) : null;
            $rows[] = [
                'when' => $when ? $when->format('Y-m-d H:i') : '',
                'ref' => $ref,
                'category' => (string) ($c->category ?: (self::STAGE_LABELS[$c->stage] ?? ucfirst(str_replace('_', ' ', (string) $c->stage)))),
                'holder' => (string) ($c->holder ?: ''),
                'dir' => $isIn ? 'In' : 'Out',
                'currency' => (string) $c->currency,
                'native' => (float) $c->native_amount,
                'signed' => ($isIn ? 1 : -1) * (float) $c->native_amount,
                'usd' => ($isIn ? 1 : -1) * (float) $c->usd_amount,
                'note' => (string) ($c->note ?: ''),
            ];
        }

        return [
            'business' => (string) ($t->name ?: 'Cashbook'),
            'generated' => now()->format('d M Y, H:i'),
            'scope' => $isOwner ? 'All cash' : ('My cash — ' . $me),
            'rate' => $rate,
            'pos' => $pos,
            'combinedUsd' => $combinedUsd,
            'holders' => $holders,
            'rows' => $rows,
        ];
    }

    /** CSV: summary blocks (by currency, by person) then every entry — opens directly in Excel. */
    public function csv(array $d): string
    {
        $out = [];
        $line = function (array $cells) use (&$out) {
            $out[] = implode(',', array_map(function ($v) {
                $s = (string) $v;
                return (str_contains($s, ',') || str_contains($s, '"') || str_contains($s, "\n"))
                    ? '"' . str_replace('"', '""', $s) . '"' : $s;
            }, $cells));
        };

        $line([$d['business'] . ' — Cashbook']);
        $line(['Generated', $d['generated'], $d['scope']]);
        $line([]);

        $line(['CASH POSITION BY CURRENCY']);
        $line(['Currency', 'Amount', 'USD value']);
        foreach (self::CURRENCIES as $c) {
            $line([$c, $this->num($d['pos'][$c]['native']), $this->num($d['pos'][$c]['usd'])]);
        }
        $line(['Combined (USD)', '', $this->num($d['combinedUsd'])]);
        $line([]);

        $line(['CASH BY PERSON']);
        $line(['Held by', 'USD', 'UGX', 'SSP', 'Total (USD)']);
        foreach ($d['holders'] as $name => $b) {
            $line([$name, $this->num($b['USD']), $this->num($b['UGX']), $this->num($b['SSP']), $this->num($b['usd'])]);
        }
        $line([]);

        $line(['ALL CASH ENTRIES']);
        $line(['When', 'Ref', 'Category', 'Held by', 'In/Out', 'Currency', 'Amount', 'Signed amount', 'USD', 'Note']);
        foreach ($d['rows'] as $r) {
            $line([$r['when'], $r['ref'], $r['category'], $r['holder'], $r['dir'], $r['currency'],
                $this->num($r['native']), $this->num($r['signed']), $this->num($r['usd']), $r['note']]);
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $out) . "\r\n"; // BOM so Excel reads UTF-8
    }

    /** PDF: a branded cashbook statement — position by currency, cash by person, and every entry. */
    public function pdf(Tenant $t, array $d): ?string
    {
        if (! class_exists(\Dompdf\Dompdf::class)) { return null; }
        $html = $this->html($d);
        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->loadHtml($html);
            $dompdf->render();
            return $dompdf->output();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function html(array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $money = fn (float $v, string $c) => $this->fmt($v, $c);

        $curRows = '';
        foreach (self::CURRENCIES as $c) {
            $curRows .= '<tr><td>' . $c . '</td><td class="r">' . $e($money($d['pos'][$c]['native'], $c))
                . '</td><td class="r">' . $e($money($d['pos'][$c]['usd'], 'USD')) . '</td></tr>';
        }
        $curRows .= '<tr class="tot"><td>Combined</td><td class="r"></td><td class="r">' . $e($money($d['combinedUsd'], 'USD')) . '</td></tr>';

        $holderRows = '';
        foreach ($d['holders'] as $name => $b) {
            $holderRows .= '<tr><td>' . $e($name) . '</td><td class="r">' . $e($money($b['USD'], 'USD'))
                . '</td><td class="r">' . $e($money($b['UGX'], 'UGX')) . '</td><td class="r">' . $e($money($b['SSP'], 'SSP'))
                . '</td><td class="r b">' . $e($money($b['usd'], 'USD')) . '</td></tr>';
        }
        if ($holderRows === '') { $holderRows = '<tr><td colspan="5" class="mut">No cash recorded yet</td></tr>'; }

        $entryRows = '';
        foreach ($d['rows'] as $r) {
            $cls = $r['dir'] === 'In' ? 'in' : 'out';
            $entryRows .= '<tr><td>' . $e($r['when']) . '</td><td>' . $e($r['ref']) . '</td><td>' . $e($r['category'])
                . '</td><td>' . $e($r['holder']) . '</td><td class="' . $cls . '">' . $r['dir']
                . '</td><td class="r ' . $cls . '">' . ($r['dir'] === 'In' ? '+' : '−') . $e($money($r['native'], $r['currency']))
                . '</td><td class="r">' . $e($money($r['usd'], 'USD')) . '</td></tr>';
        }
        if ($entryRows === '') { $entryRows = '<tr><td colspan="7" class="mut">No entries</td></tr>'; }

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1f2933; font-size: 11px; }
            h1 { font-size: 19px; margin: 0 0 2px; color: #15803d; }
            .sub { color: #6b7280; font-size: 10.5px; margin-bottom: 14px; }
            h2 { font-size: 12.5px; margin: 16px 0 6px; color: #111; border-bottom: 2px solid #15803d; padding-bottom: 3px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; background: #f3f4f6; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 5px 7px; border-bottom: 1px solid #e5e7eb; }
            td { padding: 5px 7px; border-bottom: 1px solid #eef1f4; }
            td.r, th.r { text-align: right; }
            td.b { font-weight: bold; }
            tr.tot td { font-weight: bold; border-top: 2px solid #d1d5db; }
            .in { color: #15803d; } .out { color: #b91c1c; }
            .mut { color: #9ca3af; text-align: center; }
            .foot { margin-top: 18px; color: #9ca3af; font-size: 9px; text-align: center; }
        </style></head><body>
            <h1>' . $e($d['business']) . ' — Cashbook</h1>
            <div class="sub">' . $e($d['generated']) . ' &nbsp;·&nbsp; ' . $e($d['scope']) . '</div>

            <h2>Cash position by currency</h2>
            <table><thead><tr><th>Currency</th><th class="r">Amount</th><th class="r">USD value</th></tr></thead>
            <tbody>' . $curRows . '</tbody></table>

            <h2>Cash by person</h2>
            <table><thead><tr><th>Held by</th><th class="r">USD</th><th class="r">UGX</th><th class="r">SSP</th><th class="r">Total (USD)</th></tr></thead>
            <tbody>' . $holderRows . '</tbody></table>

            <h2>All cash entries (' . count($d['rows']) . ')</h2>
            <table><thead><tr><th>When</th><th>Ref</th><th>Category</th><th>Held by</th><th>In/Out</th><th class="r">Amount</th><th class="r">USD</th></tr></thead>
            <tbody>' . $entryRows . '</tbody></table>

            <div class="foot">Generated by CloudBSS · figures use each entry\'s recorded exchange rate (cost basis)</div>
        </body></html>';
    }

    private function num(float $v): string { return number_format($v, 2, '.', ''); }

    private function fmt(float $v, string $c): string
    {
        $n = number_format($v, ($c === 'USD' ? 2 : 0), '.', ',');
        return $c === 'USD' ? ('$' . $n) : ($n . ' ' . $c);
    }
}
