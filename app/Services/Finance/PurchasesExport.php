<?php

namespace App\Services\Finance;

use App\Models\OrderCost;
use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Purchases register export (PDF + CSV) — every purchase (bill) across all suppliers, with the
 * native amount, USD (cost basis), cash/credit and outstanding. Filterable by date range, currency
 * and supplier. Read-only; the same not-void basis the Purchasing screen uses, so it always ties out.
 */
final class PurchasesExport
{
    /**
     * @param array{from?:string,to?:string,currency?:string,supplier_id?:int} $f
     */
    public function collect(Tenant $t, array $f = []): array
    {
        $q = OrderCost::where('tenant_id', $t->id)
            ->where('stage', 'purchase')->where('status', '!=', 'void');

        $from = trim((string) ($f['from'] ?? ''));
        $to = trim((string) ($f['to'] ?? ''));
        $currency = strtoupper(trim((string) ($f['currency'] ?? '')));
        $supplierId = (int) ($f['supplier_id'] ?? 0);

        if ($from !== '') { $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()); }
        if ($to !== '') { $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()); }
        if ($currency !== '') { $q->where('currency', $currency); }
        if ($supplierId > 0) { $q->where('supplier_id', $supplierId); }

        $items = $q->orderBy('created_at')->orderBy('id')
            ->get(['id', 'order_no', 'note', 'currency', 'native_amount', 'usd_amount', 'rate_to_usd', 'pay_status', 'paid_native', 'supplier_id', 'supplier_name', 'created_at']);

        // resolve supplier names in one query
        $names = Supplier::where('tenant_id', $t->id)->pluck('name', 'id');

        $rows = []; $totalUsd = 0.0; $owedUsd = 0.0; $byCur = [];
        foreach ($items as $c) {
            $native = (float) $c->native_amount;
            $usd = (float) $c->usd_amount ?: OrderCost::toUsd($native, (float) $c->rate_to_usd);
            $out = max(0.0, $native - (float) ($c->paid_native ?? 0));
            $outUsd = ($out > 0 && $c->rate_to_usd > 0) ? $out / (float) $c->rate_to_usd : 0.0;
            $isCredit = strtolower((string) $c->pay_status) === 'credit';
            $ccur = (string) ($c->currency ?: '');
            $totalUsd += $usd; $owedUsd += $outUsd;
            $byCur[$ccur] = ($byCur[$ccur] ?? 0) + $native;
            $rows[] = [
                'when' => $c->created_at ? Carbon::parse($c->created_at)->format('Y-m-d') : '',
                'ref' => (string) ($c->order_no ?: ('PUR-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT))),
                'supplier' => (string) ($names[$c->supplier_id] ?? $c->supplier_name ?: '—'),
                'item' => (string) ($c->note ?: 'Purchase'),
                'currency' => $ccur,
                'native' => round($native, 2),
                'usd' => round($usd, 2),
                'pay' => $isCredit ? ($out > 0 ? 'Credit (owing)' : 'Credit (paid)') : 'Cash',
                'owed' => round($outUsd, 2),
            ];
        }

        $supName = $supplierId > 0 ? (string) ($names[$supplierId] ?? '') : '';

        return [
            'business' => (string) ($t->name ?: 'Company'),
            'generated' => now()->format('d M Y, H:i'),
            'from' => $from, 'to' => $to, 'currency' => $currency, 'supplier' => $supName,
            'count' => count($rows),
            'totalUsd' => round($totalUsd, 2),
            'owedUsd' => round($owedUsd, 2),
            'byCurrency' => $byCur,
            'rows' => $rows,
        ];
    }

    public function csv(array $d): string
    {
        $out = fopen('php://temp', 'r+');
        // context header
        $scope = array_filter([
            $d['from'] || $d['to'] ? ('Period: ' . ($d['from'] ?: '…') . ' to ' . ($d['to'] ?: '…')) : '',
            $d['currency'] ? ('Currency: ' . $d['currency']) : '',
            $d['supplier'] ? ('Supplier: ' . $d['supplier']) : '',
        ]);
        fputcsv($out, [$d['business'] . ' — Purchases register']);
        fputcsv($out, [implode('  ·  ', $scope) ?: 'All purchases', 'Generated ' . $d['generated']]);
        fputcsv($out, []);
        fputcsv($out, ['Date', 'Ref', 'Supplier', 'Item', 'Currency', 'Amount (native)', 'Amount (USD)', 'Pay', 'Still owed (USD)']);
        foreach ($d['rows'] as $r) {
            fputcsv($out, [$r['when'], $r['ref'], $r['supplier'], $r['item'], $r['currency'], number_format($r['native'], 2, '.', ''), number_format($r['usd'], 2, '.', ''), $r['pay'], $r['owed'] > 0 ? number_format($r['owed'], 2, '.', '') : '']);
        }
        fputcsv($out, []);
        fputcsv($out, ['', '', '', '', 'TOTAL (USD)', '', number_format($d['totalUsd'], 2, '.', ''), '', number_format($d['owedUsd'], 2, '.', '')]);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF" . $csv; // BOM so Excel reads UTF-8
    }

    public function pdf(Tenant $t, array $d): ?string
    {
        if (! class_exists(\Dompdf\Dompdf::class)) { return null; }
        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
            $dompdf->setPaper('A4', 'landscape');
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
        $nat = fn ($cur, $v) => ($cur ? $e($cur) . ' ' : '') . number_format((float) $v, 2);

        $scope = array_filter([
            $d['from'] || $d['to'] ? ('Period ' . ($d['from'] ?: '…') . ' → ' . ($d['to'] ?: '…')) : '',
            $d['currency'] ? ('Currency ' . $d['currency']) : '',
            $d['supplier'] ? ('Supplier ' . $d['supplier']) : '',
        ]);

        $rows = '';
        foreach ($d['rows'] as $r) {
            $rows .= '<tr><td>' . $e($r['when']) . '</td><td>' . $e($r['ref']) . '</td><td>' . $e($r['supplier'])
                . '</td><td>' . $e($r['item']) . '</td><td class="r">' . $e($nat($r['currency'], $r['native']))
                . '</td><td class="r">' . $e($usd($r['usd'])) . '</td><td>' . $e($r['pay'])
                . '</td><td class="r out">' . ($r['owed'] > 0 ? $e($usd($r['owed'])) : '') . '</td></tr>';
        }
        if ($rows === '') { $rows = '<tr><td colspan="8" class="mut">No purchases in this range.</td></tr>'; }

        $byCur = '';
        foreach ($d['byCurrency'] as $cur => $sum) {
            if ($cur === '') { continue; }
            $byCur .= '<span class="chip">' . $e($cur) . ' ' . $e(number_format((float) $sum, 2)) . '</span> ';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1f2933; font-size: 10.5px; }
            h1 { font-size: 17px; margin: 0 0 2px; color: #15803d; }
            .sub { color: #6b7280; font-size: 10px; margin-bottom: 6px; }
            .chips { margin-bottom: 12px; } .chip { display: inline-block; background: #f3f4f6; border-radius: 10px; padding: 2px 9px; font-size: 10px; color: #374151; margin-right: 4px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; background: #f3f4f6; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 5px 7px; border-bottom: 1px solid #e5e7eb; }
            th.r { text-align: right; }
            td { padding: 5px 7px; border-bottom: 1px solid #eef1f4; } td.r { text-align: right; } td.mut { color: #9ca3af; text-align: center; padding: 24px; }
            tr.tot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
            .out { color: #b91c1c; }
            .foot { margin-top: 14px; color: #9ca3af; font-size: 9px; text-align: center; }
        </style></head><body>
            <h1>' . $e($d['business']) . ' — Purchases register</h1>
            <div class="sub">' . ($scope ? $e(implode('  ·  ', $scope)) : 'All purchases') . ' &nbsp;·&nbsp; ' . $e($d['count']) . ' purchases &nbsp;·&nbsp; ' . $e($d['generated']) . '</div>
            <div class="chips">' . $byCur . '</div>
            <table><thead><tr><th>Date</th><th>Ref</th><th>Supplier</th><th>Item</th><th class="r">Amount</th><th class="r">USD</th><th>Pay</th><th class="r">Still owed</th></tr></thead>
            <tbody>' . $rows . '<tr class="tot"><td colspan="5">Total purchased (USD)</td><td class="r">' . $e($usd($d['totalUsd'])) . '</td><td>Still owed</td><td class="r out">' . $e($usd($d['owedUsd'])) . '</td></tr></tbody></table>
            <div class="foot">USD at each entry\'s recorded rate (cost basis) · voided entries excluded</div>
        </body></html>';
    }
}
