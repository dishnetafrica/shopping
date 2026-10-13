<?php

namespace App\Services\Customs;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * PACKING LIST PDF — the shipment's contents for the clearing agent / transporter.
 * Quantities, packages and weights only — NEVER prices (that is the commercial invoice's job).
 *
 * Prints per line the part number, HS code, quantity, package count, net & gross weight and
 * origin, plus shipment totals (packages, net/gross weight) and the carton marks. Read-only;
 * the caller assembles $d via CustomsLineBuilder.
 */
final class PackingListRenderer
{
    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /** @return array{path:string,b64:string,fileName:string}|null */
    public function pdf(Tenant $tenant, array $d): ?array
    {
        if (! $this->available() || empty($d['lines'])) {
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
            \Log::warning('PackingListRenderer failed: ' . $e->getMessage());

            return null;
        }

        $ref = preg_replace('/[^A-Za-z0-9\-]+/', '', (string) ($d['doc_ref'] ?? 'pl'));
        $fileName = 'PackingList-' . ($ref ?: 'pl') . '.pdf';
        $path = 'customs/' . $tenant->id . '/' . $fileName;
        try { Storage::disk('public')->put($path, $pdf); } catch (\Throwable $e) { /* best-effort */ }

        return ['path' => $path, 'b64' => base64_encode($pdf), 'fileName' => $fileName];
    }

    private function html(Tenant $t, array $d): string
    {
        [$accent, $accentD, $ink] = CustomsDocStyle::palette($t);

        $expName = (string) ($t->setting('exporter_name', '') ?: $t->name);
        $expAddr = (string) ($t->setting('exporter_address', '') ?: trim(implode(', ', array_filter([
            (string) $t->setting('address', ''), (string) $t->setting('city', ''), (string) $t->setting('country', ''),
        ]))));
        $logo = (string) $t->setting('logo', '');

        $logoBlock = $logo !== ''
            ? '<img src="' . e($logo) . '" style="height:40px;max-width:150px"><div style="font-size:14px;font-weight:bold;margin-top:4px">' . e($expName) . '</div>'
            : '<div style="font-size:20px;font-weight:bold;color:' . $accentD . '">' . e($expName) . '</div>';

        $rows = '';
        $i = 0;
        foreach ((array) $d['lines'] as $l) {
            $i++;
            $qty = (float) $l['qty'];
            $qtyTxt = ($qty == (int) $qty ? (string) (int) $qty : (string) $qty) . (! empty($l['unit']) ? ' ' . e((string) $l['unit']) : '');
            $netEach = CustomsDocStyle::kg((int) $l['net_g']);
            $grossEach = CustomsDocStyle::kg((int) ($l['gross_g'] ?: $l['net_g']));
            $rows .= '<tr>'
                . '<td class="n">' . $i . '</td>'
                . '<td>' . e((string) $l['name']) . '</td>'
                . '<td>' . e((string) ($l['part_no'] ?: '—')) . '</td>'
                . '<td>' . e((string) ($l['hs_code'] ?: '—')) . '</td>'
                . '<td class="c">' . $qtyTxt . '</td>'
                . '<td class="c">' . (int) round($qty) . '</td>'
                . '<td class="r">' . $netEach . '</td>'
                . '<td class="r">' . $grossEach . '</td>'
                . '<td>' . e((string) ($l['origin'] ?: '—')) . '</td>'
                . '</tr>';
        }

        $meta = '<table class="meta">'
            . '<tr><td class="k">Packing List No.</td><td class="v">PL-' . e((string) ($d['doc_ref'] ?? '')) . '</td></tr>'
            . '<tr><td class="k">Date</td><td class="v">' . e((string) ($d['date'] ?? '')) . '</td></tr>'
            . '<tr><td class="k">Invoice Ref</td><td class="v">CI-' . e((string) ($d['doc_ref'] ?? '')) . '</td></tr>'
            . '</table>';

        $buyer = '<div class="plabel">CONSIGNEE / BUYER</div>'
            . '<div style="font-size:13px;font-weight:bold">' . e((string) ($d['buyer_name'] ?? '') ?: 'Customer') . '</div>'
            . (! empty($d['buyer_address']) ? '<div class="pm">' . e((string) $d['buyer_address']) . '</div>' : '')
            . (! empty($d['buyer_phone']) ? '<div class="pm">' . e((string) $d['buyer_phone']) . '</div>' : '');
        $seller = '<div class="plabel">EXPORTER / SELLER</div>'
            . '<div style="font-size:13px;font-weight:bold">' . e($expName) . '</div>'
            . ($expAddr !== '' ? '<div class="pm">' . e($expAddr) . '</div>' : '');

        $packages = (int) ($d['packages'] ?? 0);
        $netKg   = CustomsDocStyle::kg((int) ($d['net_g'] ?? 0));
        $grossKg = CustomsDocStyle::kg((int) ($d['gross_g'] ?? 0));
        $buyerName = (string) ($d['buyer_name'] ?? 'Consignee');
        $marks = e(mb_strtoupper($buyerName)) . ' / 1&ndash;' . $packages;

        $stampPx = min(60, \App\Services\Documents\DocumentStamp::sizePx($t));
        $stamp = \App\Services\Documents\DocumentStamp::img($t, 'delivery_note', $stampPx);

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 34px 36px 40px; }
*{font-family:"DejaVu Sans",Arial,sans-serif;}
body{margin:0;color:{$ink};font-size:11px;}
.top{width:100%;border-collapse:collapse;border-bottom:3px solid {$accent};}
.top td{padding:0 0 12px;vertical-align:top;}
.dtitle{font-size:22px;font-weight:bold;letter-spacing:1px;color:{$accentD};text-align:right;}
.sub{font-size:9.5px;color:#64748b;margin-top:3px;}
table.meta{border-collapse:collapse;margin-top:4px;width:100%;}
table.meta td{padding:3px 0;font-size:11px;}
table.meta .k{color:#64748b;width:110px;}
table.meta .v{font-weight:bold;text-align:right;}
.parties{width:100%;border-collapse:separate;border-spacing:12px 0;margin-top:14px;}
.parties td{width:50%;vertical-align:top;background:#f8faf9;border:1px solid #eef2ee;border-radius:8px;padding:10px 12px;}
.plabel{font-size:8.5px;font-weight:bold;letter-spacing:1.3px;color:{$accentD};margin-bottom:4px;}
.pm{font-size:10px;color:#475569;margin-top:2px;}
table.items{width:100%;border-collapse:collapse;margin-top:16px;}
table.items th{background:{$accentD};color:#fff;font-size:9px;letter-spacing:.3px;padding:7px 7px;text-align:left;}
table.items th.r{text-align:right;} table.items th.c{text-align:center;} table.items th.n{text-align:center;width:24px;}
table.items td{padding:6px 7px;border-bottom:1px solid #e2e8f0;font-size:9.5px;vertical-align:top;}
table.items td.n{text-align:center;color:#94a3b8;} table.items td.r{text-align:right;white-space:nowrap;} table.items td.c{text-align:center;white-space:nowrap;}
.sec{margin-top:18px;font-size:8.5px;font-weight:bold;letter-spacing:1.2px;color:{$accentD};}
table.tot{width:100%;border-collapse:collapse;margin-top:6px;}
table.tot td{width:25%;padding:7px 8px;border:1px solid #e2e8f0;font-size:10px;}
.tk{display:block;font-size:8px;color:#64748b;letter-spacing:.5px;}
.tv{display:block;font-weight:bold;margin-top:2px;}
.note{margin-top:12px;font-size:9px;color:#64748b;}
table.sig{width:100%;border-collapse:separate;border-spacing:14px 0;margin-top:20px;}
table.sig td{width:50%;vertical-align:bottom;}
.sigbox{border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;min-height:86px;}
.siglabel{font-size:8.5px;font-weight:bold;letter-spacing:1px;color:#64748b;}
.sigline{border-bottom:1px solid #94a3b8;height:30px;margin-top:8px;}
.sigsub{font-size:8px;color:#94a3b8;margin-top:4px;}
.foot{margin-top:18px;border-top:1px solid #e2e8f0;padding-top:8px;font-size:8.5px;color:#94a3b8;text-align:center;}
</style></head><body>

<table class="top"><tr>
<td style="width:55%">{$logoBlock}
<div class="sub">{$expAddr}</div>
</td>
<td style="width:45%"><div class="dtitle">PACKING LIST</div>{$meta}</td>
</tr></table>

<table class="parties"><tr><td>{$seller}</td><td>{$buyer}</td></tr></table>

<table class="items">
<tr><th class="n">#</th><th>Description</th><th>Part No.</th><th>HS Code</th><th class="c">Qty</th><th class="c">Pkgs</th><th class="r">Net Wt</th><th class="r">Gross Wt</th><th>Origin</th></tr>
{$rows}
</table>

<div class="sec">SHIPMENT TOTALS</div>
<table class="tot"><tr>
<td><span class="tk">Total packages</span><span class="tv">{$packages}</span></td>
<td><span class="tk">Total net weight</span><span class="tv">{$netKg}</span></td>
<td><span class="tk">Total gross weight</span><span class="tv">{$grossKg}</span></td>
<td><span class="tk">Marks &amp; numbers</span><span class="tv">{$marks}</span></td>
</tr></table>
<div class="note">Weights are per the product records; confirm against the actual carton labels / scale before lodging with customs.</div>

<table class="sig"><tr>
<td><div class="sigbox"><div class="siglabel">PACKED / CERTIFIED BY — {$expName}</div>{$stamp}<div class="sigline"></div><div class="sigsub">Signature &amp; date</div></div></td>
<td><div class="sigbox"><div class="siglabel">RECEIVED BY</div><div class="sigline"></div><div class="sigsub">Name, signature &amp; date</div></div></td>
</tr></table>

<div class="foot">Packing List PL-{$d['doc_ref']} — contents &amp; weights only; values are on the commercial invoice.</div>
</body></html>
HTML;
    }
}
