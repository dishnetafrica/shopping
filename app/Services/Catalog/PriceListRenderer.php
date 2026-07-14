<?php

namespace App\Services\Catalog;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Branded, category-grouped price-list PDF — the sheet a wholesaler sends to customers. Same tenant-driven
 * header style as the invoice/quotation/receipt documents. Renders only; reads prices as-is.
 */
final class PriceListRenderer
{
    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /**
     * @param array<int,array{category:string,items:array<int,array{name:string,unit:string,moq:?int,price:float}>}> $groups
     * @return array{path:string,url:string,b64:string,fileName:string}|null
     */
    public function pdf(Tenant $tenant, array $groups, string $currency): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $html = $this->html($tenant, $groups, $currency);

        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            \Log::warning('PriceListRenderer failed: ' . $e->getMessage());

            return null;
        }

        $fileName = 'Price-List-' . now()->format('Ymd') . '.pdf';
        $path = 'pricelists/' . $tenant->id . '/' . $fileName;
        try { Storage::disk('public')->put($path, $pdf); } catch (\Throwable $e) { /* best-effort */ }

        return ['path' => $path, 'url' => $this->url($path), 'b64' => base64_encode($pdf), 'fileName' => $fileName];
    }

    private function url(string $path): string
    {
        try { return Storage::disk('public')->url($path); } catch (\Throwable $e) { return ''; }
    }

    private function money(float $n, string $cur): string
    {
        $dec = $cur === 'USD' ? 2 : 0;
        $sym = $cur === 'USD' ? '$' : '';

        return $sym . number_format($n, $dec) . ($sym ? '' : ' ' . $cur);
    }

    private function html(Tenant $tenant, array $groups, string $currency): string
    {
        $company = e((string) $tenant->name);
        $address = nl2br(e((string) ($tenant->setting('address', '') ?: '')));
        $tin = trim((string) $tenant->setting('tin', ''));
        $email = trim((string) $tenant->setting('public_email', $tenant->setting('email', '')));
        $phone = trim((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        $logo = (string) $tenant->setting('logo', '');
        $logoTag = $logo !== '' ? '<img src="' . e($logo) . '" style="height:52px;max-width:120px;object-fit:contain">' : '';
        $contact = array_filter([$phone !== '' ? e($phone) : '', $email !== '' ? e($email) : '']);
        $contactLine = $contact ? implode(' &nbsp;|&nbsp; ', $contact) : '';
        $tinLine = $tin !== '' ? 'TIN: ' . e($tin) : '';
        $date = now()->format('d/m/Y');

        $sections = '';
        foreach ($groups as $g) {
            $rows = '';
            foreach ($g['items'] as $it) {
                $moq = $it['moq'] ? (e((string) $it['moq']) . ' ' . e((string) ($it['unit'] ?: 'units'))) : '—';
                $unit = e((string) ($it['unit'] ?: '—'));
                $rows .= '<tr><td>' . e($it['name']) . '</td><td class="c">' . $unit . '</td><td class="c">' . $moq . '</td><td class="r">' . $this->money((float) $it['price'], $currency) . '</td></tr>';
            }
            $sections .= '<tr class="cat"><td colspan="4">' . e($g['category']) . '</td></tr>' . $rows;
        }

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
*{font-family:"DejaVu Sans",Arial,sans-serif;}
body{color:#111;font-size:11px;margin:0;padding:6px;}
.head{display:table;width:100%;border-bottom:2px solid #16a34a;padding-bottom:8px;}
.head .l{display:table-cell;vertical-align:middle;}
.head .r{display:table-cell;vertical-align:middle;text-align:right;}
.company{font-size:18px;font-weight:bold;}
.sub{font-size:10px;color:#444;line-height:1.4;margin-top:2px;}
.title{text-align:center;font-size:18px;font-weight:bold;letter-spacing:2px;color:#16a34a;margin:12px 0 2px;}
.asof{text-align:center;font-size:10px;color:#555;margin-bottom:12px;}
table.pl{width:100%;border-collapse:collapse;}
table.pl th{background:#f0f0f0;border:1px solid #ccc;padding:6px 8px;font-size:10px;text-align:left;}
table.pl th.r{text-align:right;} table.pl th.c{text-align:center;}
table.pl td{border:1px solid #e5e5e5;padding:5px 8px;font-size:10.5px;}
table.pl td.r{text-align:right;font-family:"DejaVu Sans Mono",monospace;} table.pl td.c{text-align:center;}
tr.cat td{background:#eafaf0;color:#15803d;font-weight:bold;font-size:11px;border:1px solid #cfeade;padding:6px 8px;}
.note{margin-top:16px;font-size:9.5px;color:#666;text-align:center;}
</style></head><body>
<div class="head">
  <div class="l"><div class="company">{$company}</div><div class="sub">{$address}<br>{$contactLine}<br>{$tinLine}</div></div>
  <div class="r">{$logoTag}</div>
</div>
<div class="title">PRICE LIST</div>
<div class="asof">Prices as of {$date} &nbsp;·&nbsp; Currency: {$currency}</div>
<table class="pl">
  <thead><tr><th>Product</th><th class="c">Unit</th><th class="c">Min order</th><th class="r">Price</th></tr></thead>
  <tbody>{$sections}</tbody>
</table>
<div class="note">Prices are subject to change without prior notice. Please confirm availability before ordering.</div>
</body></html>
HTML;
    }
}
