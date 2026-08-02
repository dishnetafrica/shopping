<?php

namespace App\Services\Catalog;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Branded PRODUCT CATALOGUE PDF — a photo-first brochure the wholesaler sends to customers.
 *
 * v3 "rich" layout (same public API as v1/v2). Every rich element is optional and degrades
 * gracefully, so the catalogue always renders even with zero setup:
 *
 *   cover        photographic cover when the `catalog_cover_image` setting is set (hero photo,
 *                audience checklist, product count, QR) — else the clean brand-band cover.
 *   overview     "Explore our categories" page (photo card per category + page numbers) when
 *                there are ≥2 categories; single-category catalogues keep the index on the cover.
 *   category     first page opens with a hero band (name, count, top-seller mini-strip, hero
 *                photo) + 6 cards; continuation pages carry 9 cards.
 *   badges       BEST SELLER / HOT DEAL chips on cards, driven by the `catalog_best_sellers`
 *                and `catalog_hot_deals` settings (comma-separated SKUs or exact names).
 *   QR           WhatsApp order QR (wa.me link) — `catalog_qr_image` setting if provided, else
 *                fetched once from quickchart.io and cached; omitted silently if unreachable.
 *   back page    promo band ("Need a product you don't see?" + optional `catalog_promo_image`),
 *                3 ordering steps, big WhatsApp block, QR.
 *
 * Inherited from v2 (all verified against dompdf 3): strict page budgets (no orphan/blank
 * pages), correct index page numbers, "Page N of T" numbering, photos pre-fetched in parallel
 * and normalised onto identical 4:3 white canvases (disk-cached, data-URI embedded — a 33 MB
 * v1 document becomes ~3-4 MB), deterministic two-line names, equal-height cards, font
 * subsetting. dompdf has no flex/grid engine, so everything is tables + block flow.
 * Renders only; reads product data and prices as-is. Degrades gracefully without GD.
 */
final class CatalogRenderer
{
    private const PER_PAGE = 9;           // product grid pages: 3 × 3
    private const INTRO_MIN = 7;          // categories this big open with a full intro page
                                          // (slim-band pages hold ≤6 cards — band + 3 card rows overflows A4)
    private const BRAND = '#16a34a';
    private const BRAND_DARK = '#0f7a37';
    private const INK = '#0f172a';

    private const THUMB_W = 440;          // 4:3 canvas every photo is normalised onto
    private const THUMB_H = 330;
    private const FETCH_TIMEOUT = 4;      // per-image seconds
    private const FETCH_PARALLEL = 10;    // curl_multi lanes

    public function available(): bool
    {
        if (! class_exists(\Dompdf\Dompdf::class)) {
            \Log::warning('CatalogRenderer: dompdf/dompdf is not installed (composer)');

            return false;
        }

        return true;
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

        // First render of a big catalogue downloads + resizes hundreds of photos — do not let
        // PHP's default 30-60s web time limit kill the request halfway (fatal 500, uncatchable).
        @set_time_limit(300);

        // Three-stage ladder. Each stage narrows the failure domain and is logged with file:line,
        // so laravel.log names the broken feature while the customer still gets a catalogue:
        //   1. rich        thumbnails + cover art + QR + badges
        //   2. thumbs-only same thumbnails, no artwork/QR/badges  (small PDF preserved)
        //   3. plain       direct image URLs, zero external deps  (fat but never fails)
        $thumbs = [];
        try {
            $thumbs = $this->thumbs($tenant, $groups);
        } catch (\Throwable $e) {
            \Log::warning('CatalogRenderer thumbnail pipeline failed: ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ' — images will embed at full size');
            $thumbs = [];
        }

        $html = null;
        try {
            $html = $this->html($tenant, $groups, $currency, $withPrices, $thumbs);
        } catch (\Throwable $e) {
            \Log::warning('CatalogRenderer rich layout failed: ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ' — retrying without artwork/QR/badges');
        }
        if ($html === null) {
            try {
                $html = $this->html($tenant, $groups, $currency, $withPrices, $thumbs, true);
            } catch (\Throwable $e) {
                \Log::warning('CatalogRenderer thumbs-only layout failed: ' . $e->getMessage()
                    . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ' — retrying plain');
            }
        }

        try {
            if ($html === null) {
                $html = $this->html($tenant, $groups, $currency, $withPrices, [], true);
            }
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
                    'oem' => (string) ($p->oem_number ?? ''),      // v4.7.7 — manufacturer part no. (mechanics order by this)
                    'brand' => (string) ($p->brand ?? ''),         // v4.7.7 — powers the per-card "Fits" line
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

    /**
     * One category as its own compact catalogue (cover + product pages + how-to-order), for the
     * bot answering "send me the catalogue for X". Cached 6 h per category, invalidated by
     * catalogue:flush. Category match is case-insensitive on the exact stored name.
     */
    public function forCategory(Tenant $tenant, string $category, bool $withPrices = true): ?array
    {
        try {
            $cat = trim($category);
            if ($cat === '') {
                return null;
            }
            $key = 'catalog_pdf:' . $tenant->id . ':cat:' . md5(mb_strtolower($cat, 'UTF-8')) . ':' . ($withPrices ? 'p' : 'np');
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
                ->whereRaw("LOWER(COALESCE(category, '')) = ?", [mb_strtolower($cat, 'UTF-8')])
                ->orderBy('name')->get();
            if ($products->isEmpty()) {
                return null;
            }

            $cur = (string) ($tenant->setting('currency', 'UGX') ?: 'UGX');
            $catName = trim((string) $products->first()->category) ?: $cat;
            $items = [];
            foreach ($products as $p) {
                $items[] = [
                    'name' => (string) $p->name,
                    'unit' => (string) ($p->unit_label ?: ''),
                    'moq' => $p->moq ? (int) $p->moq : null,
                    'price' => (float) $p->price,
                    'sku' => (string) ($p->sku ?: ''),
                    'oem' => (string) ($p->oem_number ?? ''),      // v4.7.7
                    'brand' => (string) ($p->brand ?? ''),         // v4.7.7
                    'image' => (string) ($p->image_url ?: ''),
                ];
            }

            $doc = $this->pdf($tenant, [['category' => $catName, 'items' => $items]], $cur, $withPrices);
            if ($doc) {
                \Illuminate\Support\Facades\Cache::put($key,
                    ['path' => $doc['path'], 'fileName' => $doc['fileName']], now()->addHours(6));
            }

            return $doc;
        } catch (\Throwable $e) {
            \Log::warning('CatalogRenderer::forCategory failed: ' . $e->getMessage());

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
        $fetch = [];   // url => cache base path (missing from disk cache)
        foreach (array_keys($urls) as $u) {
            $base = 'catalog-thumbs/' . $tenant->id . '/' . md5($u);
            $hit = null;
            foreach (['jpg' => 'image/jpeg', 'png' => 'image/png'] as $ext => $mime) {
                try {
                    if (Storage::disk('public')->exists("$base.$ext")) {
                        $bytes = Storage::disk('public')->get("$base.$ext");
                        if ($bytes) {
                            $hit = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                        }
                        break;
                    }
                } catch (\Throwable $e) {
                    break;
                }
            }
            if ($hit !== null) {
                $out[$u] = $hit;
            } else {
                $fetch[$u] = $base;
            }
        }

        foreach ($this->fetchMany(array_keys($fetch)) as $u => $raw) {
            $enc = $raw !== null ? $this->normalise($raw) : null;
            if ($enc !== null) {
                [$bytes, $mime] = $enc;
                $ext = $mime === 'image/png' ? 'png' : 'jpg';
                try { Storage::disk('public')->put($fetch[$u] . '.' . $ext, $bytes); } catch (\Throwable $e) { /* cache only */ }
                $out[$u] = 'data:' . $mime . ';base64,' . base64_encode($bytes);
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
            } elseif (($own = $this->ownStorageBytes($u)) !== null) {
                $res[$u] = $own;   // our own /storage/ file — read from disk, skip HTTP entirely
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

        $deadline = microtime(true) + 120;   // whole-fetch budget; stragglers become placeholders
        foreach (array_chunk($remote, self::FETCH_PARALLEL) as $batch) {
            if (microtime(true) > $deadline) {
                foreach ($batch as $u) $res[$u] = null;
                continue;
            }
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

    /**
     * If a URL points at THIS app's public storage (any hostname — the canonical domain, a
     * custom domain, or an internal container host like *.easypanel.host that a misconfigured
     * APP_URL may have baked into saved settings), read the bytes straight from the disk.
     * Faster than HTTP for every product photo, and immune to domain/ingress problems.
     */
    private function ownStorageBytes(string $url): ?string
    {
        if (! preg_match('~^https?://[^/]+/storage/(.+)$~i', $url, $m)) {
            return null;
        }
        $rel = urldecode($m[1]);
        if (str_contains($rel, '..')) {
            return null;
        }
        try {
            if (Storage::disk('public')->exists($rel)) {
                $bytes = Storage::disk('public')->get($rel);

                return ($bytes !== null && $bytes !== '') ? $bytes : null;
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /** Contain-fit raw image bytes onto a white 4:3 canvas → [bytes, mime] (null if undecodable). */
    private function normalise(string $raw): ?array
    {
        if (! $this->decodable($raw)) {
            return null;   // would exhaust memory_limit mid-decode — a FATAL, not an exception
        }
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

        $out = $this->encodeCanvas($canvas);
        imagedestroy($canvas);

        return $out;
    }

    /**
     * Encode a GD canvas with the best encoder THIS PHP build has. Some containers compile GD
     * without JPEG (docker-php-ext-install gd with no --with-jpeg — exactly the production
     * fatal `Call to undefined function imagejpeg()`), so the ladder is:
     * JPEG (best for photos) → Imagick-JPEG → 8-bit palette PNG (universally available).
     *
     * @return array{0:string,1:string}|null [bytes, mime]
     */
    private function encodeCanvas(\GdImage $canvas): ?array
    {
        if (function_exists('imagejpeg') && ! getenv('CATALOG_FORCE_NO_JPEG')) {
            ob_start();
            imagejpeg($canvas, null, 80);
            $b = ob_get_clean();
            if ($b) {
                return [$b, 'image/jpeg'];
            }
        }
        if (class_exists(\Imagick::class)) {
            try {
                ob_start();
                imagepng($canvas, null, 1);
                $png = ob_get_clean();
                if ($png) {
                    $im = new \Imagick();
                    $im->readImageBlob($png);
                    $im->setImageFormat('jpeg');
                    $im->setImageCompressionQuality(80);
                    $b = $im->getImagesBlob();
                    $im->clear();
                    if ($b) {
                        return [$b, 'image/jpeg'];
                    }
                }
            } catch (\Throwable $e) {
                // fall through to PNG
            }
        }
        if (function_exists('imagepng')) {
            // 8-bit palette + dithering keeps photo thumbs ~40-70 KB instead of ~200 KB truecolor
            imagetruecolortopalette($canvas, true, 255);
            ob_start();
            imagepng($canvas, null, 9);
            $b = ob_get_clean();
            if ($b) {
                return [$b, 'image/png'];
            }
        }

        return null;
    }

    /**
     * A 20-megapixel phone photo needs ~100 MB of GD buffer; decoding it inside a 128 MB
     * memory_limit dies with an uncatchable fatal. Check dimensions BEFORE decoding and skip
     * anything that will not comfortably fit in the remaining headroom (placeholder instead).
     */
    private function decodable(string $raw): bool
    {
        $info = @getimagesizefromstring($raw);
        if (! $info || $info[0] < 2 || $info[1] < 2) {
            return false;
        }
        $needed = $info[0] * $info[1] * 5 + strlen($raw);          // truecolor buffer + raw bytes
        $limit = ini_get('memory_limit');
        $bytes = match (strtoupper(substr((string) $limit, -1))) {
            'G' => (int) $limit * 1073741824,
            'M' => (int) $limit * 1048576,
            'K' => (int) $limit * 1024,
            default => (int) $limit,
        };
        if ($bytes <= 0) {
            return $needed < 256 * 1048576;                        // memory_limit=-1 → sane cap
        }

        return memory_get_usage(true) + $needed < $bytes - 32 * 1048576;   // keep 32 MB headroom
    }

    /**
     * Fetch a standalone artwork asset (cover hero / promo / QR), downscale keeping its own
     * aspect ratio, cache on disk, return a data URI ('' when missing or unreachable — every
     * caller has a no-asset fallback). $asIs keeps original bytes (QR codes must stay crisp).
     */
    private function asset(Tenant $tenant, string $url, int $maxDim = 1000, bool $asIs = false, ?float $cropRatio = null): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $base = 'catalog-thumbs/' . $tenant->id . '/asset-' . md5($url . ($asIs ? ':raw' : ':' . $maxDim . ':' . ($cropRatio ?? 0)));
        try {
            foreach (['png' => 'image/png', 'jpg' => 'image/jpeg'] as $ext => $mime) {
                if (Storage::disk('public')->exists("$base.$ext")) {
                    $bytes = Storage::disk('public')->get("$base.$ext");
                    if ($bytes) {
                        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
                    }
                    break;
                }
            }
        } catch (\Throwable $e) {
        }

        $raw = $this->fetchMany([$url])[$url] ?? null;
        if ($raw === null) {
            return '';
        }
        if ($asIs || ! extension_loaded('gd')) {
            $ext = $asIs ? 'png' : 'jpg';
            try { Storage::disk('public')->put("$base.$ext", $raw); } catch (\Throwable $e) { }

            return 'data:image/' . ($asIs ? 'png' : 'jpeg') . ';base64,' . base64_encode($raw);
        }
        if (! $this->decodable($raw)) {
            return '';
        }

        $src = @imagecreatefromstring($raw);
        if (! $src) {
            return '';
        }
        $sw = imagesx($src); $sh = imagesy($src);
        if ($cropRatio !== null && $cropRatio > 0) {
            // cover-crop to the exact target ratio (w/h) so the layout slot is always filled
            $dw = (int) min($maxDim * min($cropRatio, 1.0), 1200);
            $dh = (int) round($dw / $cropRatio);
            $scale = max($dw / $sw, $dh / $sh);
            $cw = (int) round($dw / $scale); $ch = (int) round($dh / $scale);
            $sx = (int) (($sw - $cw) / 2); $sy = (int) (($sh - $ch) / 2);
            $dst = imagecreatetruecolor($dw, $dh);
            imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $cw, $ch);
        } else {
            $scale = min($maxDim / max($sw, 1), $maxDim / max($sh, 1), 1.0);
            $dw = max(1, (int) round($sw * $scale)); $dh = max(1, (int) round($sh * $scale));
            $dst = imagecreatetruecolor($dw, $dh);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        }
        imagedestroy($src);
        $enc = $this->encodeCanvas($dst);
        imagedestroy($dst);
        if ($enc === null) {
            return '';
        }
        [$bytes, $mime] = $enc;
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        try { Storage::disk('public')->put("$base.$ext", $bytes); } catch (\Throwable $e) { }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * Cover artwork, mode-aware. A PORTRAIT image (like a fully-designed A4/2:3 cover exported
     * from an AI tool, text and QR already in the picture) becomes the ENTIRE cover page,
     * untouched. A landscape/square image is treated as a hero PHOTO and cover-cropped into the
     * composed layout (headline, checklist, stat box drawn by us).
     * @return array{0:string,1:string} [dataUri|'', 'full'|'hero']
     */
    private function coverAsset(Tenant $tenant, string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['', 'hero'];
        }
        $ratio = null;   // w/h
        try {
            $raw = $this->fetchMany([$url])[$url] ?? null;
            if ($raw !== null) {
                $info = @getimagesizefromstring($raw);
                if ($info && $info[0] > 1 && $info[1] > 1) {
                    $ratio = $info[0] / $info[1];
                }
            }
        } catch (\Throwable $e) {
        }
        if ($ratio !== null && $ratio < 0.87) {   // clearly portrait → full-page cover art
            return [$this->asset($tenant, $url, 1400), 'full'];
        }

        return [$this->asset($tenant, $url, 1100, false, 0.62), 'hero'];
    }

    /** WhatsApp order QR: explicit setting wins; else generated once via quickchart and cached. */
    private function qr(Tenant $tenant, string $phone): string
    {
        $own = (string) $tenant->setting('catalog_qr_image', '');
        if ($own !== '') {
            $uri = $this->asset($tenant, $own, 300, true);
            if ($uri !== '') {
                return $uri;
            }
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return '';
        }

        return $this->asset($tenant,
            'https://quickchart.io/qr?text=' . urlencode('https://wa.me/' . $digits) . '&size=280&margin=1',
            300, true);
    }

    // ─────────────────────────── document ───────────────────────────

    /** @param array<int,array<string,mixed>> $groups */
    private function html(Tenant $tenant, array $groups, string $currency, bool $withPrices, array $thumbs, bool $plain = false): string
    {
        $company = e((string) $tenant->name);
        $tagline = e((string) ($tenant->setting('tagline', '') ?: $tenant->setting('address', '') ?: ''));
        $phone = trim((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        // v4.7.7 — a half-typed public_phone ("+2567") was printing on every page. If the setting
        // doesn't hold a dialable number, fall back to the connected WhatsApp number.
        if (strlen(preg_replace('/\D+/', '', $phone)) < 9) {
            $alt = trim((string) $tenant->whatsapp_number);
            if (strlen(preg_replace('/\D+/', '', $alt)) >= 9) $phone = $alt;
        }
        $email = trim((string) $tenant->setting('public_email', $tenant->setting('email', '')));
        $webRaw = trim((string) ($tenant->setting('public_website', '') ?: $tenant->setting('bot_website_link', '')));
        $webText = $webRaw !== '' ? preg_replace('~^https?://~i', '', rtrim($webRaw, '/')) : '';
        $webHref = $webRaw !== '' ? (preg_match('~^https?://~i', $webRaw) ? $webRaw : 'https://' . $webRaw) : '';
        $logo = (string) $tenant->setting('logo', '');
        $date = now()->format('F Y');
        $dateFull = now()->format('d M Y');
        $brand = self::BRAND; $brandD = self::BRAND_DARK; $ink = self::INK;

        $badges = $plain ? [] : $this->badgeMap($tenant);
        [$coverImg, $coverMode] = $plain ? ['', 'hero'] : $this->coverAsset($tenant, (string) $tenant->setting('catalog_cover_image', ''));
        $promoImg = $plain ? '' : $this->asset($tenant, (string) $tenant->setting('catalog_promo_image', ''), 800);
        $qr = $plain ? '' : $this->qr($tenant, $phone);
        $totalItems = 0;
        foreach ($groups as $g) $totalItems += count($g['items']);

        // --- pagination plan: cover(1) [overview(2)] categories... back page(last) ---
        $showOverview = count($groups) >= 2;
        $plan = [];
        $page = $showOverview ? 3 : 2;
        foreach ($groups as $g) {
            $items = $g['items'];
            $chunks = $items ? array_chunk($items, self::PER_PAGE) : [[]];
            $intro = count($items) >= self::INTRO_MIN;
            $plan[] = ['category' => $g['category'], 'items' => $items, 'chunks' => $chunks,
                'intro' => $intro, 'startPage' => $page, 'count' => count($items)];
            $page += count($chunks) + ($intro ? 1 : 0);
        }

        $cover = $this->coverHtml($tenant, $plan, $company, $tagline, $logo, $phone, $email,
            $date, $coverImg, $qr, $totalItems, $showOverview, $webText, $webHref, $coverMode);
        $overview = $showOverview
            ? $this->overviewHtml($company, $logo, $date, $plan, $thumbs, $brandD, $promoImg)
            : '';

        // --- category pages ---
        $body = '';
        foreach ($plan as $p) {
            if ($p['intro']) {
                $body .= $this->categoryIntro($p, $thumbs, $badges, $currency, $withPrices,
                    $company, $logo, $date, $phone, $dateFull);
            }
            $total = count($p['chunks']);
            foreach ($p['chunks'] as $ci => $items) {
                $contLabel = $total > 1 ? ' <span style="font-weight:normal;color:#bbf7d0;font-size:10px">&nbsp;' . ($ci + 1) . ' / ' . $total . '</span>' : '';
                $head = (! $p['intro'] && $ci === 0)
                    ? $this->categoryHero($p, $thumbs, $badges)
                    : '<table class="catband"><tr><td>' . e($p['category']) . $contLabel . '</td>'
                        . '<td style="text-align:right"><span class="pill">' . (int) $p['count'] . ' products</span></td></tr></table>';
                $body .= '<div class="page">'
                    . $this->pageHeader($company, $logo, $date, $brandD)
                    . $head
                    . $this->grid($items, $currency, $withPrices, $thumbs, $badges)
                    . $this->pageFooter($phone, $currency, $dateFull, $withPrices, $webText, $webHref)
                    . '</div>';
            }
        }

        $fromCity = trim((string) $tenant->setting('delivery_from_city', 'Juba')) ?: 'Juba';
        $deliveryLine = trim((string) $tenant->setting('delivery_note', ''))
            ?: 'Free delivery within ' . $fromCity . ' — outside ' . $fromCity . ', transport is confirmed at order';
        // v4.7.7 — business details for the back page (review #8). Only filled settings print.
        $infoRows = array_values(array_filter([
            ['Business hours',  (string) $tenant->setting('business_hours', '')],
            ['Delivery areas',  (string) $tenant->setting('delivery_areas', '')],
            ['Payment methods', (string) $tenant->setting('sf_pay_methods', '')],
            ['Email',           $email],
            ['Location',        trim((string) $tenant->setting('address', '') . ' ' . (string) $tenant->setting('city', ''))],
        ], fn ($r) => trim($r[1]) !== ''));
        $back = $this->backHtml($company, $logo, $date, $phone, $email, $currency, $dateFull,
            $withPrices, $showOverview ? '' : $promoImg, $qr, ! $showOverview, $webText, $webHref,
            $deliveryLine, $infoRows, trim((string) $tenant->setting('catalog_badge', '')));

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 26px 30px 34px; }
*{font-family:"DejaVu Sans",Arial,sans-serif;}
body{margin:0;padding:0;color:{$ink};}
.band{width:100%;border-collapse:collapse;background:{$brand};border-radius:12px;}
.ctitle{font-size:27px;font-weight:bold;letter-spacing:5px;color:{$ink};}
.ctitle2{font-size:31px;font-weight:bold;letter-spacing:1px;color:{$ink};line-height:1.12;}
.datechip{display:inline-block;background:#f0fdf4;border:1px solid #bbf7d0;color:{$brandD};font-size:10.5px;font-weight:bold;padding:5px 14px;border-radius:12px;letter-spacing:1px;}
table.idx{border-collapse:collapse;width:100%;border:1px solid #e2e8f0;border-radius:8px;}
table.idx th{font-size:9px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;border-bottom:2px solid {$brand};background:#ffffff;}
.coverfoot{width:100%;border-collapse:collapse;margin-top:26px;border-top:1px solid #e2e8f0;}
.coverfoot td{padding-top:12px;text-align:center;font-size:9.5px;color:#94a3b8;font-weight:bold;}
.coverfix{position:relative;height:1056px;}
.coverbar{position:absolute;top:966px;left:0;width:100%;}
.coverfeat{position:absolute;top:872px;left:0;width:100%;}
.featsub{font-size:8px;color:#94a3b8;font-weight:normal;margin-top:2px;}
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
.pillg{background:{$brandD};color:#ffffff;font-size:9px;font-weight:bold;padding:3px 10px;border-radius:9px;letter-spacing:.5px;}
table.hero{width:100%;border-collapse:collapse;margin:12px 0 4px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;}
table.ihero{width:100%;border-collapse:collapse;margin:12px 0 4px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;}
table.hero td{padding:14px 16px;vertical-align:middle;}
.heroname{font-size:21px;font-weight:bold;letter-spacing:-.3px;}
.ts{font-size:8px;font-weight:bold;letter-spacing:1.5px;color:{$brandD};}
table.tsrow{border-collapse:separate;border-spacing:6px 0;margin-top:8px;}
table.tsrow td{background:#ffffff;border:1px solid #dcfce7;border-radius:8px;padding:3px;}
table.tsrow img{height:44px;max-width:56px;}
.heroimg img{max-height:120px;max-width:160px;}
table.grid{width:100%;border-collapse:separate;border-spacing:8px;table-layout:fixed;}
td.cell{width:33%;border:1px solid #e2e8f0;border-radius:8px;vertical-align:top;padding:0;background:#ffffff;}
td.cellpad{border:none;background:none;}
.bstrip{height:17px;padding:3px 6px 0;}
.badge{display:inline-block;color:#ffffff;font-size:7.5px;font-weight:bold;letter-spacing:.8px;padding:2px 7px;border-radius:8px;}
table.phw{width:100%;border-collapse:collapse;}
table.phw td{height:132px;text-align:center;vertical-align:middle;padding:0 4px;}
table.phw img{max-height:126px;max-width:100%;}
.noimg{display:inline-block;color:#cbd5e1;font-size:8px;font-weight:bold;letter-spacing:1.5px;border:1px dashed #e2e8f0;border-radius:6px;padding:22px 16px;background:#f8fafc;}
.pbody{padding:6px 10px 9px;}
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
.chk{width:15px;height:15px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;color:{$brandD};font-weight:bold;font-size:9px;text-align:center;line-height:15px;}
.statbox{background:{$brandD};border-radius:10px;padding:13px 18px;color:#ffffff;}
table.ovg{width:100%;border-collapse:separate;border-spacing:9px;table-layout:fixed;}
td.ovcell{border:1px solid #e2e8f0;border-radius:10px;vertical-align:top;padding:0;background:#ffffff;}
table.ovph{width:100%;border-collapse:collapse;}
table.ovph td{height:104px;text-align:center;vertical-align:middle;background:#f8fafc;border-bottom:1px solid #f1f5f9;}
table.ovph img{max-height:96px;max-width:90%;}
.ovbody{padding:9px 12px 11px;}
.ovname{font-weight:bold;font-size:12px;height:16px;overflow:hidden;}
table.feat{width:100%;border-collapse:collapse;margin-top:14px;background:#f8faf8;border:1px solid #eef2ee;border-radius:10px;}
table.feat td{padding:10px 12px;font-size:9.5px;color:#475569;font-weight:bold;}
.promo{width:100%;border-collapse:collapse;background:{$brandD};border-radius:10px;margin:12px 0 14px;}
.promo td{padding:16px 18px;vertical-align:middle;}
.promoimg img{max-height:86px;max-width:150px;border-radius:6px;}
.qrbox{background:#ffffff;border-radius:8px;padding:6px;display:inline-block;}
</style></head><body>
{$cover}
{$overview}
{$body}
{$back}
</body></html>
HTML;
    }

    /** Comma-separated settings → [normalised sku/name => badge]. */
    private function badgeMap(Tenant $tenant): array
    {
        $map = [];
        $lists = [
            ['keys' => (string) $tenant->setting('catalog_best_sellers', ''), 'label' => 'BEST SELLER', 'bg' => self::BRAND],
            ['keys' => (string) $tenant->setting('catalog_hot_deals', ''), 'label' => 'HOT DEAL', 'bg' => '#ea580c'],
        ];
        foreach ($lists as $l) {
            foreach (explode(',', $l['keys']) as $k) {
                $k = mb_strtolower(trim($k), 'UTF-8');
                if ($k !== '') $map[$k] = ['label' => $l['label'], 'bg' => $l['bg']];
            }
        }

        return $map;
    }

    private function badgeFor(array $item, array $badges): ?array
    {
        if (! $badges) {
            return null;
        }
        foreach ([(string) ($item['sku'] ?? ''), (string) ($item['name'] ?? '')] as $key) {
            $k = mb_strtolower(trim($key), 'UTF-8');
            if ($k !== '' && isset($badges[$k])) {
                return $badges[$k];
            }
        }

        return null;
    }

    private function coverHtml(Tenant $tenant, array $plan, string $company, string $tagline,
        string $logo, string $phone, string $email, string $date, string $coverImg, string $qr,
        int $totalItems, bool $showOverview, string $webText = '', string $webHref = '',
        string $coverMode = 'hero'): string
    {
        // Fully-designed portrait artwork = the whole first page, nothing drawn over it.
        if ($coverImg !== '' && $coverMode === 'full') {
            return '<div class="cover" style="text-align:center">'
                . '<img src="' . e($coverImg) . '" style="max-width:100%;max-height:1046px;border-radius:10px">'
                . '</div>';
        }

        $webLine = $webText !== ''
            ? '<a href="' . e($webHref) . '" style="color:#d1fae5;text-decoration:none;font-weight:bold">' . e($webText) . '</a>'
            : '';
        $brand = self::BRAND; $brandD = self::BRAND_DARK;
        // v4.7.7 — optional trust badge + value-prop tags on the cover (catalogue review #3/#7).
        // Both are plain settings; blank = exactly the old cover. e.g. badge "✓ Genuine OEM-quality
        // parts", tags "Wholesale Distributor · East Africa Coverage · Fast Delivery".
        $badgeTxt  = trim((string) $tenant->setting('catalog_badge', ''));
        $coverTags = trim((string) $tenant->setting('catalog_cover_tags', ''));
        $badgeChip = $badgeTxt !== ''
            ? '<span style="display:inline-block;background:#f0fdf4;border:1px solid #86efac;color:' . self::BRAND_DARK . ';font-size:10px;font-weight:bold;padding:4px 12px;border-radius:12px">' . e($this->clipLine($badgeTxt, 46)) . '</span>'
            : '';
        $logoBlockDark = $logo !== ''
            ? '<img src="' . e($logo) . '" style="height:40px;max-width:150px">'
            : '<div style="font-size:19px;font-weight:bold;color:' . $brandD . '">' . $company . '</div>';

        $contactBar = '<table style="width:100%;border-collapse:collapse;background:' . $brandD . ';border-radius:10px;margin-top:16px"><tr>'
            . ($qr !== '' ? '<td style="width:74px;padding:10px 0 10px 14px"><span class="qrbox"><img src="' . e($qr) . '" style="height:52px;width:52px"></span></td>'
                . '<td style="padding:10px 6px;color:#ffffff;font-size:10px;font-weight:bold;width:120px">SCAN TO ORDER<br>ON WHATSAPP</td>' : '')
            . '<td style="padding:10px 14px;text-align:right;color:#d1fae5;font-size:9.5px;font-weight:bold">'
            . ($phone !== '' ? '☎ ' . e($phone) . '<br>' : '') . ($email !== '' ? '✉ ' . e($email) : '')
            . ($webLine !== '' ? ($phone !== '' || $email !== '' ? '<br>' : '') . $webLine : '')
            . '</td></tr></table>';

        if ($coverImg !== '') {
            // Photographic cover: text column left, hero photo right, contact/QR bar bottom.
            $audience = array_filter(array_map('trim', explode(',',
                (string) $tenant->setting('catalog_audience', 'Hotels,Restaurants,Supermarkets,Retailers'))));
            $audRows = '';
            foreach (array_slice($audience, 0, 5) as $a) {
                $audRows .= '<table style="border-collapse:collapse;margin-top:7px"><tr>'
                    . '<td style="width:21px"><div class="chk">✓</div></td>'
                    . '<td style="font-size:11px;font-weight:bold;color:#334155;padding-left:4px">For ' . e($a) . '</td></tr></table>';
            }

            $features = '<div class="coverfeat"><table class="feat"><tr>'
                . '<td style="width:25%">✓ Great prices<div class="featsub">Best wholesale rates</div></td>'
                . '<td style="width:25%">✓ Quality assured<div class="featsub">Genuine products</div></td>'
                . '<td style="width:25%">✓ Fast-moving lines<div class="featsub">High-demand stock</div></td>'
                . '<td style="width:25%">✓ Reliable supply<div class="featsub">Consistent availability</div></td>'
                . '</tr></table></div>';

            return '<div class="cover coverfix">'
                . '<table style="width:100%;border-collapse:collapse"><tr><td style="padding:2px 0 10px">' . $logoBlockDark
                . ($tagline ? '<div style="font-size:9.5px;color:#64748b;font-weight:bold;margin-top:3px">' . $tagline . '</div>' : '')
                . '</td></tr></table>'
                . '<table style="width:100%;border-collapse:collapse"><tr>'
                . '<td style="width:47%;vertical-align:top;padding-top:22px">'
                . '<div style="font-size:15px;font-weight:bold;letter-spacing:3px;color:#64748b">WHOLESALE</div>'
                . '<div class="ctitle2" style="color:' . $brandD . '">PRODUCT<br>CATALOGUE</div>'
                . '<div style="margin-top:14px"><span class="datechip">' . $date . '</span>' . ($badgeChip !== '' ? ' ' . $badgeChip : '') . '</div>'
                . ($coverTags !== '' ? '<div style="margin-top:10px;font-size:9.5px;font-weight:bold;color:#64748b;letter-spacing:.4px">' . e($this->clipLine($coverTags, 90)) . '</div>' : '')
                . '<div style="margin-top:20px">' . $audRows . '</div>'
                . '<table style="border-collapse:collapse;margin-top:24px"><tr><td><div class="statbox">'
                . '<div style="font-size:22px;font-weight:bold">' . number_format($totalItems) . '+</div>'
                . '<div style="font-size:8px;font-weight:bold;letter-spacing:1.5px;color:#bbf7d0">WHOLESALE PRODUCTS</div>'
                . '</div></td></tr></table>'
                . '</td>'
                . '<td style="width:53%;vertical-align:top;padding:12px 0 0 16px">'
                . '<img src="' . e($coverImg) . '" style="width:100%;border-radius:12px">'
                . '</td></tr></table>'
                . $features
                . '<div class="coverbar">' . $contactBar . '</div>'
                . '</div>';
        }

        // Brand-band cover (no artwork needed). Single-category catalogues keep the index here.
        $logoBlock = $logo !== ''
            ? '<div style="background:#ffffff;border-radius:10px;padding:12px 20px;display:inline-block"><img src="' . e($logo) . '" style="height:56px;max-width:170px"></div>'
            : '<div style="font-size:30px;font-weight:bold;color:#ffffff;letter-spacing:-.5px">' . $company . '</div>';

        $middle = '';
        if (! $showOverview) {
            $rows = '';
            foreach ($plan as $i => $p) {
                $bg = $i % 2 === 0 ? '#ffffff' : '#f8faf8';
                $rows .= '<tr><td style="padding:8px 12px;background:' . $bg . ';font-weight:bold;font-size:11.5px">' . e($p['category']) . '</td>'
                    . '<td style="padding:8px 6px;background:' . $bg . ';color:#94a3b8;font-size:10px">' . (int) $p['count'] . ' items</td>'
                    . '<td style="padding:8px 12px;background:' . $bg . ';text-align:right;color:' . $brandD . ';font-weight:bold;font-size:11px">p.' . (int) $p['startPage'] . '</td></tr>';
            }
            $middle = '<div style="margin-top:26px"><table class="idx" style="width:78%;margin:0 auto">'
                . '<tr><th style="text-align:left;padding:7px 12px">Category</th><th style="text-align:left;padding:7px 6px">Items</th><th style="text-align:right;padding:7px 12px">Page</th></tr>'
                . $rows . '</table></div>';
        } else {
            $middle = '<div style="text-align:center;margin-top:26px;font-size:11px;color:#64748b;font-weight:bold">'
                . number_format($totalItems) . ' products · ' . count($plan) . ' categories — see the category guide on page 2</div>';
        }

        return '<div class="cover">'
            . '<table class="band"><tr><td style="text-align:center;padding:44px 30px 40px">' . $logoBlock
            . ($tagline ? '<div style="margin-top:14px;font-size:12px;color:#d1fae5;font-weight:bold;letter-spacing:.6px">' . $tagline . '</div>' : '')
            . '</td></tr></table>'
            . '<div style="text-align:center;margin-top:34px"><div class="ctitle">PRODUCT CATALOGUE</div>'
            . '<div style="margin-top:8px"><span class="datechip">' . $date . '</span>' . ($badgeChip !== '' ? ' ' . $badgeChip : '') . '</div>'
            . ($coverTags !== '' ? '<div style="margin-top:10px;font-size:10px;font-weight:bold;color:#64748b;letter-spacing:.5px">' . e($this->clipLine($coverTags, 90)) . '</div>' : '')
            . '</div>'
            . $middle
            . ($qr !== '' ? '<div style="text-align:center;margin-top:26px"><span class="qrbox" style="border:1px solid #e2e8f0"><img src="' . e($qr) . '" style="height:74px;width:74px"></span>'
                . '<div style="font-size:8.5px;font-weight:bold;color:#94a3b8;letter-spacing:1px;margin-top:5px">SCAN TO ORDER ON WHATSAPP</div></div>' : '')
            . '<table class="coverfoot"><tr><td>' . $company
            . ($phone ? ' &nbsp;·&nbsp; ' . e($phone) : '') . ($email ? ' &nbsp;·&nbsp; ' . e($email) : '')
            . ($webText !== '' ? ' &nbsp;·&nbsp; <a href="' . e($webHref) . '" style="color:#0f7a37;text-decoration:none">' . e($webText) . '</a>' : '')
            . '</td></tr></table>'
            . '</div>';
    }

    private function overviewHtml(string $company, string $logo, string $date, array $plan,
        array $thumbs, string $brandD, string $promoImg = ''): string
    {
        // >12 categories: compact two-column text index (photo cards would overflow the page)
        if (count($plan) > 12) {
            $rows = [];
            foreach ($plan as $i => $p) {
                $bg = $i % 2 === 0 ? '#ffffff' : '#f8faf8';
                $rows[] = '<tr><td style="padding:7px 10px;background:' . $bg . ';font-weight:bold;font-size:10.5px">' . e($this->clipLine($p['category'], 26)) . '</td>'
                    . '<td style="padding:7px 4px;background:' . $bg . ';color:#94a3b8;font-size:9px;white-space:nowrap">' . (int) $p['count'] . '</td>'
                    . '<td style="padding:7px 10px;background:' . $bg . ';text-align:right;color:' . $brandD . ';font-weight:bold;font-size:10px;white-space:nowrap">p.' . (int) $p['startPage'] . '</td></tr>';
            }
            $head = '<tr><th style="text-align:left;padding:6px 10px">Category</th><th style="text-align:left;padding:6px 4px">Items</th><th style="text-align:right;padding:6px 10px">Page</th></tr>';
            $half = (int) ceil(count($rows) / 2);
            $index = '<table style="width:100%;border-collapse:separate;border-spacing:12px 0"><tr>'
                . '<td style="width:50%;vertical-align:top"><table class="idx">' . $head . implode('', array_slice($rows, 0, $half)) . '</table></td>'
                . '<td style="width:50%;vertical-align:top"><table class="idx">' . $head . implode('', array_slice($rows, $half)) . '</table></td></tr></table>';

            return '<div class="page">'
                . $this->pageHeader($company, $logo, $date, $brandD)
                . '<div style="margin:14px 0 2px;font-size:19px;font-weight:bold;color:' . $brandD . '">Explore our categories</div>'
                . '<div style="font-size:10px;color:#94a3b8;font-weight:bold;margin-bottom:8px">Everything your business needs, in one place.</div>'
                . $index
                . $this->promoBand($promoImg)
                . '</div>';
        }

        $perRow = count($plan) <= 6 ? 2 : 3;
        $cells = [];
        foreach ($plan as $p) {
            $src = '';
            foreach ($p['items'] as $it) {
                $img = trim((string) ($it['image'] ?? ''));
                if ($img !== '' && ($thumbs[$img] ?? '') !== '') { $src = $thumbs[$img]; break; }
            }
            $photo = $src !== ''
                ? '<table class="ovph"><tr><td><img src="' . e($src) . '"></td></tr></table>'
                : '<table class="ovph"><tr><td><span class="noimg">' . e(mb_strtoupper(mb_substr($p['category'], 0, 14))) . '</span></td></tr></table>';
            $ph = $perRow === 2 ? ' style="height:158px"' : '';
            $cells[] = '<td class="ovcell">' . str_replace('<td>', '<td' . $ph . '>', $photo)
                . '<div class="ovbody"><div class="ovname">' . e($this->clipLine($p['category'], $perRow === 2 ? 34 : 24)) . '</div>'
                . '<table style="width:100%;border-collapse:collapse;margin-top:6px"><tr>'
                . '<td><span class="pillg">' . (int) $p['count'] . ' products</span></td>'
                . '<td style="text-align:right;font-size:10px;font-weight:bold;color:' . $brandD . '">p.' . (int) $p['startPage'] . ' →</td>'
                . '</tr></table></div></td>';
        }
        while (count($cells) % $perRow !== 0) {
            $cells[] = '<td class="ovcell" style="border:none;background:none"></td>';
        }
        $rows = '';
        foreach (array_chunk($cells, $perRow) as $row) {
            $rows .= '<tr>' . implode('', $row) . '</tr>';
        }

        $feat = '<table class="feat"><tr>'
            . '<td style="width:25%">✓ Wholesale prices</td><td style="width:25%">✓ Genuine brands</td>'
            . '<td style="width:25%">✓ Bulk discounts</td><td style="width:25%">✓ Delivery on request</td>'
            . '</tr></table>';

        return '<div class="page">'
            . $this->pageHeader($company, $logo, $date, $brandD)
            . '<div style="margin:14px 0 2px;font-size:19px;font-weight:bold;color:' . $brandD . '">Explore our categories</div>'
            . '<div style="font-size:10px;color:#94a3b8;font-weight:bold;margin-bottom:6px">Everything your business needs, in one place.</div>'
            . '<table class="ovg">' . $rows . '</table>'
            . $feat
            . $this->promoBand($promoImg)
            . '</div>';
    }

    /** The "Need a product you don't see?" green band (promo photo optional). */
    private function promoBand(string $promoImg): string
    {
        return '<table class="promo"><tr>'
            . '<td><div style="font-size:16px;font-weight:bold;color:#ffffff">Need a product you don\'t see?</div>'
            . '<div style="font-size:10.5px;color:#d1fae5;margin-top:5px;font-weight:bold">Chat with us on WhatsApp — we source it for you.</div></td>'
            . ($promoImg !== '' ? '<td style="width:170px;text-align:right" class="promoimg"><img src="' . e($promoImg) . '"></td>' : '')
            . '</tr></table>';
    }

    /**
     * Full intro page for a big category (≥ INTRO_MIN items): headline band, hero photo,
     * top-picks card row, feature strip. Mirrors the marketing-mock structure while staying
     * inside the dompdf page budget.
     */
    private function categoryIntro(array $p, array $thumbs, array $badges, string $currency,
        bool $withPrices, string $company, string $logo, string $date, string $phone,
        string $dateFull): string
    {
        $brandD = self::BRAND_DARK;

        // hero photo: first badged item with a photo, else first item with a photo
        $heroSrc = '';
        foreach ($p['items'] as $it) {
            $img = trim((string) ($it['image'] ?? ''));
            $uri = $img !== '' ? ($thumbs[$img] ?? '') : '';
            if ($uri !== '' && $this->badgeFor($it, $badges)) { $heroSrc = $uri; break; }
            if ($uri !== '' && $heroSrc === '') $heroSrc = $uri;
        }

        // top picks: badged items first, then fill from the front (3 cards)
        $picks = [];
        foreach ($p['items'] as $it) {
            if ($this->badgeFor($it, $badges)) $picks[] = $it;
        }
        foreach ($p['items'] as $it) {
            if (count($picks) >= 3) break;
            if (! in_array($it, $picks, true)) $picks[] = $it;
        }
        $picks = array_slice($picks, 0, 3);

        $hero = '<table class="ihero"><tr>'
            . '<td style="vertical-align:middle;padding:22px 24px">'
            . '<div style="font-size:26px;font-weight:bold;letter-spacing:-.4px">' . e($p['category']) . '</div>'
            . '<div style="margin-top:9px"><span class="pillg">' . (int) $p['count'] . ' products</span>'
            . '&nbsp;&nbsp;<span style="font-size:9px;color:#64748b;font-weight:bold">pages ' . (int) $p['startPage'] . '–' . ((int) $p['startPage'] + count($p['chunks'])) . '</span></div>'
            . '<div style="margin-top:14px;font-size:10.5px;color:#475569;font-weight:bold;line-height:1.6">'
            . 'Wholesale prices on every pack size ·<br>stock confirmed on order.</div>'
            . '</td>'
            . ($heroSrc !== '' ? '<td style="width:280px;text-align:center;vertical-align:middle;padding:14px"><div style="background:#ffffff;border:1px solid #dcfce7;border-radius:12px;padding:10px"><img src="' . e($heroSrc) . '" style="max-height:218px;max-width:100%"></div></td>' : '')
            . '</tr></table>';

        $pickRow = $picks
            ? '<div style="margin:14px 0 2px;font-size:9px;font-weight:bold;letter-spacing:2px;color:' . $brandD . '">★ TOP SELLERS IN ' . e(mb_strtoupper($p['category'])) . '</div>'
                . $this->grid($picks, $currency, $withPrices, $thumbs, $badges)
            : '';

        $feat = '<table class="feat"><tr>'
            . '<td style="width:25%">✓ Great prices</td><td style="width:25%">✓ Quality assured</td>'
            . '<td style="width:25%">✓ Fast-moving lines</td><td style="width:25%">✓ Reliable supply</td>'
            . '</tr></table>';

        return '<div class="page">'
            . $this->pageHeader($company, $logo, $date, self::BRAND_DARK)
            . $hero
            . $pickRow
            . $feat
            . $this->pageFooter($phone, $currency, $dateFull, $withPrices, '', '')
            . '</div>';
    }

    /** First page of a category: light hero band — name, count, top-seller strip, hero photo. */
    private function categoryHero(array $p, array $thumbs, array $badges): string
    {
        $heroSrc = '';
        $tops = [];
        foreach ($p['items'] as $it) {
            $img = trim((string) ($it['image'] ?? ''));
            $uri = $img !== '' ? ($thumbs[$img] ?? '') : '';
            if ($uri === '') {
                continue;
            }
            if ($this->badgeFor($it, $badges)) {
                if ($heroSrc === '') $heroSrc = $uri;
                if (count($tops) < 3) $tops[] = $uri;
            }
            if ($heroSrc === '') $heroSrc = $uri;
        }

        $strip = '';
        if ($tops) {
            $tds = '';
            foreach ($tops as $t) $tds .= '<td><img src="' . e($t) . '"></td>';
            $strip = '<div class="ts" style="margin-top:10px">★ TOP SELLERS</div><table class="tsrow"><tr>' . $tds . '</tr></table>';
        }
        $total = count($p['chunks']);
        $cont = $total > 1 ? ' <span style="font-weight:normal;color:#94a3b8;font-size:10px">1 / ' . $total . '</span>' : '';

        return '<table class="hero"><tr>'
            . '<td><div class="heroname">' . e($p['category']) . $cont . '</div>'
            . '<div style="margin-top:7px"><span class="pillg">' . (int) $p['count'] . ' products</span></div>'
            . $strip . '</td>'
            . ($heroSrc !== '' ? '<td style="width:175px;text-align:right" class="heroimg"><img src="' . e($heroSrc) . '"></td>' : '')
            . '</tr></table>';
    }

    private function backHtml(string $company, string $logo, string $date, string $phone,
        string $email, string $currency, string $dateFull, bool $withPrices, string $promoImg,
        string $qr, bool $withPromo = true, string $webText = '', string $webHref = '',
        string $deliveryLine = '', array $infoRows = [], string $badgeTxt = ''): string
    {
        $ink = self::INK; $brandD = self::BRAND_DARK;
        $promo = $withPromo ? $this->promoBand($promoImg) : '';

        $steps = [
            ['1', 'Send your order on WhatsApp', $phone !== '' ? e($phone) : 'our WhatsApp line'],
            ['2', 'We confirm stock &amp; total', 'You get an order confirmation with the amount'],
            ['3', 'Pick up or delivery', $deliveryLine !== '' ? e($this->clipLine($deliveryLine, 110)) : 'Collect at the store or ask about delivery'],
        ];
        $stepHtml = '';
        foreach ($steps as [$n, $t, $s]) {
            $stepHtml .= '<table class="step"><tr>'
                . '<td style="width:34px"><div class="stepn">' . $n . '</div></td>'
                . '<td><div style="font-size:13px;font-weight:bold;color:' . $ink . '">' . $t . '</div>'
                . '<div style="font-size:10.5px;color:#64748b;margin-top:2px">' . $s . '</div></td>'
                . '</tr></table>';
        }

        return '<div class="backpage">'
            . $this->pageHeader($company, $logo, $date, $brandD)
            . '<table class="catband"><tr><td>How to order</td></tr></table>'
            . $promo
            . '<table style="width:100%;border-collapse:collapse"><tr><td style="width:55%;vertical-align:top;padding-right:16px">' . $stepHtml . '</td>'
            . '<td style="vertical-align:top">'
            . '<div class="ordercard">'
            . '<table style="width:100%;border-collapse:collapse"><tr><td>'
            . '<div style="font-size:10px;font-weight:bold;letter-spacing:1.5px;color:#bbf7d0">ORDER ON WHATSAPP</div>'
            . '<div style="font-size:19px;font-weight:bold;color:#ffffff;margin-top:6px">' . ($phone !== '' ? e($phone) : $company) . '</div>'
            . ($email !== '' ? '<div style="font-size:10.5px;color:#d1fae5;margin-top:8px">' . e($email) . '</div>' : '')
            . ($webText !== '' ? '<div style="font-size:11px;margin-top:9px;padding-top:9px;border-top:1px solid #ffffff33">'
                . '<span style="font-size:8px;font-weight:bold;letter-spacing:1.5px;color:#bbf7d0">OR ORDER ONLINE</span><br>'
                . '<a href="' . e($webHref) . '" style="color:#ffffff;font-weight:bold;text-decoration:none;font-size:13px">' . e($webText) . '</a></div>' : '')
            . '</td>'
            . ($qr !== '' ? '<td style="width:74px;text-align:right;vertical-align:top"><span class="qrbox"><img src="' . e($qr) . '" style="height:62px;width:62px"></span></td>' : '')
            . '</tr></table>'
            . '</div>'
            . '</td></tr></table>'
            . $this->backInfo($infoRows, $badgeTxt)
            . '<div style="margin-top:22px;font-size:9px;color:#94a3b8">'
            . ($withPrices ? 'All prices in ' . e($currency) . ', valid as of ' . e($dateFull) . ' and subject to change without notice. ' : '')
            . 'Availability confirmed on order. Thank you for your business.</div>'
            . '</div>';
    }

    /**
     * v4.7.7 — compact business-details block on the back page (hours, delivery areas, payment,
     * email, location) + a standing dealer-enquiry line. Rows come pre-filtered (only settings the
     * shop actually filled); with none, only the dealer line prints. Two columns, table flow only.
     */
    private function backInfo(array $infoRows, string $badgeTxt): string
    {
        $ink = self::INK; $brandD = self::BRAND_DARK;
        $cells = [];
        foreach ($infoRows as [$label, $value]) {
            $cells[] = '<td style="width:50%;padding:7px 10px;vertical-align:top">'
                . '<div style="font-size:8px;font-weight:bold;letter-spacing:1.2px;color:#94a3b8">' . strtoupper(e($label)) . '</div>'
                . '<div style="font-size:10.5px;font-weight:bold;color:' . $ink . ';margin-top:2px">' . e($this->clipLine($value, 70)) . '</div>'
                . '</td>';
        }
        $rows = '';
        foreach (array_chunk($cells, 2) as $pair) {
            if (count($pair) === 1) $pair[] = '<td style="width:50%"></td>';
            $rows .= '<tr>' . implode('', $pair) . '</tr>';
        }
        $table = $rows !== ''
            ? '<table style="width:100%;border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-top:16px">' . $rows . '</table>'
            : '';
        $dealer = '<div style="margin-top:12px;font-size:11px;font-weight:bold;color:' . $brandD . '">'
            . '🤝 Dealer &amp; bulk enquiries welcome — ask for the trade price list.'
            . ($badgeTxt !== '' ? ' &nbsp;·&nbsp; ' . e($this->clipLine($badgeTxt, 46)) : '')
            . '</div>';

        return $table . $dealer;
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
    private function grid(array $items, string $currency, bool $withPrices, array $thumbs, array $badges = []): string
    {
        $cells = [];
        foreach ($items as $it) {
            $img = trim((string) ($it['image'] ?? ''));
            $src = $img !== '' ? ($thumbs === [] ? $img : ($thumbs[$img] ?? '')) : '';
            $photo = $src !== ''
                ? '<table class="phw"><tr><td><img src="' . e($src) . '"></td></tr></table>'
                : '<table class="phw"><tr><td><span class="noimg">PHOTO<br>COMING SOON</span></td></tr></table>';

            $b = $this->badgeFor($it, $badges);
            $bstrip = '<div class="bstrip">'
                . ($b ? '<span class="badge" style="background:' . $b['bg'] . '">' . $b['label'] . '</span>' : '&nbsp;')
                . '</div>';

            $unit = trim((string) ($it['unit'] ?? ''));
            // v4.7.7 — the two info lines are multi-purpose so card height never changes:
            //   line 1: OEM part number when known (mechanics order by this), else the selling unit.
            //   line 2: MOQ when set (FMCG tenants), else a brand "Fits" line (parts tenants), else blank.
            $oem = trim((string) ($it['oem'] ?? ''));
            $pbrand = trim((string) ($it['brand'] ?? ''));
            $moq = ! empty($it['moq'])
                ? 'Min order: ' . e((string) $it['moq']) . ' &times; ' . e($unit !== '' ? $unit : 'units')
                : ($pbrand !== '' ? 'Fits: <b>' . e($this->clipLine($pbrand, 24)) . '</b> models' : '&nbsp;');
            $priceCell = $withPrices
                ? '<div class="pfrom">FROM</div><div class="pr">' . $this->money((float) $it['price'], $currency) . '</div>'
                : '<div class="pr" style="color:#94a3b8;font-size:10px">Ask for price</div>';

            $cells[] = '<td class="cell">' . $bstrip . $photo
                . '<div class="pbody">'
                . $this->nameLines((string) $it['name'])
                . '<div class="pu">' . ($oem !== '' ? '<b style="color:#334155">OEM: ' . e($this->clipLine($oem, 26)) . '</b>' : ($unit !== '' ? e($unit) : '&nbsp;')) . '</div>'
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

    private function clipLine(string $s, int $max): string
    {
        $s = trim($s);

        return mb_strlen($s, 'UTF-8') > $max ? rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…' : $s;
    }

    private function pageFooter(string $phone, string $currency, string $dateFull, bool $withPrices,
        string $webText = '', string $webHref = ''): string
    {
        $left = $withPrices
            ? 'Prices in ' . e($currency) . ' · valid as of ' . e($dateFull) . ' · subject to change'
            : 'Product catalogue · ' . e($dateFull);
        $parts = [];
        if ($phone !== '') {
            $parts[] = 'Order on WhatsApp: ' . e($phone);
        }
        if ($webText !== '') {
            $parts[] = '<a href="' . e($webHref) . '" style="color:#0f7a37;text-decoration:none">' . e($webText) . '</a>';
        }

        return '<table class="pfoot"><tr><td>' . $left . '</td><td class="wa">' . implode(' &nbsp;·&nbsp; ', $parts) . '</td></tr></table>';
    }
}
