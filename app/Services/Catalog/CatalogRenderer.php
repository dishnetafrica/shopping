<?php

namespace App\Services\Catalog;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Branded PRODUCT CATALOGUE PDF — a photo-first brochure the wholesaler sends to customers.
 *
 * Layout: a cover page (logo, title, date, category index with page numbers), then ONE CATEGORY PER
 * PAGE, each a 3-across grid of product cards (photo, name, pack/unit, MOQ, price, SKU). A category
 * with more than nine products flows onto continuation pages, and the index page numbers account for
 * that, so the contents list is always accurate.
 *
 * dompdf has no fl[ex]/grid engine, so the whole thing is built with tables + block flow (the same
 * engine-safe approach as PriceListRenderer). Renders only; reads product data and prices as-is.
 */
final class CatalogRenderer
{
    private const PER_PAGE = 9;           // 3 columns × 3 rows per category page
    private const BRAND = '#16a34a';
    private const BRAND_DARK = '#0f7a37';

    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /**
     * @param array<int,array{category:string,items:array<int,array{name:string,unit:string,moq:?int,price:float,sku:string,image:string}>}> $groups
     * @return array{path:string,url:string,b64:string,fileName:string}|null
     */
    public function pdf(Tenant $tenant, array $groups, string $currency, bool $withPrices = true): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $html = $this->html($tenant, $groups, $currency, $withPrices);

        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true, 'isHtml5ParserEnabled' => true]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            \Log::warning('CatalogRenderer failed: ' . $e->getMessage());

            return null;
        }

        $slug = $groups && count($groups) === 1 ? '-' . preg_replace('/[^A-Za-z0-9]+/', '', $groups[0]['category']) : '';
        $fileName = 'Catalogue' . $slug . '-' . now()->format('Ymd') . '.pdf';
        $path = 'catalogues/' . $tenant->id . '/' . $fileName;
        try { Storage::disk('public')->put($path, $pdf); } catch (\Throwable $e) { /* best-effort */ }

        return ['path' => $path, 'url' => $this->url($path), 'b64' => base64_encode($pdf), 'fileName' => $fileName];
    }

    /**
     * The whole-shop photo catalogue for a tenant, ready for WhatsApp sendDocument.
     * Groups active products by category (same shape the V3 doc endpoint builds),
     * renders once, then serves from a 6h cache (meta cached, bytes re-read from
     * storage) — first ask pays the render, everyone after gets it instantly.
     */
    public function forTenant(Tenant $tenant, bool $withPrices = true): ?array
    {
        try {
            $key = 'catalog_pdf:'.$tenant->id.':'.($withPrices ? 'p' : 'np');
            $meta = \Illuminate\Support\Facades\Cache::get($key);
            if (is_array($meta) && ! empty($meta['path'])) {
                try {
                    $bytes = Storage::disk('public')->get($meta['path']);
                    if ($bytes !== null && $bytes !== '') {
                        return ['path' => $meta['path'], 'url' => $this->url($meta['path']),
                                'b64' => base64_encode($bytes), 'fileName' => $meta['fileName']];
                    }
                } catch (\Throwable $e) {
                }
            }

            $products = \App\Models\Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('active', true)->where('price', '>', 0)
                ->orderBy('category')->orderBy('name')->get();
            if ($products->isEmpty()) return null;

            $cur = (string) ($tenant->setting('currency', 'UGX') ?: 'UGX');
            $grouped = [];
            foreach ($products as $p) {
                $cat = trim((string) $p->category) ?: 'Uncategorised';
                $grouped[$cat][] = [
                    'name' => (string) $p->name,
                    'unit' => (string) ($p->unit_label ?: ''),
                    'moq' => $p->moq ? (int) $p->moq : null,
                    'price' => (float) $p->price,
                    'sku' => (string) ($p->sku ?: ''),
                    'image' => (string) ($p->image_url ?: ''),
                ];
            }
            ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
            $groups = [];
            foreach ($grouped as $cat => $items) $groups[] = ['category' => $cat, 'items' => $items];

            $doc = $this->pdf($tenant, $groups, $cur, $withPrices);
            if ($doc) {
                \Illuminate\Support\Facades\Cache::put($key,
                    ['path' => $doc['path'], 'fileName' => $doc['fileName']], now()->addHours(6));
            }

            return $doc;
        } catch (\Throwable $e) {
            \Log::warning('CatalogRenderer::forTenant failed: '.$e->getMessage());

            return null;
        }
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

    /** @param array<int,array<string,mixed>> $groups */
    private function html(Tenant $tenant, array $groups, string $currency, bool $withPrices): string
    {
        $company = e((string) $tenant->name);
        $tagline = e((string) ($tenant->setting('tagline', '') ?: $tenant->setting('address', '') ?: ''));
        $phone = trim((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        $email = trim((string) $tenant->setting('public_email', $tenant->setting('email', '')));
        $logo = (string) $tenant->setting('logo', '');
        $date = now()->format('F Y');
        $dateFull = now()->format('d M Y');
        $brand = self::BRAND; $brandD = self::BRAND_DARK;

        // --- paginate categories and compute index page numbers (cover = page 1) ---
        $plan = [];
        $page = 2;
        foreach ($groups as $g) {
            $chunks = array_chunk($g['items'], self::PER_PAGE);
            if (! $chunks) { $chunks = [[]]; }
            $plan[] = ['category' => $g['category'], 'chunks' => $chunks, 'startPage' => $page, 'count' => count($g['items'])];
            $page += count($chunks);
        }

        // --- cover: logo, title, index ---
        $logoBlock = $logo !== ''
            ? '<img src="' . e($logo) . '" style="height:70px;max-width:180px;object-fit:contain">'
            : '<div style="font-size:34px;font-weight:bold;color:' . $brand . '">' . $company . '</div>';

        $indexRows = '';
        foreach ($plan as $p) {
            $indexRows .= '<tr>'
                . '<td style="padding:7px 4px;border-bottom:1px solid #eee;font-weight:bold;font-size:12px">' . e($p['category']) . '</td>'
                . '<td style="padding:7px 4px;border-bottom:1px solid #eee;color:#666;font-size:11px">' . (int) $p['count'] . ' products</td>'
                . '<td style="padding:7px 4px;border-bottom:1px solid #eee;text-align:right;color:' . $brandD . ';font-weight:bold;font-size:11px">p.' . (int) $p['startPage'] . '</td>'
                . '</tr>';
        }

        $cover = '<div class="cover">'
            . '<div style="margin-top:150px">' . $logoBlock . '</div>'
            . '<div style="margin-top:18px;font-size:13px;color:#555;font-weight:bold">' . ($tagline ?: 'Wholesale') . '</div>'
            . '<div class="ctitle">PRODUCT CATALOGUE</div>'
            . '<div style="font-size:13px;color:#666;margin-bottom:40px">' . $date . '</div>'
            . '<table class="idx"><tr>'
            . '<th style="text-align:left">Category</th><th style="text-align:left">Items</th><th style="text-align:right">Page</th>'
            . '</tr>' . $indexRows . '</table>'
            . '<div class="coverfoot">' . e($company) . ($phone ? ' &nbsp;·&nbsp; ' . e($phone) : '') . ($email ? ' &nbsp;·&nbsp; ' . e($email) : '') . '</div>'
            . '</div>';

        // --- category pages ---
        $body = '';
        foreach ($plan as $p) {
            $total = count($p['chunks']);
            foreach ($p['chunks'] as $ci => $items) {
                $contLabel = $total > 1 ? ' <span style="font-weight:normal;color:#94a3b8;font-size:11px">(' . ($ci + 1) . '/' . $total . ')</span>' : '';
                $body .= '<div class="page">'
                    . $this->pageHeader($company, $logo, $date, $brand, $brandD)
                    . '<table class="catband"><tr><td>' . e($p['category']) . $contLabel . '</td>'
                    . '<td style="text-align:right"><span class="pill">' . (int) $p['count'] . ' products</span></td></tr></table>'
                    . $this->grid($items, $currency, $withPrices, $brandD)
                    . $this->pageFooter($phone, $currency, $dateFull, $withPrices)
                    . '</div>';
            }
        }

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
*{font-family:"DejaVu Sans",Arial,sans-serif;}
body{margin:0;padding:0;color:#0f172a;}
.cover{text-align:center;padding:0 60px;page-break-after:always;height:1020px;}
.ctitle{font-size:30px;font-weight:bold;letter-spacing:4px;color:{$brand};margin:26px 0 6px;}
table.idx{width:70%;margin:10px auto 0;border-collapse:collapse;}
table.idx th{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;border-bottom:2px solid {$brand};padding:6px 4px;}
.coverfoot{margin-top:60px;font-size:10px;color:#94a3b8;}
.page{page-break-after:always;padding:0;}
.phead{width:100%;border-collapse:collapse;border-bottom:2px solid {$brand};}
.phead td{padding:10px 6px 8px;vertical-align:middle;}
.pcompany{font-size:15px;font-weight:bold;}
.pdoc{font-size:10px;font-weight:bold;letter-spacing:2px;color:{$brandD};text-transform:uppercase;}
.pdate{font-size:9px;color:#94a3b8;}
table.catband{width:100%;border-collapse:collapse;margin:14px 0 10px;background:#f0fdf4;border:1px solid #dcfce7;}
table.catband td{padding:11px 14px;vertical-align:middle;font-size:19px;font-weight:bold;letter-spacing:-.3px;}
.pill{background:{$brand};color:#fff;font-size:10px;font-weight:bold;padding:4px 10px;border-radius:9px;letter-spacing:.5px;}
table.grid{width:100%;border-collapse:separate;border-spacing:9px;table-layout:fixed;}
td.cell{width:33%;border:1px solid #e5e7eb;vertical-align:top;padding:0;}
.ph{height:150px;background:#f1f5f9;text-align:center;}
.ph img{max-height:150px;max-width:100%;object-fit:contain;}
.ph .noimg{display:inline-block;padding-top:60px;color:#b6c0cc;font-size:9px;font-weight:bold;letter-spacing:1px;}
.pbody{padding:9px 10px 11px;}
.pn{font-weight:bold;font-size:12px;line-height:1.25;height:31px;overflow:hidden;}
.pu{font-size:10px;color:#64748b;font-weight:bold;margin-top:2px;}
.pm{font-size:9.5px;color:#475569;margin-top:3px;}
.pr{margin-top:7px;color:{$brandD};font-weight:bold;font-size:14px;}
.pr .from{font-size:8px;color:#94a3b8;font-weight:bold;display:block;letter-spacing:.5px;}
.sku{float:right;font-size:9px;color:#94a3b8;font-weight:bold;margin-top:5px;}
.pfoot{width:100%;border-collapse:collapse;border-top:1px solid #e5e7eb;margin-top:14px;}
.pfoot td{padding:9px 6px;font-size:9.5px;color:#64748b;font-weight:bold;}
.pfoot .wa{text-align:right;color:{$brandD};}
</style></head><body>
{$cover}
{$body}
</body></html>
HTML;
    }

    private function pageHeader(string $company, string $logo, string $date, string $brand, string $brandD): string
    {
        $logoTag = $logo !== '' ? '<img src="' . e($logo) . '" style="height:34px;max-width:100px;object-fit:contain">' : '';

        return '<table class="phead"><tr>'
            . '<td><div class="pcompany">' . $company . '</div></td>'
            . '<td style="text-align:right"><div class="pdoc">Product Catalogue</div><div class="pdate">' . $date . '</div>' . ($logoTag ? '<div style="margin-top:2px">' . $logoTag . '</div>' : '') . '</td>'
            . '</tr></table>';
    }

    /** @param array<int,array<string,mixed>> $items */
    private function grid(array $items, string $currency, bool $withPrices, string $brandD): string
    {
        $cells = [];
        foreach ($items as $it) {
            $img = trim((string) ($it['image'] ?? ''));
            $photo = $img !== ''
                ? '<div class="ph"><img src="' . e($img) . '"></div>'
                : '<div class="ph"><span class="noimg">PRODUCT PHOTO</span></div>';
            $moq = ! empty($it['moq'])
                ? '<div class="pm">Min order: ' . e((string) $it['moq']) . ' &times; ' . e((string) ($it['unit'] ?: 'units')) . '</div>'
                : '';
            $priceBlock = $withPrices
                ? '<div class="pr"><span class="from">FROM</span>' . $this->money((float) $it['price'], $currency)
                  . ($it['sku'] ? '<span class="sku">' . e((string) $it['sku']) . '</span>' : '') . '</div>'
                : ($it['sku'] ? '<div class="pr" style="color:#94a3b8;font-size:10px">' . e((string) $it['sku']) . '</div>' : '');

            $cells[] = '<td class="cell">' . $photo
                . '<div class="pbody"><div class="pn">' . e((string) $it['name']) . '</div>'
                . '<div class="pu">' . e((string) ($it['unit'] ?: '—')) . '</div>'
                . $moq . $priceBlock . '</div></td>';
        }

        // pad the final row to 3 columns so the table layout stays aligned
        while (count($cells) % 3 !== 0) {
            $cells[] = '<td class="cell" style="border:none;background:transparent"></td>';
        }

        $rows = '';
        foreach (array_chunk($cells, 3) as $row) {
            $rows .= '<tr>' . implode('', $row) . '</tr>';
        }

        return '<table class="grid">' . $rows . '</table>';
    }

    private function pageFooter(string $phone, string $currency, string $dateFull, bool $withPrices): string
    {
        $left = $withPrices
            ? 'Prices in ' . e($currency) . ' · valid as of ' . e($dateFull) . ' · subject to change'
            : 'Product catalogue · ' . e($dateFull);
        $right = $phone !== '' ? 'Order on WhatsApp: ' . e($phone) : '';

        return '<table class="pfoot"><tr><td>' . $left . '</td><td class="wa">' . $right . '</td></tr></table>';
    }
}
