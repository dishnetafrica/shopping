<?php

namespace App\Services\Purchasing;

use App\Models\PurchaseOrder;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a branded Local Purchase Order (LPO) PDF from a PurchaseOrder + its lines and returns the
 * bytes (base64 for WhatsApp) plus a saved public copy. Uses dompdf if installed; callers guard on
 * available() and fall back gracefully if it is not.
 *
 * The layout is a standard LPO: company header (tenant brand settings), supplier "To" block, order &
 * delivery detail, a line grid (item / ordered qty / unit cost / line total), an expected total, and
 * a terms + authorised-signature footer. Every brand value comes from tenant settings, so any tenant
 * gets the same document with their own details. This is a purchasing DOCUMENT — the amounts are the
 * expected/agreed cost, not a posted ledger figure.
 */
class LpoPdfService
{
    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /**
     * @return array|null ['no'=>, 'b64'=>, 'path'=>, 'url'=>, 'fileName'=>, 'total'=>, 'currency'=>]
     */
    public function generate(Tenant $tenant, PurchaseOrder $po): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $po->loadMissing('lines');

        $no  = (string) ($po->po_no ?: ('LPO-' . $po->id));
        $cur = strtoupper((string) ($po->currency ?: $tenant->setting('currency', 'UGX')));

        $total = 0.0;
        foreach ($po->lines as $l) {
            $total += (float) $l->ordered_qty * (float) $l->unit_cost;
        }
        $total = round($total, 2);

        $html = $this->html($tenant, $po, $cur, $total);

        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            \Log::warning('LpoPdfService render failed: ' . $e->getMessage());
            return null;
        }

        $fileName = 'LPO-' . preg_replace('/[^A-Za-z0-9\-]+/', '', $no) . '.pdf';
        $path     = "lpos/{$tenant->id}/{$fileName}";
        try { Storage::disk('public')->put($path, $pdf); } catch (\Throwable $e) { /* record copy is best-effort */ }

        return [
            'no'       => $no,
            'b64'      => base64_encode($pdf),
            'path'     => $path,
            'url'      => $this->url($path),
            'fileName' => $fileName,
            'total'    => $total,
            'currency' => $cur,
        ];
    }

    private function url(string $path): string
    {
        try { return Storage::disk('public')->url($path); } catch (\Throwable $e) { return ''; }
    }

    private function money(float $v, string $cur): string
    {
        $dec = 2;
        try { $dec = \App\Services\Pricing::decimalsForCurrency($cur); } catch (\Throwable $e) { $dec = 2; }

        return $cur . ' ' . number_format($v, $dec, '.', ',');
    }

    private function html(Tenant $tenant, PurchaseOrder $po, string $cur, float $total): string
    {
        $company = e((string) $tenant->name);
        $address = trim((string) $tenant->setting('address', ''));
        $email   = trim((string) $tenant->setting('public_email', $tenant->setting('email', '')));
        $cphone  = trim((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        $web     = trim((string) $tenant->setting('website', ''));
        $tin     = trim((string) $tenant->setting('tin', ''));

        $metaParts = array_filter([
            $address !== '' ? nl2br(e($address)) : '',
            $cphone  !== '' ? 'Tel: ' . e($cphone) : '',
            $email   !== '' ? 'Email: ' . e($email) : '',
            $web     !== '' ? e($web) : '',
            $tin     !== '' ? 'TIN: ' . e($tin) : '',
        ], fn ($x) => $x !== '');
        $companyMeta = implode(' &nbsp;·&nbsp; ', $metaParts);

        $supName = e((string) ($po->supplier_name ?: 'Supplier'));
        $orderDate = $po->order_date ? $po->order_date->format('d M Y') : ($po->created_at ? $po->created_at->format('d M Y') : '');
        $expDate   = $po->expected_date ? $po->expected_date->format('d M Y') : '';
        $deliverTo = trim((string) ($po->delivery_address ?: $address));

        // Lines
        $rowsHtml = '';
        $i = 0; $anyPriced = false;
        foreach ($po->lines as $l) {
            $i++;
            $qty = (float) $l->ordered_qty;
            $unit = (float) $l->unit_cost;
            if ($unit > 0) { $anyPriced = true; }
            $line = round($qty * $unit, 2);
            $rowsHtml .= '<tr>'
                . '<td class="c">' . $i . '</td>'
                . '<td>' . e((string) $l->name) . (trim((string) $l->remarks) !== '' ? '<div class="rm">' . e((string) $l->remarks) . '</div>' : '') . '</td>'
                . '<td class="r">' . $this->qty($qty) . '</td>'
                . '<td class="r">' . ($unit > 0 ? number_format($unit, 2, '.', ',') : '—') . '</td>'
                . '<td class="r">' . ($unit > 0 ? number_format($line, 2, '.', ',') : '—') . '</td>'
                . '</tr>';
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5" class="c" style="padding:16px;color:#6b7280;">No items on this order.</td></tr>';
        }

        $termsRaw = trim((string) ($po->terms ?: $tenant->setting('lpo_terms', '')));
        $termsHtml = $termsRaw !== '' ? nl2br(e($termsRaw)) : '';
        $notesRaw = trim((string) $po->notes);
        $notesHtml = $notesRaw !== '' ? nl2br(e($notesRaw)) : '';

        // Authorised-signature stamp — reuse the quotation stamp toggle (owners who stamp quotes stamp LPOs).
        $stamp = '';
        try { $stamp = \App\Services\Documents\DocumentStamp::img($tenant, 'quotation', 96); } catch (\Throwable $e) { $stamp = ''; }

        // A price-less LPO ("please supply these quantities") is valid — the cost comes on the supplier's
        // invoice. So don't print a misleading "CUR 0" total; say the prices are to be confirmed instead.
        $totalLabel = $anyPriced ? ('Total (' . e($cur) . ')') : 'Total';
        $totalCell  = $anyPriced
            ? e($this->money($total, $cur))
            : '<span style="font-size:11px;color:#6b7280;font-weight:600;">Prices to be confirmed</span>';

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { margin: 0; color: #111827; font-size: 12px; }
            .wrap { padding: 28px 32px; }
            .hd { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 14px; }
            .co { font-size: 20px; font-weight: bold; }
            .cometa { font-size: 9.5px; color: #4b5563; margin-top: 3px; line-height: 1.5; }
            .title { text-align: right; }
            .title .t { font-size: 22px; font-weight: bold; letter-spacing: 1px; color: #111827; }
            .title .n { font-size: 12px; color: #4b5563; margin-top: 2px; }
            table.meta { width: 100%; margin-bottom: 14px; }
            table.meta td { vertical-align: top; width: 50%; padding-right: 12px; }
            .lbl { font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; margin-bottom: 3px; }
            .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 9px 11px; }
            .box .big { font-size: 13px; font-weight: bold; }
            .kv { font-size: 11px; line-height: 1.7; }
            .kv b { color: #374151; }
            table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
            table.items th { background: #111827; color: #fff; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; padding: 7px 8px; text-align: left; }
            table.items th.r { text-align: right; }
            table.items td { border-bottom: 1px solid #eef0f2; padding: 7px 8px; font-size: 11.5px; vertical-align: top; }
            table.items td.r { text-align: right; }
            table.items td.c { text-align: center; color: #6b7280; }
            .rm { font-size: 9.5px; color: #6b7280; margin-top: 2px; }
            .tot { width: 100%; margin-top: 10px; }
            .tot td { padding: 4px 8px; }
            .tot .tl { text-align: right; font-size: 13px; font-weight: bold; }
            .tot .tv { text-align: right; font-size: 15px; font-weight: bold; width: 180px; border-top: 2px solid #111827; }
            .foot { margin-top: 22px; }
            .foot .lbl { margin-bottom: 6px; }
            .terms { font-size: 10px; color: #4b5563; line-height: 1.6; }
            .sig { margin-top: 30px; }
            .sigbox { border-top: 1px solid #9ca3af; width: 220px; padding-top: 4px; font-size: 10px; color: #6b7280; }
            .stamp { margin-bottom: 4px; height: 96px; }
            .exp { font-size: 9px; color: #9ca3af; margin-top: 26px; text-align: center; }
        </style></head><body><div class="wrap">

            <table style="width:100%"><tr>
                <td style="vertical-align:top;">
                    <div class="co">' . $company . '</div>
                    <div class="cometa">' . $companyMeta . '</div>
                </td>
                <td class="title" style="vertical-align:top;">
                    <div class="t">PURCHASE ORDER</div>
                    <div class="n">' . e((string) ($po->po_no ?: ('LPO-' . $po->id))) . '</div>
                </td>
            </tr></table>
            <div class="hd"></div>

            <table class="meta"><tr>
                <td>
                    <div class="lbl">Supplier</div>
                    <div class="box"><div class="big">' . $supName . '</div></div>
                </td>
                <td>
                    <div class="kv">'
                        . ($orderDate !== '' ? '<div><b>Order date:</b> ' . e($orderDate) . '</div>' : '')
                        . ($expDate !== '' ? '<div><b>Expected delivery:</b> ' . e($expDate) . '</div>' : '')
                        . ($deliverTo !== '' ? '<div><b>Deliver to:</b> ' . nl2br(e($deliverTo)) . '</div>' : '')
                    . '</div>
                </td>
            </tr></table>

            <table class="items">
                <thead><tr>
                    <th style="width:28px;">#</th>
                    <th>Item</th>
                    <th class="r" style="width:80px;">Qty</th>
                    <th class="r" style="width:90px;">Unit cost</th>
                    <th class="r" style="width:100px;">Amount</th>
                </tr></thead>
                <tbody>' . $rowsHtml . '</tbody>
            </table>

            <table class="tot"><tr>
                <td class="tl">' . $totalLabel . '</td>
                <td class="tv">' . $totalCell . '</td>
            </tr></table>

            ' . ($notesHtml !== '' ? '<div class="foot"><div class="lbl">Notes</div><div class="terms">' . $notesHtml . '</div></div>' : '') . '
            ' . ($termsHtml !== '' ? '<div class="foot"><div class="lbl">Terms &amp; conditions</div><div class="terms">' . $termsHtml . '</div></div>' : '') . '

            <table style="width:100%" class="sig"><tr>
                <td style="vertical-align:bottom;">
                    <div class="sigbox">Prepared by &nbsp;·&nbsp; ' . e((string) ($po->created_by ?: $tenant->name)) . '</div>
                </td>
                <td style="text-align:right;vertical-align:bottom;">'
                    . ($stamp !== '' ? '<div class="stamp">' . $stamp . '</div>' : '')
                    . '<div class="sigbox" style="margin-left:auto;">Authorised signature</div>
                </td>
            </tr></table>

            <div class="exp">This is a purchase order — the amounts are the agreed/expected cost, subject to the supplier invoice on delivery.</div>

        </div></body></html>';
    }

    private function qty($v): string
    {
        $f = (float) $v;

        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.') ?: '0';
    }
}
