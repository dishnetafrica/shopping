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

    /**
     * Gather the position + holders + entries, scoped like the screen (staff see only their own).
     * Optional $from / $to (Y-m-d) filter the ENTRIES to a period and add an in/out movement summary;
     * the cash position always reflects the current live balance.
     */
    public function collect(Tenant $t, ?User $u = null, ?string $from = null, ?string $to = null, ?string $currency = null, bool $company = false, ?string $holder = null): array
    {
        $currency = in_array(strtoupper((string) $currency), self::CURRENCIES, true) ? strtoupper($currency) : null;
        if ($company) { $currency = null; } // company book consolidates ALL currencies into USD
        $holder = trim((string) $holder) !== '' ? trim((string) $holder) : null;
        $byHolder = fn ($q) => $holder ? $q->where('holder', $holder) : $q;   // person-wise filter
        $fx = Fx::forTenant($t);
        $rate = ['USD' => 1.0, 'UGX' => (float) $fx->ratePerUsd('UGX'), 'SSP' => (float) $fx->ratePerUsd('SSP')];
        $isOwner = $u && method_exists($u, 'isOwnerLike') ? $u->isOwnerLike() : true;
        $me = (string) ($u->name ?? '');

        $scoped = function ($q) use ($isOwner, $u, $me) {
            if ($isOwner || ! $u) { return $q; }
            return $q->where(fn ($w) => $w->where('holder_user_id', $u->id)->orWhere('holder', $me));
        };

        // --- position by currency and by person (frozen IN_STAGES sign rule) ---
        // Same basis as the entries/books below (every live cash entry, native_amount) so the position
        // reconciles to the running-balance cash books to the cent.
        $posRows = $byHolder($scoped(OrderCost::where('tenant_id', $t->id)
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
            ->whereNotIn('status', ['void', 'rejected'])))
            ->select('currency', 'stage', 'holder', DB::raw('SUM(native_amount) as native'), DB::raw('SUM(native_amount / NULLIF(rate_to_usd,0)) as usd'))
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

        // --- entries (cash rows only, newest first; optionally within a date period) ---
        $from = $this->cleanDate($from); $to = $this->cleanDate($to);
        $eq = $scoped(OrderCost::where('tenant_id', $t->id)
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
            ->whereNotIn('status', ['void', 'rejected']));
        if ($from) { $eq->whereDate('created_at', '>=', $from); }
        if ($to) { $eq->whereDate('created_at', '<=', $to); }
        if ($currency) { $eq->where('currency', $currency); }
        if ($holder) { $eq->where('holder', $holder); }
        $entries = $eq->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_ROWS)->get();

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

        // --- period movement (in / out / net per currency) over the filtered entries ---
        $period = null;
        if ($from || $to) {
            $mv = [];
            foreach (self::CURRENCIES as $c) { $mv[$c] = ['in' => 0.0, 'out' => 0.0, 'net' => 0.0, 'usd' => 0.0]; }
            foreach ($rows as $r) {
                $c = $r['currency']; if (! isset($mv[$c])) { continue; }
                if ($r['dir'] === 'In') { $mv[$c]['in'] += $r['native']; } else { $mv[$c]['out'] += $r['native']; }
                $mv[$c]['net'] += $r['signed']; $mv[$c]['usd'] += $r['usd'];
            }
            $period = [
                'from' => $from ?: '…', 'to' => $to ?: '…',
                'label' => ($from ?: 'start') . ' → ' . ($to ?: 'today'),
                'movement' => $mv,
                'netUsd' => round(array_sum(array_map(fn ($m) => $m['usd'], $mv)), 2),
            ];
        }

        // --- single-currency cash book: opening balance → entries with a running balance → closing ---
        $book = null;
        if ($currency) {
            $inSql = "CASE WHEN stage IN ('funding','cash_in','customer_payment') THEN native_amount ELSE -native_amount END";
            $openQ = $byHolder($scoped(OrderCost::where('tenant_id', $t->id)->where('currency', $currency)
                ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
                ->whereNotIn('status', ['void', 'rejected'])));
            if ($from) { $openQ->whereDate('created_at', '<', $from); } else { $openQ->whereRaw('1=0'); }
            $opening = (float) $openQ->selectRaw("COALESCE(SUM($inSql),0) as bal")->value('bal');

            $bal = $opening; $bookRows = [];
            foreach (array_reverse($rows) as $r) {   // $rows is newest-first; walk oldest→newest
                $bal += $r['signed'];
                $bookRows[] = $r + ['balance' => round($bal, 2)];
            }
            $book = ['currency' => $currency, 'opening' => round($opening, 2), 'closing' => round($bal, 2), 'entries' => $bookRows];
        }

        // --- company account book: EVERY transaction valued in USD, one running USD balance ---
        $companyBook = null;
        if ($company) {
            $inSql = "CASE WHEN stage IN ('funding','cash_in','customer_payment') THEN usd_amount ELSE -usd_amount END";
            $openQ = $byHolder($scoped(OrderCost::where('tenant_id', $t->id)
                ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
                ->whereNotIn('status', ['void', 'rejected'])));
            if ($from) { $openQ->whereDate('created_at', '<', $from); } else { $openQ->whereRaw('1=0'); }
            $openingUsd = (float) $openQ->selectRaw("COALESCE(SUM($inSql),0) as bal")->value('bal');

            $bal = $openingUsd; $cRows = []; $totIn = 0.0; $totOut = 0.0;
            foreach (array_reverse($rows) as $r) {   // oldest→newest, $r['usd'] is already signed
                $bal += $r['usd'];
                if ($r['dir'] === 'In') { $totIn += abs($r['usd']); } else { $totOut += abs($r['usd']); }
                $cRows[] = $r + ['balance' => round($bal, 2)];
            }
            $companyBook = ['opening' => round($openingUsd, 2), 'closing' => round($bal, 2),
                'totalIn' => round($totIn, 2), 'totalOut' => round($totOut, 2), 'net' => round($totIn - $totOut, 2), 'entries' => $cRows];
        }

        return [
            'business' => (string) ($t->name ?: 'Cashbook'),
            'generated' => now()->format('d M Y, H:i'),
            'scope' => $holder ? ('Held by ' . $holder) : ($isOwner ? 'All cash' : ('My cash — ' . $me)),
            'holder' => $holder,
            'currency' => $currency,
            'book' => $book,
            'company' => $companyBook,
            'period' => $period,
            'rate' => $rate,
            'pos' => $pos,
            'combinedUsd' => $combinedUsd,
            'holders' => $holders,
            'rows' => $rows,
        ];
    }

    private function cleanDate(?string $d): ?string
    {
        $d = trim((string) $d);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
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

        // Company account book: every transaction valued in USD, one running USD balance.
        if (! empty($d['company'])) {
            $b = $d['company'];
            $line([$d['business'] . ' — Company Account Cash Book (USD)']);
            $line(['Generated', $d['generated'], $d['scope']]);
            if (! empty($d['period'])) { $line(['Period', $d['period']['label']]); }
            $line([]);
            $line(['Opening balance (USD)', $this->num($b['opening'])]);
            $line(['Date', 'Ref', 'Description', 'Held by', 'Original', 'In (USD)', 'Out (USD)', 'Balance (USD)']);
            foreach ($b['entries'] as $r) {
                $orig = $this->num($r['native']) . ' ' . $r['currency'];
                $line([$r['when'], $r['ref'], $r['note'] ?: $r['category'], $r['holder'], $orig,
                    $r['dir'] === 'In' ? $this->num(abs($r['usd'])) : '', $r['dir'] === 'Out' ? $this->num(abs($r['usd'])) : '',
                    $this->num($r['balance'])]);
            }
            $line(['Closing balance (USD)', $this->num($b['closing'])]);
            $line([]);
            $line(['SUMMARY (USD)']);
            $line(['Opening', $this->num($b['opening'])]);
            $line(['Total in', $this->num($b['totalIn'])]);
            $line(['Total out', $this->num($b['totalOut'])]);
            $line(['Net movement', $this->num($b['net'])]);
            $line(['Closing', $this->num($b['closing'])]);

            return "\xEF\xBB\xBF" . implode("\r\n", $out) . "\r\n";
        }

        // Single-currency cash book: opening → running balance → closing.
        if (! empty($d['book'])) {
            $b = $d['book']; $c = $b['currency'];
            $line([$d['business'] . ' — ' . $c . ' Cashbook']);
            $line(['Generated', $d['generated'], $d['scope']]);
            if (! empty($d['period'])) { $line(['Period', $d['period']['label']]); }
            $line([]);
            $line(['Opening balance', $this->num($b['opening']), $c]);
            $line(['Date', 'Ref', 'Category', 'Held by', 'In', 'Out', 'Balance']);
            foreach ($b['entries'] as $r) {
                $line([$r['when'], $r['ref'], $r['category'], $r['holder'],
                    $r['dir'] === 'In' ? $this->num($r['native']) : '', $r['dir'] === 'Out' ? $this->num($r['native']) : '',
                    $this->num($r['balance'])]);
            }
            $line(['Closing balance', $this->num($b['closing']), $c]);

            return "\xEF\xBB\xBF" . implode("\r\n", $out) . "\r\n";
        }

        $line([$d['business'] . ' — Cashbook']);
        $line(['Generated', $d['generated'], $d['scope']]);
        if (! empty($d['period'])) { $line(['Period', $d['period']['label']]); }
        $line([]);

        if (! empty($d['period'])) {
            $line(['MOVEMENT IN PERIOD (' . $d['period']['label'] . ')']);
            $line(['Currency', 'In', 'Out', 'Net', 'Net USD']);
            foreach (self::CURRENCIES as $c) {
                $m = $d['period']['movement'][$c];
                $line([$c, $this->num($m['in']), $this->num($m['out']), $this->num($m['net']), $this->num($m['usd'])]);
            }
            $line(['Net (USD)', '', '', '', $this->num($d['period']['netUsd'])]);
            $line([]);
        }

        $line(['CASH POSITION BY CURRENCY (current)']);
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

        // Company account book (consolidated USD, running balance).
        if (! empty($d['company'])) {
            return $this->companyHtml($d);
        }

        // Single-currency cash book (running balance).
        if (! empty($d['book'])) {
            return $this->bookHtml($d);
        }

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
                . '</td><td>' . $e($r['note'] ?? '') . '</td><td>' . $e($r['holder']) . '</td><td class="' . $cls . '">' . $r['dir']
                . '</td><td class="r ' . $cls . '">' . ($r['dir'] === 'In' ? '+' : '−') . $e($money($r['native'], $r['currency']))
                . '</td><td class="r">' . $e($money($r['usd'], 'USD')) . '</td></tr>';
        }
        if ($entryRows === '') { $entryRows = '<tr><td colspan="8" class="mut">No entries</td></tr>'; }

        $periodBlock = '';
        if (! empty($d['period'])) {
            $mvRows = '';
            foreach (self::CURRENCIES as $c) {
                $m = $d['period']['movement'][$c];
                $mvRows .= '<tr><td>' . $c . '</td><td class="r in">' . $e($money($m['in'], $c)) . '</td><td class="r out">'
                    . $e($money($m['out'], $c)) . '</td><td class="r">' . $e($money($m['net'], $c)) . '</td><td class="r">'
                    . $e($money($m['usd'], 'USD')) . '</td></tr>';
            }
            $mvRows .= '<tr class="tot"><td>Net (USD)</td><td class="r"></td><td class="r"></td><td class="r"></td><td class="r">'
                . $e($money($d['period']['netUsd'], 'USD')) . '</td></tr>';
            $periodBlock = '<h2>Movement in period (' . $e($d['period']['label']) . ')</h2>'
                . '<table><thead><tr><th>Currency</th><th class="r">In</th><th class="r">Out</th><th class="r">Net</th><th class="r">Net USD</th></tr></thead>'
                . '<tbody>' . $mvRows . '</tbody></table>';
        }
        $periodSub = ! empty($d['period']) ? (' &nbsp;·&nbsp; Period: ' . $e($d['period']['label'])) : '';

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
            <div class="sub">' . $e($d['generated']) . ' &nbsp;·&nbsp; ' . $e($d['scope']) . $periodSub . '</div>

            ' . $periodBlock . '

            <h2>Cash position by currency (current)</h2>
            <table><thead><tr><th>Currency</th><th class="r">Amount</th><th class="r">USD value</th></tr></thead>
            <tbody>' . $curRows . '</tbody></table>

            <h2>Cash by person</h2>
            <table><thead><tr><th>Held by</th><th class="r">USD</th><th class="r">UGX</th><th class="r">SSP</th><th class="r">Total (USD)</th></tr></thead>
            <tbody>' . $holderRows . '</tbody></table>

            <h2>' . (! empty($d['period']) ? 'Entries in period' : 'All cash entries') . ' (' . count($d['rows']) . ')</h2>
            <table><thead><tr><th>When</th><th>Ref</th><th>Category</th><th>Note</th><th>Held by</th><th>In/Out</th><th class="r">Amount</th><th class="r">USD</th></tr></thead>
            <tbody>' . $entryRows . '</tbody></table>

            <div class="foot">Generated by CloudBSS · figures use each entry\'s recorded exchange rate (cost basis)</div>
        </body></html>';
    }

    /** PDF company account book: every transaction valued in USD with a single running USD balance. */
    private function companyHtml(array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $b = $d['company'];
        $usd = fn (float $v) => $this->fmt($v, 'USD');
        $periodSub = ! empty($d['period']) ? (' &nbsp;·&nbsp; Period: ' . $e($d['period']['label'])) : '';

        $rows = '<tr class="tot"><td colspan="7">Opening balance</td><td class="r b">' . $e($usd($b['opening'])) . '</td></tr>';
        foreach ($b['entries'] as $r) {
            $cls = $r['dir'] === 'In' ? 'in' : 'out';
            $orig = $this->fmt($r['native'], $r['currency']);
            $rows .= '<tr><td>' . $e($r['when']) . '</td><td>' . $e($r['ref']) . '</td><td>' . $e($r['note'] ?: $r['category'])
                . '</td><td>' . $e($r['holder']) . '</td><td class="r mut">' . $e($orig) . '</td>'
                . '<td class="r in">' . ($r['dir'] === 'In' ? $e($usd(abs($r['usd']))) : '') . '</td>'
                . '<td class="r out">' . ($r['dir'] === 'Out' ? $e($usd(abs($r['usd']))) : '') . '</td>'
                . '<td class="r">' . $e($usd($r['balance'])) . '</td></tr>';
        }
        $rows .= '<tr class="tot"><td colspan="7">Closing balance</td><td class="r b">' . $e($usd($b['closing'])) . '</td></tr>';

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1f2933; font-size: 10.5px; }
            h1 { font-size: 18px; margin: 0 0 2px; color: #15803d; }
            .sub { color: #6b7280; font-size: 10px; margin-bottom: 14px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; background: #f3f4f6; font-size: 9px; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
            th.r { text-align: right; }
            td { padding: 5px 6px; border-bottom: 1px solid #eef1f4; }
            td.r { text-align: right; } td.b { font-weight: bold; } td.mut { color: #9ca3af; }
            tr.tot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
            .in { color: #15803d; } .out { color: #b91c1c; }
            .foot { margin-top: 16px; color: #9ca3af; font-size: 9px; text-align: center; }
        </style></head><body>
            <h1>' . $e($d['business']) . ' — ' . (! empty($d['holder']) ? $e($d['holder']) . ' — ' : '') . 'Company Account Cash Book (USD)</h1>
            <div class="sub">' . $e($d['generated']) . ' &nbsp;·&nbsp; ' . $e($d['scope']) . $periodSub . ' &nbsp;·&nbsp; every transaction in USD, one running balance</div>
            <table><thead><tr><th>Date</th><th>Ref</th><th>Description</th><th>Held by</th><th class="r">Original</th><th class="r">In (USD)</th><th class="r">Out (USD)</th><th class="r">Balance</th></tr></thead>
            <tbody>' . $rows . '</tbody></table>

            <h2 style="font-size:12px;margin:16px 0 6px;color:#111;border-bottom:2px solid #15803d;padding-bottom:3px">Summary (USD)</h2>
            <table style="width:auto"><tbody>
              <tr><td style="padding:4px 22px 4px 0">Opening balance</td><td class="r b">' . $e($usd($b['opening'])) . '</td></tr>
              <tr><td style="padding:4px 22px 4px 0" class="in">Total in</td><td class="r in">' . $e($usd($b['totalIn'])) . '</td></tr>
              <tr><td style="padding:4px 22px 4px 0" class="out">Total out</td><td class="r out">' . $e($usd($b['totalOut'])) . '</td></tr>
              <tr><td style="padding:4px 22px 4px 0">Net movement</td><td class="r b">' . $e($usd($b['net'])) . '</td></tr>
              <tr class="tot"><td style="padding:6px 22px 6px 0">Closing balance</td><td class="r b">' . $e($usd($b['closing'])) . '</td></tr>
            </tbody></table>
            <div class="foot">Generated by CloudBSS · company account cash book · USD values at each entry\'s recorded rate (cost basis)</div>
        </body></html>';
    }

    /** PDF for a single currency: opening balance, entries with a running balance, closing balance. */
    private function bookHtml(array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $b = $d['book']; $c = $b['currency'];
        $money = fn (float $v) => $this->fmt($v, $c);
        $periodSub = ! empty($d['period']) ? (' &nbsp;·&nbsp; Period: ' . $e($d['period']['label'])) : '';

        $rows = '<tr class="tot"><td colspan="6">Opening balance</td><td class="r b">' . $e($money($b['opening'])) . '</td></tr>';
        foreach ($b['entries'] as $r) {
            $cls = $r['dir'] === 'In' ? 'in' : 'out';
            $rows .= '<tr><td>' . $e($r['when']) . '</td><td>' . $e($r['ref']) . '</td><td>' . $e($r['category'])
                . '</td><td>' . $e($r['holder'])
                . '</td><td class="r in">' . ($r['dir'] === 'In' ? $e($money($r['native'])) : '')
                . '</td><td class="r out">' . ($r['dir'] === 'Out' ? $e($money($r['native'])) : '')
                . '</td><td class="r">' . $e($money($r['balance'])) . '</td></tr>';
        }
        $rows .= '<tr class="tot"><td colspan="6">Closing balance</td><td class="r b">' . $e($money($b['closing'])) . '</td></tr>';

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1f2933; font-size: 11px; }
            h1 { font-size: 19px; margin: 0 0 2px; color: #15803d; }
            .sub { color: #6b7280; font-size: 10.5px; margin-bottom: 14px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; background: #f3f4f6; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 5px 7px; border-bottom: 1px solid #e5e7eb; }
            th.r { text-align: right; }
            td { padding: 5px 7px; border-bottom: 1px solid #eef1f4; }
            td.r { text-align: right; } td.b { font-weight: bold; }
            tr.tot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
            .in { color: #15803d; } .out { color: #b91c1c; }
            .foot { margin-top: 18px; color: #9ca3af; font-size: 9px; text-align: center; }
        </style></head><body>
            <h1>' . $e($d['business']) . ' — ' . (! empty($d['holder']) ? $e($d['holder']) . ' — ' : '') . $e($c) . ' Cashbook</h1>
            <div class="sub">' . $e($d['generated']) . ' &nbsp;·&nbsp; ' . $e($d['scope']) . $periodSub . '</div>
            <table><thead><tr><th>Date</th><th>Ref</th><th>Category</th><th>Held by</th><th class="r">In</th><th class="r">Out</th><th class="r">Balance</th></tr></thead>
            <tbody>' . $rows . '</tbody></table>
            <div class="foot">Generated by CloudBSS · single-currency cash book with running balance</div>
        </body></html>';
    }

    private function num(float $v): string { return number_format($v, 2, '.', ''); }

    private function fmt(float $v, string $c): string
    {
        $n = number_format($v, ($c === 'USD' ? 2 : 0), '.', ',');
        return $c === 'USD' ? ('$' . $n) : ($n . ' ' . $c);
    }
}
