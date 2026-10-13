<?php

namespace App\Services\Customs;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * COMMERCIAL INVOICE PDF — the valuation document a clearing agent lodges to clear a
 * cross-border shipment. Unlike a tax Invoice it is NOT issued/numbered through the
 * accounting engine and writes nothing to the books — it is a rendered document only.
 *
 * It prints, per line, the part number (oem_number), HS/tariff code, country of origin,
 * quantity, unit price and amount, plus a customs/shipment block (Incoterms, country of
 * export, total packages, total gross weight) and the exporter's declaration — all read
 * from the tenant's "Exporter / Customs profile" settings.
 *
 * Pure renderer: the caller assembles $d via CustomsLineBuilder.
 */
final class CommercialInvoiceRenderer
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
            \Log::warning('CommercialInvoiceRenderer failed: ' . $e->getMessage());

            return null;
        }

        $ref = preg_replace('/[^A-Za-z0-9\-]+/', '', (string) ($d['doc_ref'] ?? 'inv'));
        $fileName = 'CommercialInvoice-' . ($ref ?: 'inv') . '.pdf';
        $path = 'customs/' . $tenant->id . '/' . $fileName;
        try { Storage::disk('public')->put($path, $pdf); } catch (\Throwable $e) { /* best-effort */ }

        return ['path' => $path, 'b64' => base64_encode($pdf), 'fileName' => $fileName];
    }

    private function html(Tenant $t, array $d): string
    {
        [$accent, $accentD, $ink] = CustomsDocStyle::palette($t);
        $cur = strtoupper((string) ($d['currency'] ?? 'USD'));
        $dec = CustomsDocStyle::decimals($cur);

        // Exporter block — exporter_* overrides the general business identity.
        $expName = (string) ($t->setting('exporter_name', '') ?: $t->name);
        $expAddr = (string) ($t->setting('exporter_address', '') ?: trim(implode(', ', array_filter([
            (string) $t->setting('address', ''), (string) $t->setting('city', ''), (string) $t->setting('country', ''),
        ]))));
        $expTax  = (string) ($t->setting('exporter_tax_id', '') ?: $t->setting('tin', ''));
        $logo    = (string) $t->setting('logo', '');

        $incoterms = (string) $t->setting('customs_incoterms', 'FOB');
        $cExport   = (string) $t->setting('customs_country_export', '');
        $declare   = (string) $t->setting('customs_declaration', 'We certify that the information on this invoice is true and correct and that the contents of this shipment are as stated.');
        $terms     = (string) $t->setting('customs_terms', '');

        $logoBlock = $logo !== ''
            ? '<img src="' . e($logo) . '" style="height:40px;max-width:150px"><div style="font-size:14px;font-weight:bold;margin-top:4px">' . e($expName) . '</div>'
            : '<div style="font-size:20px;font-weight:bold;color:' . $accentD . '">' . e($expName) . '</div>';

        // Origin summary: one value if every line agrees, else "Mixed (see lines)".
        $origins = array_values(array_unique(array_filter(array_map(fn ($l) => trim((string) $l['origin']), (array) $d['lines']))));
        $originSummary = count($origins) === 1 ? $origins[0] : (count($origins) > 1 ? 'Mixed — see line origins' : '—');

        $rows = '';
        $i = 0;
        foreach ((array) $d['lines'] as $l) {
            $i++;
            $qty = (float) $l['qty'];
            $qtyTxt = ($qty == (int) $qty ? (string) (int) $qty : (string) $qty) . (! empty($l['unit']) ? ' ' . e((string) $l['unit']) : '');
            $rows .= '<tr>'
                . '<td class="n">' . $i . '</td>'
                . '<td>' . e((string) $l['name']) . '</td>'
                . '<td>' . e((string) ($l['part_no'] ?: '—')) . '</td>'
                . '<td>' . e((string) ($l['hs_code'] ?: '—')) . '</td>'
                . '<td>' . e((string) ($l['origin'] ?: '—')) . '</td>'
                . '<td class="r">' . $qtyTxt . '</td>'
                . '<td class="r">' . number_format((float) $l['unit_price'], $dec) . '</td>'
                . '<td class="r b">' . number_format((float) $l['amount'], $dec) . '</td>'
                . '</tr>';
        }

        $subtotal = number_format((float) ($d['subtotal'] ?? 0), $dec);
        $grossKg  = CustomsDocStyle::kg((int) ($d['gross_g'] ?? 0));
        $meta = '<table class="meta">'
            . '<tr><td class="k">Invoice No.</td><td class="v">CI-' . e((string) ($d['doc_ref'] ?? '')) . '</td></tr>'
            . '<tr><td class="k">Date</td><td class="v">' . e((string) ($d['date'] ?? '')) . '</td></tr>'
            . '<tr><td class="k">Buyer Order</td><td class="v">' . e((string) ($d['doc_ref'] ?? '')) . '</td></tr>'
            . '<tr><td class="k">Currency</td><td class="v">' . e($cur) . '</td></tr>'
            . '</table>';

        $buyer = '<div class="plabel">CONSIGNEE / BUYER</div>'
            . '<div style="font-size:13px;font-weight:bold">' . e((string) ($d['buyer_name'] ?? '') ?: 'Customer') . '</div>'
            . (! empty($d['buyer_address']) ? '<div class="pm">' . e((string) $d['buyer_address']) . '</div>' : '')
            . (! empty($d['buyer_phone']) ? '<div class="pm">' . e((string) $d['buyer_phone']) . '</div>' : '');
        $seller = '<div class="plabel">EXPORTER / SELLER</div>'
            . '<div style="font-size:13px;font-weight:bold">' . e($expName) . '</div>'
            . ($expAddr !== '' ? '<div class="pm">' . e($expAddr) . '</div>' : '')
            . ($expTax !== '' ? '<div class="pm">Tax ID / PIN: ' . e($expTax) . '</div>' : '');

        $stampPx = min(60, \App\Services\Documents\DocumentStamp::sizePx($t));
        $stamp = \App\Services\Documents\DocumentStamp::img($t, 'invoice', $stampPx);

        $customsBlock = '<table class="cust"><tr>'
            . '<td><span class="ck">Incoterms</span><span class="cv">' . e($incoterms) . ($cExport !== '' ? ' ' . e($cExport) : '') . '</span></td>'
            . '<td><span class="ck">Country of export</span><span class="cv">' . e($cExport ?: '—') . '</span></td>'
            . '<td><span class="ck">Country of origin</span><span class="cv">' . e($originSummary) . '</span></td>'
            . '</tr><tr>'
            . '<td><span class="ck">Total packages</span><span class="cv">' . (int) ($d['packages'] ?? 0) . '</span></td>'
            . '<td><span class="ck">Total gross weight</span><span class="cv">' . e($grossKg) . '</span></td>'
            . '<td><span class="ck">Reason for export</span><span class="cv">Sale of goods</span></td>'
            . '</tr></table>';

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
table.meta .k{color:#64748b;width:95px;}
table.meta .v{font-weight:bold;text-align:right;}
.parties{width:100%;border-collapse:separate;border-spacing:12px 0;margin-top:14px;}
.parties td{width:50%;vertical-align:top;background:#f8faf9;border:1px solid #eef2ee;border-radius:8px;padding:10px 12px;}
.plabel{font-size:8.5px;font-weight:bold;letter-spacing:1.3px;color:{$accentD};margin-bottom:4px;}
.pm{font-size:10px;color:#475569;margin-top:2px;}
table.items{width:100%;border-collapse:collapse;margin-top:16px;}
table.items th{background:{$accentD};color:#fff;font-size:9.5px;letter-spacing:.4px;padding:7px 8px;text-align:left;}
table.items th.r{text-align:right;} table.items th.n{text-align:center;width:26px;}
table.items td{padding:6px 8px;border-bottom:1px solid #e2e8f0;font-size:10px;vertical-align:top;}
table.items td.n{text-align:center;color:#94a3b8;} table.items td.r{text-align:right;white-space:nowrap;} table.items td.b{font-weight:bold;}
.totbox{width:100%;border-collapse:collapse;margin-top:10px;}
.totbox td{vertical-align:top;}
table.tt{border-collapse:collapse;float:right;}
table.tt td{padding:4px 8px;font-size:11px;}
table.tt .tk{color:#64748b;text-align:right;} table.tt .tv{text-align:right;font-weight:bold;min-width:90px;}
table.tt .grand td{background:{$accentD};color:#fff;font-size:12px;}
.sec{margin-top:18px;font-size:8.5px;font-weight:bold;letter-spacing:1.2px;color:{$accentD};}
table.cust{width:100%;border-collapse:collapse;margin-top:6px;}
table.cust td{width:33%;padding:6px 8px;border:1px solid #e2e8f0;font-size:10px;}
.ck{display:block;font-size:8px;color:#64748b;letter-spacing:.5px;}
.cv{display:block;font-weight:bold;margin-top:2px;}
.declare{margin-top:14px;font-size:10px;color:#334155;}
.terms{margin-top:8px;font-size:9px;color:#64748b;white-space:pre-line;}
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
HTML
        . ($expTax !== '' ? '<div class="sub">Tax ID / PIN: ' . e($expTax) . '</div>' : '')
        . <<<HTML
</td>
<td style="width:45%"><div class="dtitle">COMMERCIAL INVOICE</div>{$meta}</td>
</tr></table>

<table class="parties"><tr><td>{$seller}</td><td>{$buyer}</td></tr></table>

<table class="items">
<tr><th class="n">#</th><th>Description of goods</th><th>Part No.</th><th>HS Code</th><th>Origin</th><th class="r">Qty</th><th class="r">Unit Price</th><th class="r">Amount</th></tr>
{$rows}
</table>

<table class="totbox"><tr><td>
<table class="tt">
<tr><td class="tk">Subtotal ({$cur})</td><td class="tv">{$subtotal}</td></tr>
<tr class="grand"><td class="tk" style="color:#fff">TOTAL ({$cur})</td><td class="tv">{$subtotal}</td></tr>
</table>
</td></tr></table>

<div class="sec">SHIPMENT &amp; CUSTOMS DECLARATION</div>
{$customsBlock}
<div class="declare">{$declare}</div>
HTML
        . ($terms !== '' ? '<div class="terms">' . e($terms) . '</div>' : '')
        . <<<HTML

<table class="sig"><tr>
<td><div class="sigbox"><div class="siglabel">FOR {$expName} — SIGNATURE / STAMP / DATE</div>{$stamp}<div class="sigline"></div><div class="sigsub">Authorized signatory</div></div></td>
<td><div class="sigbox"><div class="siglabel">RECEIVED / APPROVED</div><div class="sigline"></div><div class="sigsub">Name, signature &amp; date</div></div></td>
</tr></table>

<div class="foot">Commercial Invoice CI-{$d['doc_ref']} — a customs valuation document, not a tax invoice or receipt of payment.</div>
</body></html>
HTML;
    }
}
