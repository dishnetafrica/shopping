<?php

namespace App\Services\Sales;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * DELIVERY NOTE PDF — the paper the customer signs when goods arrive (backlog ask 2026-07-30).
 *
 * Deliberately shows QUANTITIES ONLY, never prices: a delivery note is proof of goods received
 * in good condition, not a bill (the invoice handles money). Signature blocks for the receiver
 * AND the deliverer, plus an authorized-signatory line — matching the standard trade format.
 *
 * Pure renderer: the caller (V3 DocumentController) assembles the data array from the Order,
 * so this class stays framework-light and testable:
 *   pdf($tenant, [
 *     'order_no' => 'FS-1042', 'order_date' => '30 Jul 2026', 'customer_name' => '…',
 *     'customer_phone' => '…', 'deliver_to' => '…', 'notes' => '…',
 *     'items' => [['name' => '…', 'qty' => 10, 'unit' => 'carton'], …],
 *   ])
 */
final class DeliveryNoteRenderer
{
    private const BRAND = '#16a34a';
    private const BRAND_DARK = '#0f7a37';
    private const INK = '#0f172a';

    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /** @return array{path:string,b64:string,fileName:string}|null */
    public function pdf(Tenant $tenant, array $d): ?array
    {
        if (! $this->available() || empty($d['items'])) {
            return null;
        }

        try {
            $dompdf = new \Dompdf\Dompdf([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'isFontSubsettingEnabled' => true,
            ]);
            $dompdf->loadHtml($this->html($tenant, $d));
            $dompdf->setPaper('A4');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            \Log::warning('DeliveryNoteRenderer failed: ' . $e->getMessage());

            return null;
        }

        $no = preg_replace('/[^A-Za-z0-9\-]+/', '', (string) ($d['order_no'] ?? 'order'));
        $fileName = 'DeliveryNote-' . ($no ?: 'order') . '.pdf';
        $path = 'delivery-notes/' . $tenant->id . '/' . $fileName;
        try { Storage::disk('public')->put($path, $pdf); } catch (\Throwable $e) { /* best-effort */ }

        return ['path' => $path, 'b64' => base64_encode($pdf), 'fileName' => $fileName];
    }

    private function html(Tenant $tenant, array $d): string
    {
        $brand = self::BRAND; $brandD = self::BRAND_DARK; $ink = self::INK;
        $company = e((string) $tenant->name);
        $addr = trim(implode(', ', array_filter([
            (string) $tenant->setting('address', ''),
            (string) $tenant->setting('city', ''),
            (string) $tenant->setting('country', ''),
        ])));
        $tin = trim((string) $tenant->setting('tin', ''));
        $phone = trim((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        $logo = (string) $tenant->setting('logo', '');

        $logoBlock = $logo !== ''
            ? '<img src="' . e($logo) . '" style="height:42px;max-width:150px">'
                . '<div style="font-size:15px;font-weight:bold;margin-top:5px">' . $company . '</div>'
            : '<div style="font-size:21px;font-weight:bold;color:' . $brandD . '">' . $company . '</div>';

        $rows = '';
        $i = 0;
        foreach ((array) $d['items'] as $it) {
            $i++;
            $qty = (float) ($it['qty'] ?? 0);
            $qtyTxt = ($qty == (int) $qty ? (string) (int) $qty : (string) $qty)
                . (! empty($it['unit']) ? ' ' . e((string) $it['unit']) : '');
            $rows .= '<tr>'
                . '<td style="text-align:center;color:#94a3b8">' . $i . '</td>'
                . '<td>' . e((string) ($it['name'] ?? '')) . '</td>'
                . '<td style="text-align:right;font-weight:bold;white-space:nowrap">' . $qtyTxt . '</td>'
                . '<td style="width:70px"></td>'
                . '</tr>';
        }

        $meta = '<table class="meta">'
            . '<tr><td class="k">Order No.</td><td class="v">' . e((string) ($d['order_no'] ?? '')) . '</td></tr>'
            . '<tr><td class="k">Order date</td><td class="v">' . e((string) ($d['order_date'] ?? '')) . '</td></tr>'
            . '<tr><td class="k">Delivery date</td><td class="v">' . e((string) ($d['delivery_date'] ?? now()->format('d M Y'))) . '</td></tr>'
            . '</table>';

        $cust = '<div class="clabel">DELIVER TO</div>'
            . '<div style="font-size:13px;font-weight:bold">' . e((string) ($d['customer_name'] ?? '') ?: 'Customer') . '</div>'
            . (! empty($d['customer_phone']) ? '<div style="font-size:10.5px;color:#475569;margin-top:2px">' . e((string) $d['customer_phone']) . '</div>' : '')
            . (! empty($d['deliver_to']) ? '<div style="font-size:10.5px;color:#475569;margin-top:2px">' . e((string) $d['deliver_to']) . '</div>' : '');

        $notes = trim((string) ($d['notes'] ?? ''));

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 34px 36px 40px; }
*{font-family:"DejaVu Sans",Arial,sans-serif;}
body{margin:0;color:{$ink};font-size:11px;}
.top{width:100%;border-collapse:collapse;border-bottom:3px solid {$brand};}
.top td{padding:0 0 12px;vertical-align:top;}
.dtitle{font-size:24px;font-weight:bold;letter-spacing:1px;color:{$brandD};text-align:right;}
.sub{font-size:9.5px;color:#64748b;margin-top:3px;}
table.meta{border-collapse:collapse;margin-top:4px;width:100%;}
table.meta td{padding:3px 0;font-size:11px;}
table.meta .k{color:#64748b;width:90px;}
table.meta .v{font-weight:bold;text-align:right;}
.clabel{font-size:8.5px;font-weight:bold;letter-spacing:1.5px;color:{$brandD};margin-bottom:4px;}
table.items{width:100%;border-collapse:collapse;margin-top:16px;}
table.items th{background:{$brandD};color:#ffffff;font-size:10px;letter-spacing:.5px;padding:8px 10px;text-align:left;}
table.items th.q{text-align:right;} table.items th.n{text-align:center;width:34px;} table.items th.c{width:70px;text-align:center;}
table.items td{padding:7px 10px;border-bottom:1px solid #e2e8f0;font-size:11px;vertical-align:top;}
.goods{margin-top:16px;font-size:11px;font-weight:bold;}
table.sig{width:100%;border-collapse:separate;border-spacing:14px 0;margin-top:26px;}
table.sig td{width:33%;vertical-align:bottom;padding:0;}
.sigbox{border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;height:86px;}
.siglabel{font-size:8.5px;font-weight:bold;letter-spacing:1px;color:#64748b;}
.sigline{border-bottom:1px solid #94a3b8;height:34px;margin-top:8px;}
.sigsub{font-size:8px;color:#94a3b8;margin-top:4px;}
.foot{margin-top:22px;border-top:1px solid #e2e8f0;padding-top:8px;font-size:8.5px;color:#94a3b8;text-align:center;}
.notes{margin-top:12px;background:#f8faf8;border:1px solid #eef2ee;border-radius:8px;padding:9px 12px;font-size:10px;color:#475569;}
</style></head><body>

<table class="top"><tr>
<td style="width:55%">{$logoBlock}
<div class="sub">{$addr}</div>
HTML
        . ($tin !== '' ? '<div class="sub">TIN: ' . e($tin) . '</div>' : '')
        . ($phone !== '' ? '<div class="sub">' . e($phone) . '</div>' : '')
        . <<<HTML
</td>
<td style="width:45%"><div class="dtitle">DELIVERY NOTE</div>{$meta}</td>
</tr></table>

<table style="width:100%;border-collapse:collapse;margin-top:14px"><tr>
<td style="width:60%;vertical-align:top">{$cust}</td>
<td style="width:40%;vertical-align:top;text-align:right;font-size:9px;color:#94a3b8">Quantities only — prices are on the invoice.</td>
</tr></table>

<table class="items">
<tr><th class="n">#</th><th>Product</th><th class="q">Quantity</th><th class="c">Received ✓</th></tr>
{$rows}
</table>

<div class="goods">Above mentioned items received in good condition.</div>
HTML
        . ($notes !== '' ? '<div class="notes"><b>Notes:</b> ' . e($notes) . '</div>' : '')
        . <<<HTML

<table class="sig"><tr>
<td><div class="sigbox"><div class="siglabel">RECEIVED BY (CUSTOMER)</div><div class="sigline"></div><div class="sigsub">Name, signature &amp; date</div></div></td>
<td><div class="sigbox"><div class="siglabel">DELIVERED BY</div><div class="sigline"></div><div class="sigsub">Name, signature &amp; date</div></div></td>
<td><div class="sigbox"><div class="siglabel">AUTHORIZED SIGNATORY</div><div class="sigline"></div><div class="sigsub">For {$company}</div></div></td>
</tr></table>

<div class="foot">{$company} — this delivery note confirms receipt of goods only; it is not a tax invoice or receipt of payment.</div>
</body></html>
HTML;
    }
}
