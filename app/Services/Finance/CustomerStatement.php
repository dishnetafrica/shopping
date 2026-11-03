<?php

namespace App\Services\Finance;

/**
 * Customer account-statement PDF — a running debit/credit ledger (invoices raised vs payments
 * received) with a closing balance, rendered from the SAME consolidated statement the v3 360 shows
 * (CustomerInsight::statement). Read-only; the caller assembles the data, this only renders.
 * Mirrors SupplierStatement's dompdf mechanism so downloads work without the public storage URL.
 */
final class CustomerStatement
{
    /** @param array<string,mixed> $d business, customer, meta, generated, currency, rows[], closingText */
    public function pdf(array $d): ?string
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

    /** @param array<string,mixed> $d */
    private function html(array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);

        $rows = '';
        foreach (($d['rows'] ?? []) as $r) {
            $rows .= '<tr><td>' . $e($r['when'] ?? '') . '</td><td>' . $e($r['desc'] ?? '')
                . '</td><td class="r out">' . $e($r['charge'] ?? '')
                . '</td><td class="r in">' . $e($r['payment'] ?? '')
                . '</td><td class="r b">' . $e($r['balance'] ?? '') . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:18px">No account activity yet.</td></tr>';
        }
        $rows .= '<tr class="tot"><td colspan="4">Balance due</td><td class="r b">' . $e($d['closingText'] ?? '') . '</td></tr>';

        // --- Purchases (itemised, grouped by invoice) + Spend by product (summary) ---
        $purchasesHtml = '';
        foreach (($d['purchases'] ?? []) as $inv) {
            $purchasesHtml .= '<tr class="ih"><td colspan="3">' . $e($inv['date'] ?? '') . ' &nbsp;·&nbsp; ' . $e($inv['no'] ?? '') . '</td></tr>';
            foreach (($inv['items'] ?? []) as $it) {
                $purchasesHtml .= '<tr><td>' . $e($it['name'] ?? '') . '</td><td class="r">' . $e($it['qty'] ?? '')
                    . '</td><td class="r">' . $e($it['amountText'] ?? '') . '</td></tr>';
            }
        }
        $productsHtml = '';
        foreach (($d['products'] ?? []) as $p) {
            $productsHtml .= '<tr><td>' . $e($p['name'] ?? '') . '</td><td class="r">' . $e($p['qty'] ?? '')
                . '</td><td class="r">' . $e($p['totalText'] ?? '') . '</td></tr>';
        }
        $extra = '';
        if ($productsHtml !== '') {
            $extra = '<h2>Purchases</h2>'
                . '<table><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead><tbody>'
                . $purchasesHtml . '</tbody></table>'
                . '<h2>Spend by product</h2>'
                . '<table><thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Total spent</th></tr></thead><tbody>'
                . $productsHtml
                . '<tr class="tot"><td>Total</td><td class="r"></td><td class="r b">' . $e($d['productsTotalText'] ?? '') . '</td></tr>'
                . '</tbody></table>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1f2933; font-size: 11px; }
            h1 { font-size: 18px; margin: 0 0 2px; color: #15803d; }
            h2 { font-size: 13px; margin: 20px 0 6px; color: #15803d; page-break-after: avoid; }
            .sub { color: #6b7280; font-size: 10.5px; margin-bottom: 14px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; background: #f3f4f6; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 5px 7px; border-bottom: 1px solid #e5e7eb; }
            th.r { text-align: right; }
            td { padding: 5px 7px; border-bottom: 1px solid #eef1f4; } td.r { text-align: right; } td.b { font-weight: bold; }
            tr.tot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
            tr.ih td { font-weight: bold; background: #f9fafb; color: #374151; }
            .in { color: #15803d; } .out { color: #b91c1c; }
            .foot { margin-top: 16px; color: #9ca3af; font-size: 9px; text-align: center; }
        </style></head><body>
            <h1>' . $e($d['business'] ?? 'Company') . ' — Account Statement</h1>
            <div class="sub">' . $e($d['customer'] ?? '') . (($d['meta'] ?? '') ? ' &nbsp;·&nbsp; ' . $e($d['meta']) : '') . ' &nbsp;·&nbsp; ' . $e($d['generated'] ?? '') . '</div>
            <table><thead><tr><th>Date</th><th>Description</th><th class="r">Charge</th><th class="r">Payment</th><th class="r">Balance</th></tr></thead>
            <tbody>' . $rows . '</tbody></table>
            ' . $extra . '
            <div class="foot">Amounts shown in ' . $e($d['currency'] ?? 'USD') . ' (report currency). Balance due ' . $e($d['closingText'] ?? '') . '.</div>
        </body></html>';
    }
}
