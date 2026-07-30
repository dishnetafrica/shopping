<?php

namespace App\Services\Catalog;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Branded PRODUCT CATALOGUE PDF — a photo-first brochure the wholesaler sends to customers.
 *
 * v2 layout (same public API as v1): cover with brand band + accurate category index, one
 * category per page (3×3 product cards), a "How to order" back page, and real page numbers.
 *
 * What v2 fixes over v1 (all reproduced with dompdf 3 on sample data):
 *   1. Cover no longer orphans its contact line onto a near-blank page 2 (fixed-height hack gone).
 *   2. A full 9-card page no longer overflows A4 and dumps its footer on an orphan page —
 *      the whole page fits a strict height budget under an explicit @page margin.
 *   3. No trailing blank page (pages use page-break-BEFORE; nothing breaks after the last page).
 *   4. Index page numbers are correct (cover=1, back page counted) and every page is numbered
 *      "Page N of T" via the canvas page_script.
 *   5. Product photos are pre-fetched and normalised onto identical 4:3 white canvases
 *      (GD, cached on disk, embedded as data URIs) — uniform grid, no broken-image boxes
 *      when a URL 404s, dramatically smaller PDFs than embedding full-size remote images.
 *   6. Cards are strictly equal height (name/unit/MOQ zones are fixed) so price rows align,
 *      and names clip at a line boundary instead of slicing glyphs.
 *
 * dompdf has no flex/grid engine, so everything stays tables + block flow. Renders only;
 * reads product data and prices as-is. Degrades gracefully: no GD → original URLs are
 * embedded exactly like v1; fetch failure → drawn "no photo" placeholder.
 */
final class CatalogRenderer
{
    private const PER_PAGE = 9;           // 3 columns × 3 rows per category page
    private const BRAND = '#16a34a';
    private const BRAND_DARK = '#0f7a37';
    private const INK = '#0f172a';

    private const THUMB_W = 440;          // 4:3 canvas every photo is normalised onto
    private const THUMB_H = 330;
    private const FETCH_TIMEOUT = 4;      // per-image seconds
    private const FETCH_PARALLEL = 10;    // curl_multi lanes

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

        $thumbs = $this->thumbs($tenant, $groups);
        $html = $this->html($tenant, $groups, $currency, $withPrices, $thumbs);

        try {
            $dompdf = new \Dompdf\Dompdf([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                // Embed only the glyphs actually used instead of whole font files — shaves a few
                // hundred KB off every document for free.
                'isFontSubsettingEnabled' => true,
            ]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();

            // "Page N of T" on every page except the cover.
            try {
                $canvas = $dompdf->getCanvas();
                $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
                $w = $canvas->get_width();
                $canvas->page_script(function ($pageNumber, $pageCount, $c) use ($font, $w) {
                    if ($pageNumber > 1) {
                        $c->text($w / 2 - 30, $c->get_height() - 22,
                            'Page ' . $pageNumber . ' of ' . $pageCount, $font, 7.5, [0.58, 0.64, 0.71]);
                    }
                });
            } catch (\Throwable $e) {
                // page numbers are cosmetic — never fail the document over them
            }

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

    // ─────────────────────────── photo pipeline ───────────────────────────

    /**
     * Pre-fetch every product image (parallel, cached on disk) and normalise each onto an
     * identical 4:3 white canvas → data URI. Returns [imageUrl => dataUri|''] ('' = use
     * the drawn placeholder). Without GD this returns [] and cards embed original URLs (v1
     * behaviour) so nothing regresses on a slim PHP build.
     */
    private function thumbs(Tenant $tenant, array $groups): array
    {
        if (! extension_loaded('gd')) {
            return [];
        }

        $urls = [];
        foreach ($groups as $g) {
            foreach ($g['items'] as $it) {
                $u = trim((string) ($it['image'] ?? ''));
                if ($u !== '') $urls[$u] = true;
            }
        }
        if (! $urls) {
            return [];
        }

        $out = [];
        $fetch = [];   // url => cache path (missing from disk cache)
        foreach (array_keys($urls) as $u) {
            $cache = 'catalog-thumbs/' . $tenant->id . '/' . md5($u) . '.jpg';
            try {
                $bytes = Storage::disk('public')->exists($cache) ? Storage::disk('public')->get($cache) : null;
            } catch (\Throwable $e) {
                $bytes = null;
            }
            if ($bytes) {
                $out[$u] = 'data:image/jpeg;base64,' . base64_encode($bytes);
            } else {
                $fetch[$u] = $cache;
            }
        }

        foreach ($this->fetchMany(array_keys($fetch)) as $u => $raw) {
            $jpeg = $raw !== null ? $this->normalise($raw) : null;
            if ($jpeg !== null) {
                try { Storage::disk('public')->put($fetch[$u], $jpeg); } catch (\Throwable $e) { /* cache only */ }
                $out[$u] = 'data:image/jpeg;base64,' . base64_encode($jpeg);
            } else {
                $out[$u] = '';   // fetch/decode failed → drawn placeholder, never a broken-image box
            }
        }

        return $out;
    }

    /** @return array<string,?string> url => raw bytes (null on failure). Local paths supported (harness/tests). */
    private function fetchMany(array $urls): array
    {
        $res = [];
        $remote = [];
        foreach ($urls as $u) {
            if (is_file($u)) {
                $res[$u] = @file_get_contents($u) ?: null;
            } elseif (preg_match('~^https?://~i', $u)) {
                $remote[] = $u;
            } else {
                $res[$u] = null;
            }
        }
        if (! $remote) {
            return $res;
        }
        if (! function_exists('curl_multi_init')) {
            foreach ($remote as $u) {
                $ctx = stream_context_create(['http' => ['timeout' => self::FETCH_TIMEOUT]]);
                $res[$u] = @file_get_contents($u, false, $ctx) ?: null;
            }

            return $res;
        }

        foreach (array_chunk($remote, self::FETCH_PARALLEL) as $batch) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($batch as $u) {
                $ch = curl_init($u);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'CloudBSS-Catalog/2.0',
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$u] = $ch;
            }
            do {
                curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 0.2);
            } while ($running);
            foreach ($handles as $u => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $res[$u] = ($code >= 200 && $code < 300 && $body !== '' && $body !== false) ? $body : null;
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        return $res;
    }

    /** Contain-fit raw image bytes onto a white 4:3 canvas → JPEG bytes (null if undecodable). */
    private function normalise(string $raw): ?string
    {
        $src = @imagecreatefromstring($raw);
        if (! $src) {
            return null;
        }
        $sw = imagesx($src); $sh = imagesy($src);
        if ($sw < 2 || $sh < 2) { imagedestroy($src); return null; }

        $canvas = imagecreatetruecolor(self::THUMB_W, self::THUMB_H);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $scale = min((self::THUMB_W - 20) / $sw, (self::THUMB_H - 20) / $sh, 1.5);
        $dw = (int) round($sw * $scale); $dh = (int) round($sh * $scale);
        imagecopyresampled($canvas, $src,
            (int) ((self::THUMB_W - $dw) / 2), (int) ((self::THUMB_H - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);

        ob_start();
        imagejpeg($canvas, null, 80);
        imagedestroy($canvas);
        $jpeg = ob_get_clean();

        return $jpeg !== false && $jpeg !== '' ? $jpeg : null;
    }

    // ─────────────────────────── document ───────────────────────────

    /** @param array<int,array<string,mixed>> $groups */
    private function html(Tenant $tenant, array $groups, string $currency, bool $withPrices, array $thumbs): string
    {
        $company = e((string) $tenant->name);
        $tagline = e((string) ($tenant->setting('tagline', '') ?: $tenant->setting('address', '') ?: ''));
        $phone = trim((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        $email = trim((string) $tenant->setting('public_email', $tenant->setting('email', '')));
        $logo = (string) $tenant->setting('logo', '');
        $date = now()->format('F Y');
        $dateFull = now()->format('d M Y');
        $brand = self::BRAND; $brandD = self::BRAND_DARK; $ink = self::INK;

        // --- paginate categories; cover = page 1, back page = last (index stays accurate) ---
        $plan = [];
        $page = 2;
        foreach ($groups as $g) {
            $chunks = array_chunk($g['items'], self::PER_PAGE);
            if (! $chunks) { $chunks = [[]]; }
            $plan[] = ['category' => $g['category'], 'chunks' => $chunks, 'startPage' => $page, 'count' => count($g['items'])];
            $page += count($chunks);
        }

        // --- cover ---
        $logoBlock = $logo !== ''
            ? '<div style="background:#ffffff;border-radius:10px;padding:12px 20px;display:inline-block"><img src="' . e($logo) . '" style="height:56px;max-width:170px"></div>'
            : '<div style="font-size:30px;font-weight:bold;color:#ffffff;letter-spacing:-.5px">' . $company . '</div>';

        $rows = [];
        foreach ($plan as $i => $p) {
            $bg = $i % 2 === 0 ? '#ffffff' : '#f8faf8';
            $rows[] = '<tr>'
                . '<td style="padding:8px 12px;background:' . $bg . ';font-weight:bold;font-size:11.5px;color:' . $ink . '">' . e($p['category']) . '</td>'
                . '<td style="padding:8px 6px;background:' . $bg . ';color:#94a3b8;font-size:10px;white-space:nowrap">' . (int) $p['count'] . ' items</td>'
                . '<td style="padding:8px 12px;background:' . $bg . ';text-align:right;color:' . $brandD . ';font-weight:bold;font-size:11px;white-space:nowrap">p.' . (int) $p['startPage'] . '</td>'
                . '</tr>';
        }
        $idxHead = '<tr><th style="text-align:left;padding:7px 12px">Category</th><th style="text-align:left;padding:7px 6px">Items</th><th style="text-align:right;padding:7px 12px">Page</th></tr>';
        if (count($rows) > 14) {   // two-column index for catalogue-heavy shops — never overflows the cover
            $half = (int) ceil(count($rows) / 2);
            $colA = '<table class="idx">' . $idxHead . implode('', array_slice($rows, 0, $half)) . '</table>';
            $colB = '<table class="idx">' . $idxHead . implode('', array_slice($rows, $half)) . '</table>';
            $index = '<table style="width:100%;border-collapse:separate;border-spacing:12px 0"><tr>'
                . '<td style="width:50%;vertical-align:top">' . $colA . '</td>'
                . '<td style="width:50%;vertical-align:top">' . $colB . '</td></tr></table>';
        } else {
            $index = '<table class="idx" style="width:78%;margin:0 auto">' . $idxHead . implode('', $rows) . '</table>';
        }

        $cover = '<div class="cover">'
            . '<table class="band"><tr><td style="text-align:center;padding:44px 30px 40px">'
            . $logoBlock
            . ($tagline ? '<div style="margin-top:14px;font-size:12px;color:#d1fae5;font-weight:bold;letter-spacing:.6px">' . $tagline . '</div>' : '')
            . '</td></tr></table>'
            . '<div style="text-align:center;margin-top:38px">'
            . '<div class="ctitle">PRODUCT CATALOGUE</div>'
            . '<div style="margin-top:8px"><span class="datechip">' . $date . '</span></div>'
            . '</div>'
            . '<div style="margin-top:30px">' . $index . '</div>'
            . '<table class="coverfoot"><tr><td>'
            . $company . ($phone ? ' &nbsp;·&nbsp; ' . e($phone) : '') . ($email ? ' &nbsp;·&nbsp; ' . e($email) : '')
            . '</td></tr></table>'
            . '</div>';

        // --- category pages ---
        $body = '';
        foreach ($plan as $p) {
            $total = count($p['chunks']);
            foreach ($p['chunks'] as $ci => $items) {
                $contLabel = $total > 1 ? ' <span style="font-weight:normal;color:#bbf7d0;font-size:10px">&nbsp;' . ($ci + 1) . ' / ' . $total . '</span>' : '';
                $body .= '<div class="page">'
                    . $this->pageHeader($company, $logo, $date, $brandD)
                    . '<table class="catband"><tr><td>' . e($p['category']) . $contLabel . '</td>'
                    . '<td style="text-align:right"><span class="pill">' . (int) $p['count'] . ' products</span></td></tr></table>'
                    . $this->grid($items, $currency, $withPrices, $thumbs)
                    . $this->pageFooter($phone, $currency, $dateFull, $withPrices)
                    . '</div>';
            }
        }

        // --- back page: how to order (also prevents any trailing-blank-page break) ---
        $steps = [
            ['1', 'Send your order on WhatsApp', $phone !== '' ? e($phone) : 'our WhatsApp line' ],
            ['2', 'We confirm stock &amp; total', 'You get an order confirmation with the amount'],
            ['3', 'Pick up or delivery', 'Collect at the store or ask about delivery'],
        ];
        $stepHtml = '';
        foreach ($steps as [$n, $t, $s]) {
            $stepHtml .= '<table class="step"><tr>'
                . '<td style="width:34px"><div class="stepn">' . $n . '</div></td>'
                . '<td><div style="font-size:13px;font-weight:bold;color:' . $ink . '">' . $t . '</div>'
                . '<div style="font-size:10.5px;color:#64748b;margin-top:2px">' . $s . '</div></td>'
                . '</tr></table>';
        }
        $back = '<div class="backpage">'
            . $this->pageHeader($company, $logo, $date, $brandD)
            . '<table class="catband"><tr><td>How to order</td></tr></table>'
            . '<table style="width:100%;border-collapse:collapse"><tr><td style="width:55%;vertical-align:top;padding-right:16px">' . $stepHtml . '</td>'
            . '<td style="vertical-align:top">'
            . '<div class="ordercard">'
            . '<div style="font-size:10px;font-weight:bold;letter-spacing:1.5px;color:#bbf7d0">ORDER ON WHATSAPP</div>'
            . '<div style="font-size:19px;font-weight:bold;color:#ffffff;margin-top:6px">' . ($phone !== '' ? e($phone) : $company) . '</div>'
            . ($email !== '' ? '<div style="font-size:10.5px;color:#d1fae5;margin-top:8px">' . e($email) . '</div>' : '')
            . '</div>'
            . '</td></tr></table>'
            . '<div style="margin-top:22px;font-size:9px;color:#94a3b8">'
            . ($withPrices ? 'All prices in ' . e($currency) . ', valid as of ' . e($dateFull) . ' and subject to change without notice. ' : '')
            . 'Availability confirmed on order. Thank you for your business.</div>'
            . '</div>';

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 26px 30px 34px; }
*{font-family:"DejaVu Sans",Arial,sans-serif;}
body{margin:0;padding:0;color:{$ink};}
.band{width:100%;border-collapse:collapse;background:{$brand};border-radius:12px;}
.ctitle{font-size:27px;font-weight:bold;letter-spacing:5px;color:{$ink};}
.datechip{display:inline-block;background:#f0fdf4;border:1px solid #bbf7d0;color:{$brandD};font-size:10.5px;font-weight:bold;padding:5px 14px;border-radius:12px;letter-spacing:1px;}
table.idx{border-collapse:collapse;width:100%;border:1px solid #e2e8f0;border-radius:8px;}
table.idx th{font-size:9px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;border-bottom:2px solid {$brand};background:#ffffff;}
.coverfoot{width:100%;border-collapse:collapse;margin-top:34px;border-top:1px solid #e2e8f0;}
.coverfoot td{padding-top:12px;text-align:center;font-size:9.5px;color:#94a3b8;font-weight:bold;}
.page{page-break-before:always;}
.backpage{page-break-before:always;}
.phead{width:100%;border-collapse:collapse;border-bottom:2px solid {$brand};}
.phead td{padding:0 0 7px;vertical-align:bottom;}
.pcompany{font-size:14px;font-weight:bold;}
.pdoc{font-size:9px;font-weight:bold;letter-spacing:2px;color:{$brandD};text-transform:uppercase;}
.pdate{font-size:8.5px;color:#94a3b8;}
table.catband{width:100%;border-collapse:collapse;margin:12px 0 4px;background:{$brandD};border-radius:8px;}
table.catband td{padding:9px 14px;vertical-align:middle;font-size:16px;font-weight:bold;color:#ffffff;letter-spacing:-.2px;}
.pill{background:#ffffff;color:{$brandD};font-size:9px;font-weight:bold;padding:3px 10px;border-radius:9px;letter-spacing:.5px;}
table.grid{width:100%;border-collapse:separate;border-spacing:8px;table-layout:fixed;}
td.cell{width:33%;border:1px solid #e2e8f0;border-radius:8px;vertical-align:top;padding:0;background:#ffffff;}
td.cellpad{border:none;background:none;}
table.phw{width:100%;border-collapse:collapse;}
table.phw td{height:132px;text-align:center;vertical-align:middle;padding:4px 4px 0;}
table.phw img{max-height:126px;max-width:100%;}
.noimg{display:inline-block;color:#cbd5e1;font-size:8px;font-weight:bold;letter-spacing:1.5px;border:1px dashed #e2e8f0;border-radius:6px;padding:26px 18px;background:#f8fafc;}
.pbody{padding:7px 10px 9px;}
.pn1{font-weight:bold;font-size:11px;height:16px;overflow:hidden;}
.pu{font-size:9.5px;color:#64748b;font-weight:bold;height:13px;overflow:hidden;margin-top:2px;}
.pm{font-size:9px;color:#94a3b8;height:12px;overflow:hidden;margin-top:1px;}
table.prow{width:100%;border-collapse:collapse;margin-top:6px;border-top:1px solid #f1f5f9;}
table.prow td{padding-top:5px;vertical-align:bottom;}
.pr{color:{$brandD};font-weight:bold;font-size:13.5px;white-space:nowrap;}
.pfrom{font-size:7.5px;color:#94a3b8;font-weight:bold;letter-spacing:.5px;}
.sku{text-align:right;font-size:8.5px;color:#94a3b8;font-weight:bold;}
.pfoot{width:100%;border-collapse:collapse;border-top:1px solid #e2e8f0;margin-top:8px;}
.pfoot td{padding:7px 2px 0;font-size:9px;color:#94a3b8;font-weight:bold;}
.pfoot .wa{text-align:right;color:{$brandD};}
.step{width:100%;border-collapse:collapse;margin:0 0 14px;}
.stepn{width:26px;height:26px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:13px;color:{$brandD};font-weight:bold;font-size:13px;text-align:center;line-height:26px;}
.ordercard{background:{$brandD};border-radius:10px;padding:18px 20px;}
</style></head><body>
{$cover}
{$body}
{$back}
</body></html>
HTML;
    }

    private function pageHeader(string $company, string $logo, string $date, string $brandD): string
    {
        $logoTag = $logo !== '' ? '<img src="' . e($logo) . '" style="height:26px;max-width:90px">' : '';

        return '<table class="phead"><tr>'
            . '<td><div class="pcompany">' . $company . '</div></td>'
            . '<td style="text-align:right">' . ($logoTag !== '' ? $logoTag
                : '<div class="pdoc">Product Catalogue</div><div class="pdate">' . $date . '</div>') . '</td>'
            . '</tr></table>';
    }

    /** @param array<int,array<string,mixed>> $items */
    private function grid(array $items, string $currency, bool $withPrices, array $thumbs): string
    {
        $cells = [];
        foreach ($items as $it) {
            $img = trim((string) ($it['image'] ?? ''));
            $src = $img !== '' ? ($thumbs === [] ? $img : ($thumbs[$img] ?? '')) : '';
            $photo = $src !== ''
                ? '<table class="phw"><tr><td><img src="' . e($src) . '"></td></tr></table>'
                : '<table class="phw"><tr><td><span class="noimg">PHOTO<br>COMING SOON</span></td></tr></table>';

            $unit = trim((string) ($it['unit'] ?? ''));
            $moq = ! empty($it['moq'])
                ? 'Min order: ' . e((string) $it['moq']) . ' &times; ' . e($unit !== '' ? $unit : 'units')
                : '&nbsp;';
            $priceCell = $withPrices
                ? '<div class="pfrom">FROM</div><div class="pr">' . $this->money((float) $it['price'], $currency) . '</div>'
                : '<div class="pr" style="color:#94a3b8;font-size:10px">Ask for price</div>';

            $cells[] = '<td class="cell">' . $photo
                . '<div class="pbody">'
                . $this->nameLines((string) $it['name'])
                . '<div class="pu">' . ($unit !== '' ? e($unit) : '&nbsp;') . '</div>'
                . '<div class="pm">' . $moq . '</div>'
                . '<table class="prow"><tr><td>' . $priceCell . '</td>'
                . '<td class="sku">' . ($it['sku'] ? e((string) $it['sku']) : '') . '</td></tr></table>'
                . '</div></td>';
        }

        // pad the final row to 3 columns so the table layout stays aligned
        while (count($cells) % 3 !== 0) {
            $cells[] = '<td class="cell cellpad"></td>';
        }

        $rows = '';
        foreach (array_chunk($cells, 3) as $row) {
            $rows .= '<tr>' . implode('', $row) . '</tr>';
        }

        return '<table class="grid">' . $rows . '</table>';
    }

    /**
     * Product name as EXACTLY two fixed-height single-line divs (second may be blank).
     * dompdf's line boxes ignore CSS line-height enough to glyph-slice a clipped multi-line
     * block, so we do the wrapping ourselves: greedy word wrap at a char budget per line,
     * ellipsis if it can't fit two lines. Every card gets the same 32px name zone.
     */
    private function nameLines(string $name, int $perLine = 30): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $l1 = '';
        $l2 = '';
        foreach ($words as $w) {
            if ($l2 === '' && mb_strlen(($l1 === '' ? $w : "$l1 $w"), 'UTF-8') <= $perLine) {
                $l1 = $l1 === '' ? $w : "$l1 $w";
            } elseif (mb_strlen(($l2 === '' ? $w : "$l2 $w"), 'UTF-8') <= $perLine) {
                $l2 = $l2 === '' ? $w : "$l2 $w";
            } else {
                $l2 = rtrim(mb_substr($l2 === '' ? $w : "$l2 $w", 0, $perLine - 1, 'UTF-8')) . '…';
                break;
            }
        }
        if ($l1 !== '' && $l2 === '' && mb_strlen($l1, 'UTF-8') > $perLine) {
            $l2 = rtrim(mb_substr($l1, $perLine, $perLine - 1, 'UTF-8')) . '…';
            $l1 = mb_substr($l1, 0, $perLine, 'UTF-8');
        }

        return '<div class="pn1">' . e($l1) . '</div><div class="pn1">' . ($l2 !== '' ? e($l2) : '&nbsp;') . '</div>';
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
