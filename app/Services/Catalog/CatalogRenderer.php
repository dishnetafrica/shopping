<?php

namespace App\Services\Catalog;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Branded PRODUCT CATALOGUE PDF — a photo-first document the business sends on WhatsApp.
 *
 * v2.0 "design system" release. Every page is composed from ONE set of tokens and reusable
 * components — pages are never styled independently:
 *
 *   tokens       C_* colour constants (dark green / white / black / light grey + WhatsApp
 *                green for order elements + red STRICTLY for promotions) and a 3-weight
 *                Inter type scale (400 body / 600 medium / 800 display) registered from
 *                public/fonts/inter/*.ttf — automatic DejaVu fallback when unavailable.
 *   components   pageHeader, pageFooter (WhatsApp + QR + validity on every page), catBand,
 *                card (product), chip/badge/stockChip, catChip (category monogram),
 *                promoBand, bikeStrip (AUTO_PARTS only), infoGrid.
 *   pages        cover (uploaded portrait art still becomes the ENTIRE page untouched;
 *                the generated cover is a dark-green plate with hero photo + QR bar),
 *                index, category intro / slim hero, 3×3 product pages, OEM cross-reference
 *                (auto-appears when ≥6 parts carry OEM numbers), how-to-order guide,
 *                closing contact page.
 *
 * Inherited and unchanged: the 3-stage failure ladder (rich → thumbs-only → plain), strict
 * page budgets, parallel photo pre-fetch normalised onto identical 4:3 white canvases
 * (disk-cached, data-URI embedded), deterministic two-line names, font subsetting,
 * "Page N of T" numbering. dompdf has no flex/grid engine, so everything is tables + block
 * flow. Renders only; reads product data and prices as-is. Degrades gracefully without GD.
 */
final class CatalogRenderer
{
    private const PER_PAGE = 9;           // product grid pages: 3 × 3
    private const INTRO_MIN = 7;          // categories this big open with a full intro page
                                          // (slim-band pages hold ≤6 cards — band + 3 card rows overflows A4)

    // ── design tokens: colour (the ONLY colours any component may use) ──
    private const C_GREEN_DEEP = '#0B3D20';   // cover / contact plates, order card
    private const C_GREEN = '#14532D';        // bands, titles, prices
    private const C_GREEN_MID = '#175C36';    // chips on deep green
    private const C_WA = '#25D366';           // WhatsApp green — order elements only
    private const C_TINT = '#EAF5EE';         // light green surface
    private const C_TINT_LINE = '#CFE6D8';
    private const C_RED = '#DC2626';          // promotions ONLY (HOT DEAL)
    private const C_INK = '#111827';
    private const C_GREY = '#6B7280';
    private const C_FAINT = '#9CA3AF';
    private const C_LINE = '#E5E7EB';
    private const C_WELL = '#F7F8F8';         // image wells / soft cards
    private const C_BLUE = '#1D4ED8';         // OEM chip
    private const C_BLUE_BG = '#EFF6FF';
    private const C_BLUE_LINE = '#BFDBFE';

    // legacy aliases (external callers / old settings docs reference these names)
    private const BRAND = self::C_GREEN;
    private const BRAND_DARK = self::C_GREEN_DEEP;
    private const INK = self::C_INK;

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

        // The Dompdf instance is created BEFORE the HTML so the Inter registration outcome can
        // steer the CSS font stacks (automatic DejaVu fallback when the TTFs are missing).
        try {
            $dompdf = $this->makeDompdf();
        } catch (\Throwable $e) {
            \Log::warning('CatalogRenderer: dompdf init failed: ' . $e->getMessage());

            return null;
        }
        $fonts = $this->registerFonts($dompdf);

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
            $html = $this->html($tenant, $groups, $currency, $withPrices, $thumbs, false, $fonts);
        } catch (\Throwable $e) {
            \Log::warning('CatalogRenderer rich layout failed: ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ' — retrying without artwork/QR/badges');
        }
        if ($html === null) {
            try {
                $html = $this->html($tenant, $groups, $currency, $withPrices, $thumbs, true, $fonts);
            } catch (\Throwable $e) {
                \Log::warning('CatalogRenderer thumbs-only layout failed: ' . $e->getMessage()
                    . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ' — retrying plain');
            }
        }

        try {
            if ($html === null) {
                $html = $this->html($tenant, $groups, $currency, $withPrices, [], true, $fonts);
            }
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();

            // "Page N of T" on every page except the cover.
            try {
                $canvas = $dompdf->getCanvas();
                $font = $dompdf->getFontMetrics()->getFont($fonts ? 'inter' : 'DejaVu Sans', 'normal');
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

    /** Dompdf configured for the design system: subsetting on, writable font dir for Inter. */
    private function makeDompdf(): \Dompdf\Dompdf
    {
        $opts = [
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            // Embed only the glyphs actually used instead of whole font files — shaves a few
            // hundred KB off every document for free.
            'isFontSubsettingEnabled' => true,
        ];
        try {
            $fontDir = storage_path('fonts/dompdf');
            if (! is_dir($fontDir)) {
                @mkdir($fontDir, 0775, true);
            }
            if (is_dir($fontDir) && is_writable($fontDir)) {
                $opts['fontDir'] = $fontDir;
                $opts['fontCache'] = $fontDir;
                $opts['chroot'] = [public_path('fonts')];
            }
        } catch (\Throwable $e) {
            // no writable font dir → dompdf defaults (DejaVu only), still fully functional
        }

        return new \Dompdf\Dompdf($opts);
    }

    /**
     * Register the Inter family (400 body / 600 medium / 800 display — the design system's
     * three weights) from public/fonts/inter. Returns false when anything is missing or
     * unwritable, and every stylesheet silently falls back to DejaVu Sans.
     */
    private function registerFonts(\Dompdf\Dompdf $dompdf): bool
    {
        try {
            $dir = public_path('fonts/inter');
            $files = [
                ['inter', 'normal', $dir . '/Inter-400.ttf'],
                ['inter', 'bold', $dir . '/Inter-800.ttf'],
                ['intermd', 'normal', $dir . '/Inter-600.ttf'],
                ['intermd', 'bold', $dir . '/Inter-800.ttf'],
            ];
            foreach ($files as [, , $f]) {
                if (! is_file($f)) {
                    return false;
                }
            }
            $fm = $dompdf->getFontMetrics();
            foreach ($files as [$family, $weight, $f]) {
                if (! $fm->registerFont(['family' => $family, 'style' => 'normal', 'weight' => $weight], 'file://' . $f)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            \Log::warning('CatalogRenderer: Inter registration failed (' . $e->getMessage() . ') — using DejaVu');

            return false;
        }
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

            // v4.54 — this catalogue goes straight to customers on WhatsApp. A hidden line printed
            // here is the same leak as one on the website, only harder to notice.
            $products = \App\Services\Catalog\Visibility::scope(\App\Models\Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('active', true)->where('price', '>', 0), $tenant)
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
                    'pack' => (int) ($p->pack_size ?? 0),          // per-piece (loose) pricing on the card
                    'sku' => (string) ($p->sku ?: ''),
                    'oem' => (string) ($p->oem_number ?? ''),      // v4.7.7 — manufacturer part no. (mechanics order by this)
                    'brand' => (string) ($p->brand ?? ''),         // v4.7.7 — powers the per-card "Fits" line
                    'image' => (string) ($p->image_url ?: ''),
                    'stock' => $p->stock === null ? null : (int) $p->stock,   // v2.0 — stock chip (same rule as the storefront)
                    'id' => (int) $p->id,                                     // v2.1 — per-product QR → /part/{id}
                    'keywords' => (string) ($p->keywords ?? ''),              // v2.1 — model fitment matching
                    'pop' => (int) ($p->popularity ?? 0),                     // v2.1 — auto POPULAR badge
                    'new' => (bool) ($p->created_at && $p->created_at->getTimestamp() > time() - 45 * 86400),   // v2.1 — NEW badge
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

            // v4.54 — asking for a hidden category by name must produce nothing, not a PDF of it.
            $products = \App\Services\Catalog\Visibility::scope(\App\Models\Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('active', true)->where('price', '>', 0), $tenant)
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
                    'pack' => (int) ($p->pack_size ?? 0),          // per-piece (loose) pricing on the card
                    'sku' => (string) ($p->sku ?: ''),
                    'oem' => (string) ($p->oem_number ?? ''),      // v4.7.7
                    'brand' => (string) ($p->brand ?? ''),         // v4.7.7
                    'image' => (string) ($p->image_url ?: ''),
                    'stock' => $p->stock === null ? null : (int) $p->stock,   // v2.0
                    'id' => (int) $p->id,                                     // v2.1
                    'keywords' => (string) ($p->keywords ?? ''),              // v2.1
                    'pop' => (int) ($p->popularity ?? 0),                     // v2.1
                    'new' => (bool) ($p->created_at && $p->created_at->getTimestamp() > time() - 45 * 86400),   // v2.1
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

    /** [big, small] halves of a price for the card price row ("12,500" + "UGX"). */
    private function moneyParts(float $n, string $cur): array
    {
        if ($cur === 'USD') {
            return ['$' . number_format($n, 2), ''];
        }

        return [number_format($n, 0), $cur];
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

    // ─────────────────────────── AUTO_PARTS extras ───────────────────────────

    /** @var array{tid:int,data:array|false}|null memo: parsed SPA data (false = unavailable) */
    private ?array $spaMemo = null;

    /**
     * IMG map + MODELS list from the built storefront SPA — one parse per render, null
     * for every other vertical or on ANY failure — grocery catalogues are untouched.
     * @return array{img:array<string,string>,models:array<int,array<string,mixed>>}|null
     */
    private function spaData(Tenant $tenant): ?array
    {
        if ($this->spaMemo !== null && $this->spaMemo['tid'] === (int) $tenant->id) {
            return $this->spaMemo['data'] ?: null;
        }
        $this->spaMemo = ['tid' => (int) $tenant->id, 'data' => false];
        try {
            if (! class_exists(\App\Support\Vertical::class)
                || \App\Support\Vertical::of($tenant) !== \App\Support\Vertical::AUTO_PARTS) {
                return null;
            }
            $spa = resource_path('storefront/autoparts.html');
            if (! is_file($spa)) {
                return null;
            }
            $s = (string) file_get_contents($spa);
            if (! preg_match('/var IMG = (\{.*?\});/s', $s, $mi) || ! preg_match('/var MODELS = (\[.*?\]);\s*\n/s', $s, $mm)) {
                return null;
            }
            $img = json_decode($mi[1], true);
            $models = json_decode($mm[1], true);
            if (! is_array($img) || ! is_array($models)) {
                return null;
            }
            $this->spaMemo['data'] = ['img' => $img, 'models' => $models];

            return $this->spaMemo['data'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * v4.15 — the owner's uploaded photo for a model ('' when not set). Same settings the
     * storefront reads (model_image_{id}); a site-relative "/storage/…" value is resolved
     * to the local file so the photo pipeline never needs HTTP for it.
     */
    private function modelOverride(Tenant $tenant, string $id): string
    {
        $u = trim((string) $tenant->setting('model_image_' . $id, ''));
        if ($u === '') {
            return '';
        }
        if (preg_match('~^/storage/(.+)$~', $u, $m)) {
            try {
                $p = public_path('storage/' . urldecode($m[1]));
                if (is_file($p)) {
                    return $p;
                }
            } catch (\Throwable $e) {
            }
        }

        return $u;
    }

    /**
     * Real motorcycle photos for parts tenants: the owner's PANEL UPLOAD for a model wins
     * (Settings → Storefront → "<model> photo"), else the built-in repo photo
     * (public/assets/sfm, referenced by the SPA's IMG/MODELS data). Up to $max entries
     * [name, dataUri], deduped by source.
     * @return array<int,array{0:string,1:string}>
     */
    private function bikePhotos(Tenant $tenant, int $max = 4): array
    {
        try {
            $data = $this->spaData($tenant);
            if (! $data) {
                return [];
            }
            $out = [];
            $seen = [];
            foreach ($data['models'] as $m) {
                if (count($out) >= $max) {
                    break;
                }
                $id = (string) ($m['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $src = $this->modelOverride($tenant, $id);   // uploaded photo wins
                if ($src === '') {
                    $rel = (string) ($data['img'][$id] ?? '');
                    if ($rel === '' || ! str_starts_with($rel, '/assets/')) {
                        continue;
                    }
                    $file = public_path(ltrim($rel, '/'));
                    if (! is_file($file)) {
                        continue;
                    }
                    $src = $file;
                }
                if (isset($seen[$src])) {
                    continue;
                }
                $uri = $this->asset($tenant, $src, 360);
                if ($uri === '') {
                    continue;
                }
                $seen[$src] = true;
                $out[] = [(string) ($m['name'] ?? $id), $uri];
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The hero motorcycle shot for the generated cover: panel upload wins, else built-in. */
    private function bikeHero(Tenant $tenant): string
    {
        try {
            $data = $this->spaData($tenant);
            if (! $data) {
                return '';
            }
            $ov = $this->modelOverride($tenant, 'hero');
            if ($ov !== '') {
                $uri = $this->asset($tenant, $ov, 620);
                if ($uri !== '') {
                    return $uri;
                }
            }
            $rel = (string) ($data['img']['hero'] ?? '');
            if ($rel === '' || ! str_starts_with($rel, '/assets/')) {
                return '';
            }
            $file = public_path(ltrim($rel, '/'));

            return is_file($file) ? $this->asset($tenant, $file, 620) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The storefront's model list as [[name, lowercase match tokens], …] for per-card
     * fitment lines. Tokens are the alpha words of the model name/id (≥3 chars).
     * @return array<int,array{0:string,1:array<int,string>}>
     */
    private function fitModels(Tenant $tenant): array
    {
        try {
            $data = $this->spaData($tenant);
            if (! $data) {
                return [];
            }
            // Maker names alone ("bajaj", "tvs") appear in nearly every item's keywords and
            // would match every model of that maker — match only on DISTINCTIVE tokens
            // (model family words like "boxer"/"hlx"/"v15"), so a V15 part never claims to
            // fit every Bajaj, and pure-maker items fall back to the brand line.
            $makers = ['bajaj' => true, 'tvs' => true, 'honda' => true, 'yamaha' => true, 'suzuki' => true, 'ct' => true];
            $out = [];
            foreach ($data['models'] as $m) {
                $name = trim((string) ($m['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $toks = [];
                foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($name . ' ' . (string) ($m['id'] ?? ''), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
                    if (strlen($t) >= 3 && ! ctype_digit($t) && ! isset($makers[$t])) {
                        $toks[$t] = true;
                    }
                }
                if ($toks) {
                    $out[] = [$name, array_keys($toks)];
                }
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * "✓ Fits Boxer 100 KS · 125 HD · 150" — specific models matched from the item's
     * brand+keywords against the storefront model list. '' when nothing matches
     * (caller falls back to the brand line).
     */
    private function fitsLineFor(array $item, array $fitModels): string
    {
        if (! $fitModels) {
            return '';
        }
        $hay = mb_strtolower((string) ($item['brand'] ?? '') . ' ' . (string) ($item['keywords'] ?? ''), 'UTF-8');
        if (trim($hay) === '') {
            return '';
        }
        $names = [];
        foreach ($fitModels as [$name, $toks]) {
            foreach ($toks as $t) {
                if (str_contains($hay, $t)) {
                    $names[] = $name;
                    break;
                }
            }
        }
        if (! $names) {
            return '';
        }
        // Compress a shared family word: Boxer 100 KS + Boxer 125 HD → "Boxer 100 KS · 125 HD"
        $first = explode(' ', $names[0])[0];
        $sameFamily = count(array_filter($names, fn ($n) => str_starts_with($n, $first . ' ') || $n === $first)) === count($names);
        $shown = array_slice($names, 0, 3);
        if ($sameFamily && count($names) > 1) {
            $rest = array_map(fn ($n) => trim(substr($n, strlen($first))) ?: $n, $shown);
            $line = $first . ' ' . implode(' · ', $rest);
        } else {
            $line = implode(' · ', $shown);
        }
        if (count($names) > 3) {
            $line .= ' +' . (count($names) - 3);
        }

        return '✓ Fits ' . $this->clipLine($line, 34);
    }

    /**
     * Split a product name into [display name, spec line]: trailing parentheticals become
     * the spec ("Drive Chain (428H-112L)" → ["Drive Chain", "428H-112L"]). Parentheticals
     * that are just fitment words (Boxer/TVS/…) are dropped — the fits line covers them.
     */
    private function nameSpec(string $name, array $fitModels): array
    {
        if (! preg_match_all('/\(([^()]{1,40})\)/', $name, $mm)) {
            return [trim($name), ''];
        }
        $fitWords = ['bajaj' => true, 'boxer' => true, 'tvs' => true];
        foreach ($fitModels as [, $toks]) {
            foreach ($toks as $t) {
                $fitWords[$t] = true;
            }
        }
        $specs = [];
        $strip = [];
        foreach ($mm[1] as $i => $inner) {
            $words = preg_split('/[^a-z0-9]+/', mb_strtolower($inner, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $alpha = array_filter($words, fn ($w) => ! ctype_digit($w));
            $isFit = $alpha !== [] && count(array_filter($alpha, fn ($w) => isset($fitWords[$w]))) === count($alpha);
            if ($isFit) {
                $strip[] = $mm[0][$i];          // pure fitment marker → fits line covers it
            } else {
                $specs[] = trim($inner);
                $strip[] = $mm[0][$i];
            }
        }
        $base = trim(preg_replace('/\s{2,}/', ' ', str_replace($strip, '', $name)));
        if (mb_strlen($base, 'UTF-8') < 3) {
            return [trim($name), ''];           // stripping everything left nothing usable
        }

        return [$base, implode(' · ', array_slice($specs, 0, 2))];
    }

    /**
     * Batch-generate per-product QR codes (parallel fetch, same disk cache as asset()).
     * Parts tenants with a website get the REAL product URL (/part/{id} — the v4.11 SEO
     * pages); otherwise a prefilled wa.me link. [] in plain mode or with no phone+website.
     * @return array<string,string> item key (id|sku|name) => dataUri
     */
    private function qrBatch(Tenant $tenant, array $groups, bool $isParts, string $webHref, string $phone): array
    {
        try {
            $digits = preg_replace('/\D+/', '', $phone);
            $base = ($isParts && $webHref !== '') ? rtrim($webHref, '/') : '';
            if ($base === '' && $digits === '') {
                return [];
            }

            $urls = [];   // itemKey => quickchart url
            foreach ($groups as $g) {
                foreach ($g['items'] as $it) {
                    $key = (string) ($it['id'] ?? '') !== '' ? 'i' . $it['id'] : (string) ($it['sku'] ?? $it['name'] ?? '');
                    if ($key === '' || isset($urls[$key])) {
                        continue;
                    }
                    if ($base !== '' && ! empty($it['id'])) {
                        $target = $base . '/part/' . (int) $it['id'];
                    } elseif ($digits !== '') {
                        $ref = trim((string) ($it['sku'] ?? '')) ?: $this->clipLine((string) ($it['name'] ?? ''), 40);
                        $target = 'https://wa.me/' . $digits . '?text=' . rawurlencode('Order ' . $ref);
                    } else {
                        continue;
                    }
                    $urls[$key] = 'https://quickchart.io/qr?text=' . urlencode($target) . '&size=120&margin=1';
                }
            }
            if (! $urls) {
                return [];
            }

            // disk-cache pass (same key scheme as asset() so caches interoperate)
            $out = [];
            $missing = [];   // fetch url => [key, cache path]
            foreach ($urls as $key => $qurl) {
                $cache = 'catalog-thumbs/' . $tenant->id . '/asset-' . md5($qurl . ':raw') . '.png';
                $hit = null;
                try {
                    if (Storage::disk('public')->exists($cache)) {
                        $bytes = Storage::disk('public')->get($cache);
                        if ($bytes) {
                            $hit = 'data:image/png;base64,' . base64_encode($bytes);
                        }
                    }
                } catch (\Throwable $e) {
                }
                if ($hit !== null) {
                    $out[$key] = $hit;
                } else {
                    $missing[$qurl] = [$key, $cache];
                }
            }
            if ($missing) {
                foreach ($this->fetchMany(array_keys($missing)) as $qurl => $raw) {
                    [$key, $cache] = $missing[$qurl];
                    if ($raw === null || $raw === '') {
                        continue;   // unreachable → card simply has no QR
                    }
                    try { Storage::disk('public')->put($cache, $raw); } catch (\Throwable $e) { }
                    $out[$key] = 'data:image/png;base64,' . base64_encode($raw);
                }
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────── document ───────────────────────────

    /** @param array<int,array<string,mixed>> $groups */
    private function html(Tenant $tenant, array $groups, string $currency, bool $withPrices, array $thumbs, bool $plain = false, bool $fonts = false): string
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
        $version = 'v' . now()->format('Y.m');

        try {
            $isParts = class_exists(\App\Support\Vertical::class)
                && \App\Support\Vertical::of($tenant) === \App\Support\Vertical::AUTO_PARTS;
        } catch (\Throwable $e) {
            $isParts = false;
        }

        $badges = $plain ? [] : $this->badgeMap($tenant);
        [$coverImg, $coverMode] = $plain ? ['', 'hero'] : $this->coverAsset($tenant, (string) $tenant->setting('catalog_cover_image', ''));
        $promoImg = $plain ? '' : $this->asset($tenant, (string) $tenant->setting('catalog_promo_image', ''), 800);
        $qr = $plain ? '' : $this->qr($tenant, $phone);
        $bikes = $plain ? [] : $this->bikePhotos($tenant);
        $bikeHero = $plain ? '' : $this->bikeHero($tenant);
        $totalItems = 0;
        foreach ($groups as $g) $totalItems += count($g['items']);

        // v2.1 card context: per-product QRs, model fitment, auto badges
        $fits = $plain ? [] : $this->fitModels($tenant);
        $qrMap = $plain ? [] : $this->qrBatch($tenant, $groups, $isParts, $webHref, $phone);
        $popIds = [];
        if (! $plain) {
            $pops = [];
            foreach ($groups as $g) {
                foreach ($g['items'] as $it) {
                    if ((int) ($it['pop'] ?? 0) >= 3 && ! empty($it['id'])) {
                        $pops[(int) $it['id']] = (int) $it['pop'];
                    }
                }
            }
            arsort($pops);
            $popIds = array_slice(array_keys($pops), 0, min(8, (int) ceil($totalItems * 0.1)), true);
        }
        $ctx = ['fits' => $fits, 'qr' => $qrMap, 'pop' => array_flip($popIds),
            // Per-piece (loose) pricing on the card: same gate/formula as the bot quote & storefront.
            // For a parts shop the piece price leads (most buyers want one piece); other verticals keep
            // the carton price as the headline with a per-piece hint underneath.
            'loose' => (bool) $tenant->setting('loose_enabled', false),
            'loosePct' => (float) $tenant->setting('loose_markup_pct', 20),
            'parts' => $isParts];

        // --- pagination plan: cover(1) [index(2)] categories… [cross-ref] guide, contact ---
        $showIndex = count($groups) >= 2;
        $plan = [];
        $page = $showIndex ? 3 : 2;
        foreach ($groups as $g) {
            $items = $g['items'];
            $chunks = $items ? array_chunk($items, self::PER_PAGE) : [[]];
            $intro = count($items) >= self::INTRO_MIN;
            $plan[] = ['category' => $g['category'], 'items' => $items, 'chunks' => $chunks,
                'intro' => $intro, 'startPage' => $page, 'count' => count($items)];
            $page += count($chunks) + ($intro ? 1 : 0);
        }

        // OEM cross-reference (auto: only when enough parts carry manufacturer numbers)
        $refs = [];
        foreach ($plan as $p) {
            $pg = $p['startPage'] + ($p['intro'] ? 1 : 0);
            foreach ($p['chunks'] as $ci => $chunk) {
                foreach ($chunk as $it) {
                    $o = trim((string) ($it['oem'] ?? ''));
                    if ($o !== '') {
                        $refs[] = ['oem' => $o, 'name' => (string) $it['name'], 'sku' => (string) ($it['sku'] ?? ''), 'page' => $pg + $ci];
                    }
                }
            }
        }
        usort($refs, fn ($a, $b) => strnatcasecmp($a['oem'], $b['oem']));
        $crossPages = count($refs) >= 6 ? array_chunk($refs, 48) : [];

        $footQr = $plain ? '' : $qr;
        $foot = fn () => $this->pageFooter($company, $phone, $currency, $dateFull, $withPrices, $webText, $webHref, $version, $footQr);

        $cover = $this->coverHtml($tenant, $plan, $company, $tagline, $logo, $phone, $email,
            $date, $coverImg, $qr, $totalItems, $showIndex, $webText, $webHref, $coverMode, $bikeHero);
        $index = $showIndex
            ? $this->indexHtml($company, $logo, $date, $plan, $bikes, $promoImg, $totalItems, count($crossPages) > 0, $isParts, $foot)
            : '';

        // --- category pages ---
        $body = '';
        foreach ($plan as $p) {
            if ($p['intro']) {
                $body .= $this->categoryIntro($p, $thumbs, $badges, $currency, $withPrices, $company, $logo, $date, $isParts, $ctx, $foot);
            }
            $total = count($p['chunks']);
            foreach ($p['chunks'] as $ci => $items) {
                $head = (! $p['intro'] && $ci === 0)
                    ? $this->categoryHero($p, $thumbs, $badges)
                    : $this->catBand($p['category'], $p['count'] . ' products', $total > 1 ? [$ci + 1, $total] : null);
                $body .= '<div class="page">'
                    . $this->pageHeader($company, $logo, $date)
                    . $head
                    . $this->grid($items, $currency, $withPrices, $thumbs, $badges, $ctx)
                    . $foot()
                    . '</div>';
            }
        }

        // --- OEM cross-reference pages ---
        $cross = '';
        foreach ($crossPages as $ci => $chunk) {
            $cross .= $this->crossRefHtml($chunk, $company, $logo, $date, $ci + 1, count($crossPages), $foot);
        }

        $fromCity = trim((string) $tenant->setting('delivery_from_city', 'Juba')) ?: 'Juba';
        $deliveryLine = trim((string) $tenant->setting('delivery_note', ''))
            ?: 'Free delivery within ' . $fromCity . ' — outside ' . $fromCity . ', transport is confirmed at order';
        // v4.7.7 — business details for the back pages. Only filled settings print.
        $infoRows = array_values(array_filter([
            ['Business hours',  (string) $tenant->setting('business_hours', '')],
            ['Delivery areas',  (string) $tenant->setting('delivery_areas', '')],
            ['Payment methods', (string) $tenant->setting('sf_pay_methods', '')],
            ['Email',           $email],
            ['Location',        trim((string) $tenant->setting('address', '') . ' ' . (string) $tenant->setting('city', ''))],
        ], fn ($r) => trim($r[1]) !== ''));
        $guide = $this->orderGuideHtml($company, $logo, $date, $phone, $email, $qr,
            $showIndex ? '' : $promoImg, ! $showIndex, $webText, $webHref, $deliveryLine, $infoRows,
            trim((string) $tenant->setting('catalog_badge', '')), $isParts, $foot);
        $contact = $this->contactHtml($tenant, $company, $tagline, $logo, $phone, $email, $qr,
            $webText, $webHref, $infoRows, $currency, $dateFull, $withPrices, $version, $date);

        // ── stylesheet: every rule reads the token constants — components share one system ──
        $gd = self::C_GREEN_DEEP; $g = self::C_GREEN; $gm = self::C_GREEN_MID; $wa = self::C_WA;
        $tint = self::C_TINT; $tintLn = self::C_TINT_LINE; $ink = self::C_INK; $gy = self::C_GREY;
        $ft = self::C_FAINT; $ln = self::C_LINE; $wl = self::C_WELL;
        $blue = self::C_BLUE; $blueBg = self::C_BLUE_BG; $blueLn = self::C_BLUE_LINE;
        $fB = $fonts ? '"inter","DejaVu Sans",sans-serif' : '"DejaVu Sans",Arial,sans-serif';
        $fM = $fonts ? '"intermd","DejaVu Sans",sans-serif' : '"DejaVu Sans",Arial,sans-serif';

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 24px 30px 34px; }
*{font-family:{$fB};}
.m, .m div, .m td, .m span{font-family:{$fM};}
body{margin:0;padding:0;color:{$ink};font-size:10px;}
.page{page-break-before:always;}

/* ── plates & bands ── */
.plate{width:100%;border-collapse:collapse;background:{$gd};border-radius:16px;}
table.catband{width:100%;border-collapse:collapse;margin:10px 0 2px;background:{$g};border-radius:10px;}
table.catband td{padding:9px 13px;vertical-align:middle;color:#ffffff;}
.cbname{font-size:15px;font-weight:bold;letter-spacing:-.2px;}
.cbmeta{text-align:right;font-size:8.5px;color:{$tint};}
.eyebrow{font-size:8px;font-weight:bold;letter-spacing:2.5px;color:{$wa};}
.eyebrowg{font-size:8px;font-weight:bold;letter-spacing:2.5px;color:{$g};}

/* ── page chrome ── */
.phead{width:100%;border-collapse:collapse;border-bottom:2px solid {$g};}
.phead td{padding:0 0 6px;vertical-align:bottom;}
.pcompany{font-size:12.5px;font-weight:bold;}
.pdoc{font-size:8px;font-weight:bold;letter-spacing:2px;color:{$g};text-transform:uppercase;}
.pdate{font-size:8px;color:{$ft};}
.pfoot{width:100%;border-collapse:collapse;border-top:1px solid {$ln};margin-top:9px;}
.pfoot td{padding-top:6px;font-size:7.5px;color:{$ft};vertical-align:middle;}
.pfootwa{font-size:8.5px;font-weight:bold;color:{$g};}

/* ── chips & badges ── */
.pill{background:#ffffff;color:{$g};font-size:8.5px;font-weight:bold;padding:3px 10px;border-radius:9px;letter-spacing:.4px;}
.pillg{background:{$g};color:#ffffff;font-size:8.5px;font-weight:bold;padding:3px 10px;border-radius:9px;letter-spacing:.4px;}
.pillt{background:{$tint};border:1px solid {$tintLn};color:{$g};font-size:8.5px;font-weight:bold;padding:3px 10px;border-radius:9px;}
.badge{display:inline-block;color:#ffffff;font-size:7px;font-weight:bold;letter-spacing:.8px;padding:2px 7px;border-radius:8px;}
.oem{display:inline-block;background:{$blueBg};border:1px solid {$blueLn};color:{$blue};font-size:7.5px;font-weight:bold;letter-spacing:.4px;padding:1px 6px;border-radius:7px;}
.stok{display:inline-block;font-size:6.5px;font-weight:bold;letter-spacing:.6px;padding:2px 6px;border-radius:7px;}
.stok-in{background:{$tint};color:{$g};}
.stok-low{background:#F3F4F6;color:{$gy};}
.catchip{display:inline-block;width:20px;background:{$tint};border:1px solid {$tintLn};border-radius:6px;color:{$g};font-size:8px;font-weight:bold;text-align:center;padding:4px 0;}

/* ── product card (v3: 60% image with overlays — badge TL, stock TR, QR BR) ── */
table.grid{width:100%;border-collapse:separate;border-spacing:8px;table-layout:fixed;}
td.cell{width:33%;border:1px solid {$ln};border-radius:10px;vertical-align:top;padding:0;background:#ffffff;}
td.cellpad{border:none;background:none;}
.well{position:relative;height:160px;text-align:center;}
.well img.ph{max-height:150px;max-width:96%;margin-top:5px;}
.ov-tl{position:absolute;top:6px;left:7px;color:#ffffff;font-size:6.5px;font-weight:bold;letter-spacing:.6px;padding:2px 7px;border-radius:8px;}
.ov-tlo{position:absolute;top:6px;left:7px;background:#ffffff;border:1px solid {$tintLn};color:{$g};font-size:6.5px;font-weight:bold;letter-spacing:.6px;padding:1px 6px;border-radius:8px;}
.ov-tr{position:absolute;top:6px;right:7px;font-size:6px;font-weight:bold;letter-spacing:.5px;padding:2px 6px;border-radius:7px;}
.ov-br{position:absolute;bottom:4px;right:5px;background:#ffffff;border:1px solid {$ln};border-radius:5px;padding:2px;}
.ov-br img{height:36px;width:36px;display:block;}
.noimg{display:inline-block;color:{$ft};font-size:8px;font-weight:bold;letter-spacing:1.5px;border:1px dashed {$ln};border-radius:6px;padding:30px 16px;background:{$wl};margin-top:26px;}
.pbody{padding:6px 9px 8px;border-top:1px solid #F3F4F6;}
.pn1{font-size:10.5px;height:15px;overflow:hidden;}
.pu{font-size:8px;color:{$gy};height:15px;overflow:hidden;}
.pspec{font-size:7.5px;color:{$gy};}
.pfits{font-size:8px;color:{$g};font-weight:bold;height:12px;overflow:hidden;margin-top:1px;}
table.prow{width:100%;border-collapse:collapse;margin-top:5px;border-top:1px solid #F3F4F6;}
table.prow td{padding-top:5px;vertical-align:bottom;}
.pr{color:{$g};font-weight:bold;font-size:13.5px;white-space:nowrap;}
.prc{font-size:7px;color:{$ft};font-weight:bold;}
.pfrom{font-size:6.5px;color:{$ft};font-weight:bold;letter-spacing:.5px;}
.sku{text-align:right;font-size:7.5px;color:{$ft};}

/* ── index / hero / intro ── */
table.idx{border-collapse:collapse;width:100%;}
table.idx td{border-bottom:1px solid #F3F4F6;padding:7px 4px;vertical-align:middle;}
.idxn{font-size:10.5px;font-weight:bold;}
.idxc{font-size:8px;color:{$ft};}
.idxp{text-align:right;font-size:10px;font-weight:bold;color:{$g};white-space:nowrap;}
table.hero{width:100%;border-collapse:collapse;margin:12px 0 4px;background:{$g};border-radius:12px;}
table.hero td{padding:13px 16px;vertical-align:middle;}
.heroname{font-size:19px;font-weight:bold;letter-spacing:-.3px;color:#ffffff;}
table.ihero{width:100%;border-collapse:collapse;margin:12px 0 4px;background:{$gd};border-radius:16px;}
.ts{font-size:7.5px;font-weight:bold;letter-spacing:2px;color:{$tint};}
table.tsrow{border-collapse:separate;border-spacing:6px 0;margin-top:8px;}
table.tsrow td{background:#ffffff;border-radius:8px;padding:3px;}
table.tsrow img{height:40px;max-width:52px;}
.heroimg{background:#ffffff;border-radius:10px;padding:6px;}
.wcard{background:#ffffff;border-radius:12px;padding:8px;}

/* ── strips & panels ── */
table.feat{width:100%;border-collapse:collapse;margin-top:12px;background:{$wl};border:1px solid {$ln};border-radius:10px;}
table.feat td{padding:9px 12px;font-size:8.5px;color:{$gy};font-weight:bold;}
.promo{width:100%;border-collapse:collapse;background:{$gd};border-radius:12px;margin:12px 0 0;}
.promo td{padding:14px 18px;vertical-align:middle;}
.promoimg img{max-height:76px;max-width:150px;border-radius:6px;}
table.bikes{width:100%;border-collapse:separate;border-spacing:7px 0;table-layout:fixed;}
td.bike{background:{$wl};border:1px solid {$ln};border-radius:10px;text-align:center;padding:8px 4px 7px;}
td.bike img{max-height:74px;max-width:100%;border-radius:6px;}
.bikename{font-size:8px;font-weight:bold;margin-top:5px;color:{$ink};}
.qrbox{background:#ffffff;border-radius:8px;padding:5px;display:inline-block;}
.statv{font-size:20px;font-weight:bold;color:#ffffff;}
.statl{font-size:7px;font-weight:bold;letter-spacing:1.5px;color:{$tint};}

/* ── cross-reference ── */
table.xref{border-collapse:collapse;width:100%;}
table.xref th{font-size:7.5px;text-transform:uppercase;letter-spacing:1px;color:{$ft};text-align:left;padding:5px 6px;border-bottom:2px solid {$g};}
table.xref td{font-size:8px;padding:5px 6px;border-bottom:1px solid #F3F4F6;vertical-align:middle;}
.xoem{font-weight:bold;letter-spacing:.3px;white-space:nowrap;}
.xsku{color:{$g};font-weight:bold;white-space:nowrap;font-size:7.5px;}
.xpg{text-align:right;color:{$ft};}

/* ── order guide / contact ── */
.step{width:100%;border-collapse:collapse;margin:0 0 18px;}
.stepn{width:30px;height:30px;background:{$tint};border:1px solid {$tintLn};border-radius:15px;color:{$g};font-weight:bold;font-size:14px;text-align:center;line-height:30px;}
.ordercard{background:{$gd};border-radius:14px;padding:18px 20px;}
.chk{width:15px;height:15px;background:{$tint};border:1px solid {$tintLn};border-radius:8px;color:{$g};font-weight:bold;font-size:9px;text-align:center;line-height:15px;}
.infocell{background:{$wl};border:1px solid {$ln};border-radius:10px;}

/* ── cover ── */
.ctitle{font-size:34px;font-weight:bold;letter-spacing:-.8px;color:#ffffff;line-height:1.08;}
.datechip{display:inline-block;background:#ffffff;color:{$gd};font-size:9.5px;font-weight:bold;padding:4px 13px;border-radius:11px;letter-spacing:1px;}
.covertag{display:inline-block;border:1px solid {$gm};color:{$tint};font-size:8.5px;font-weight:bold;padding:4px 11px;border-radius:11px;}
.coverfix{position:relative;height:1056px;}
.coverbar{position:absolute;top:966px;left:0;width:100%;}
.coverfeat{position:absolute;top:872px;left:0;width:100%;}
.featsub{font-size:8px;color:{$ft};font-weight:normal;margin-top:2px;}
.backpage{page-break-before:always;}
</style></head><body>
{$cover}
{$index}
{$body}
{$cross}
{$guide}
{$contact}
</body></html>
HTML;
    }

    /** Comma-separated settings → [normalised sku/name => badge]. Red is reserved for HOT DEAL. */
    private function badgeMap(Tenant $tenant): array
    {
        $map = [];
        $lists = [
            ['keys' => (string) $tenant->setting('catalog_best_sellers', ''), 'label' => 'BEST SELLER', 'bg' => self::C_GREEN],
            ['keys' => (string) $tenant->setting('catalog_hot_deals', ''), 'label' => 'HOT DEAL', 'bg' => self::C_RED],
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

    /** Two-letter category monogram chip ("Engine & Fuel" → EF). */
    private function catChip(string $category): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $category, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $code = '';
        foreach ($words as $w) {
            $code .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
            if (mb_strlen($code, 'UTF-8') >= 2) {
                break;
            }
        }
        if ($code === '') {
            $code = '·';
        }

        return '<span class="catchip">' . e($code) . '</span>';
    }

    /** Stock chip from the item's stock value (null = untracked = available, storefront rule). */
    private function stockChip($stock): string
    {
        if ($stock === null || (int) $stock > 5) {
            return '<span class="stok stok-in">IN STOCK</span>';
        }
        if ((int) $stock > 0) {
            return '<span class="stok stok-low">LOW STOCK</span>';
        }

        return '<span class="stok stok-low">ON REQUEST</span>';
    }

    /** Green category band above product grids: name + pill + "i / j" pager (null pill hides). */
    private function catBand(string $category, ?string $pill, ?array $pager): string
    {
        $cont = $pager ? ' <span style="font-weight:normal;color:' . self::C_TINT . ';font-size:9px">&nbsp;' . (int) $pager[0] . ' / ' . (int) $pager[1] . '</span>' : '';

        return '<table class="catband"><tr>'
            . '<td class="cbname">' . e($category) . $cont . '</td>'
            . '<td class="cbmeta">' . ($pill !== null ? '<span class="pill">' . e($pill) . '</span>' : '&nbsp;') . '</td>'
            . '</tr></table>';
    }

    private function coverHtml(Tenant $tenant, array $plan, string $company, string $tagline,
        string $logo, string $phone, string $email, string $date, string $coverImg, string $qr,
        int $totalItems, bool $showIndex, string $webText = '', string $webHref = '',
        string $coverMode = 'hero', string $bikeHero = ''): string
    {
        // Fully-designed portrait artwork = the whole first page, nothing drawn over it.
        if ($coverImg !== '' && $coverMode === 'full') {
            return '<div class="cover" style="text-align:center">'
                . '<img src="' . e($coverImg) . '" style="max-width:100%;max-height:1046px;border-radius:10px">'
                . '</div>';
        }

        $gd = self::C_GREEN_DEEP; $gm = self::C_GREEN_MID; $wa = self::C_WA;
        $tint = self::C_TINT; $ft = self::C_FAINT;
        $badgeTxt = trim((string) $tenant->setting('catalog_badge', ''));
        $coverTags = trim((string) $tenant->setting('catalog_cover_tags', ''));
        $isParts = $bikeHero !== '';
        $docLine = $isParts ? 'GENUINE MOTORCYCLE SPARE PARTS' : 'WHOLESALE PRODUCT CATALOGUE';

        $webLine = $webText !== ''
            ? '<a href="' . e($webHref) . '" style="color:' . $tint . ';text-decoration:none;font-weight:bold">' . e($webText) . '</a>'
            : '';

        // Photographic cover (landscape art uploaded): keep the composed layout, v2 tokens.
        if ($coverImg !== '') {
            $audience = array_filter(array_map('trim', explode(',',
                (string) $tenant->setting('catalog_audience', 'Hotels,Restaurants,Supermarkets,Retailers'))));
            $audRows = '';
            foreach (array_slice($audience, 0, 5) as $a) {
                $audRows .= '<table style="border-collapse:collapse;margin-top:7px"><tr>'
                    . '<td style="width:21px"><div class="chk">✓</div></td>'
                    . '<td style="font-size:11px;font-weight:bold;color:' . self::C_INK . ';padding-left:4px">For ' . e($a) . '</td></tr></table>';
            }
            $badgeChip = $badgeTxt !== ''
                ? '<span class="pillt">' . e($this->clipLine($badgeTxt, 46)) . '</span>'
                : '';
            $logoBlock = $logo !== ''
                ? '<img src="' . e($logo) . '" style="height:40px;max-width:150px">'
                : '<div style="font-size:19px;font-weight:bold;color:' . self::C_GREEN . '">' . $company . '</div>';

            $features = '<div class="coverfeat"><table class="feat"><tr>'
                . '<td style="width:25%">✓ Great prices<div class="featsub">Best wholesale rates</div></td>'
                . '<td style="width:25%">✓ Quality assured<div class="featsub">Genuine products</div></td>'
                . '<td style="width:25%">✓ Fast-moving lines<div class="featsub">High-demand stock</div></td>'
                . '<td style="width:25%">✓ Reliable supply<div class="featsub">Consistent availability</div></td>'
                . '</tr></table></div>';
            $contactBar = '<table style="width:100%;border-collapse:collapse;background:' . $gd . ';border-radius:12px"><tr>'
                . ($qr !== '' ? '<td style="width:74px;padding:10px 0 10px 14px"><span class="qrbox"><img src="' . e($qr) . '" style="height:52px;width:52px"></span></td>'
                    . '<td style="padding:10px 6px;color:#ffffff;font-size:9px;font-weight:bold;width:120px;letter-spacing:.5px">SCAN TO ORDER<br>ON WHATSAPP</td>' : '')
                . '<td style="padding:10px 14px;text-align:right;color:' . $tint . ';font-size:9.5px;font-weight:bold">'
                . ($phone !== '' ? e($phone) . '<br>' : '') . ($email !== '' ? e($email) : '')
                . ($webLine !== '' ? ($phone !== '' || $email !== '' ? '<br>' : '') . $webLine : '')
                . '</td></tr></table>';

            return '<div class="cover coverfix">'
                . '<table style="width:100%;border-collapse:collapse"><tr><td style="padding:2px 0 10px">' . $logoBlock
                . ($tagline ? '<div style="font-size:9.5px;color:' . self::C_GREY . ';font-weight:bold;margin-top:3px">' . $tagline . '</div>' : '')
                . '</td></tr></table>'
                . '<table style="width:100%;border-collapse:collapse"><tr>'
                . '<td style="width:47%;vertical-align:top;padding-top:22px">'
                . '<div class="eyebrowg" style="font-size:11px">' . e($docLine) . '</div>'
                . '<div class="ctitle" style="color:' . self::C_GREEN . ';font-size:31px">PRODUCT<br>CATALOGUE</div>'
                . '<div style="margin-top:14px"><span class="datechip" style="background:' . self::C_GREEN . ';color:#ffffff">' . $date . '</span>' . ($badgeChip !== '' ? ' ' . $badgeChip : '') . '</div>'
                . ($coverTags !== '' ? '<div style="margin-top:10px;font-size:9.5px;font-weight:bold;color:' . self::C_GREY . ';letter-spacing:.4px">' . e($this->clipLine($coverTags, 90)) . '</div>' : '')
                . '<div style="margin-top:18px">' . $audRows . '</div>'
                . '<table style="border-collapse:collapse;margin-top:22px"><tr><td><div class="ordercard" style="padding:13px 18px">'
                . '<div class="statv">' . number_format($totalItems) . '+</div>'
                . '<div class="statl">PRODUCTS INSIDE</div>'
                . '</div></td></tr></table>'
                . '</td>'
                . '<td style="width:53%;vertical-align:top;padding:12px 0 0 16px">'
                . '<img src="' . e($coverImg) . '" style="width:100%;border-radius:14px">'
                . '</td></tr></table>'
                . $features
                . '<div class="coverbar">' . $contactBar . '</div>'
                . '</div>';
        }

        // Generated cover — the design-system plate: deep green, hero photo, stats, QR bar.
        $initials = '';
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $company, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $initials .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
            if (mb_strlen($initials, 'UTF-8') >= 2) break;
        }
        $logoBlock = $logo !== ''
            ? '<div style="background:#ffffff;border-radius:12px;padding:10px 18px;display:inline-block"><img src="' . e($logo) . '" style="height:46px;max-width:170px"></div>'
            : '<span style="display:inline-block;background:' . $gm . ';color:#ffffff;font-size:17px;font-weight:bold;border-radius:14px;padding:12px 16px;letter-spacing:1px">' . e($initials ?: '•') . '</span>';

        // hero art: motorcycle shot for parts tenants, else up to 3 product-thumb cards
        $heroBlock = '';
        if ($bikeHero !== '') {
            $heroBlock = '<div class="wcard" style="display:inline-block;max-width:520px"><img src="' . e($bikeHero) . '" style="max-width:504px;max-height:346px;border-radius:8px"></div>';
        }

        $stats = '<table style="border-collapse:separate;border-spacing:8px 0;margin:0 auto"><tr>'
            . '<td style="background:' . $gm . ';border-radius:12px;padding:11px 20px;text-align:center"><div class="statv">' . number_format($totalItems) . '+</div><div class="statl">GENUINE PARTS</div></td>'
            . '<td style="background:' . $gm . ';border-radius:12px;padding:11px 20px;text-align:center"><div class="statv">' . count($plan) . '</div><div class="statl">CATEGORIES</div></td>'
            . '<td style="background:' . $gm . ';border-radius:12px;padding:11px 20px;text-align:center"><div class="statv">' . now()->format('Y') . '</div><div class="statl">EDITION</div></td>'
            . '</tr></table>';
        if (! $bikeHero) {
            $stats = str_replace('GENUINE PARTS', 'PRODUCTS', $stats);
        }

        $tagChips = '';
        if ($coverTags !== '') {
            foreach (array_slice(array_filter(array_map('trim', preg_split('/[·,]+/u', $coverTags))), 0, 3) as $t) {
                $tagChips .= '<span class="covertag">' . e($this->clipLine($t, 28)) . '</span>&nbsp; ';
            }
        }
        $badgeChip = $badgeTxt !== ''
            ? '<div style="margin-top:10px"><span class="covertag" style="border-color:' . $wa . ';color:#ffffff">' . e($this->clipLine($badgeTxt, 46)) . '</span></div>'
            : '';

        $contactBar = '<table style="width:100%;border-collapse:collapse;background:#ffffff;border-radius:12px"><tr>'
            . ($qr !== '' ? '<td style="width:66px;padding:9px 0 9px 12px"><img src="' . e($qr) . '" style="height:54px;width:54px"></td>'
                . '<td style="padding:9px 6px;width:118px"><div style="font-size:8px;font-weight:bold;letter-spacing:1px;color:' . self::C_GREEN . '">SCAN TO ORDER</div>'
                . '<div style="font-size:8px;font-weight:bold;letter-spacing:1px;color:' . self::C_INK . '">ON WHATSAPP</div></td>' : '')
            . '<td style="padding:9px 14px;text-align:right">'
            . ($phone !== '' ? '<div style="font-size:12.5px;font-weight:bold;color:' . self::C_INK . '">' . e($phone) . '</div>' : '')
            . '<div style="font-size:9px;font-weight:bold;color:' . self::C_GREEN . '">'
            . implode(' &nbsp;·&nbsp; ', array_filter([$webText !== '' ? e($webText) : '', $email !== '' ? e($email) : '']))
            . '</div></td></tr></table>';

        return '<div class="cover">'
            . '<table class="plate"><tr><td style="padding:34px 34px 30px;text-align:center;height:990px;vertical-align:top">'
            . $logoBlock
            . '<div class="eyebrow" style="margin-top:30px;font-size:9px">' . e($docLine) . '</div>'
            . '<div class="ctitle" style="margin-top:10px;font-size:36px">' . $company . '</div>'
            . ($tagline ? '<div style="margin-top:10px;font-size:10.5px;color:' . $tint . ';font-weight:bold">' . $tagline . '</div>' : '')
            . '<div style="margin-top:18px"><span class="datechip">' . strtoupper($date) . ' CATALOGUE</span></div>'
            . ($tagChips !== '' ? '<div style="margin-top:12px">' . $tagChips . '</div>' : '')
            . $badgeChip
            . ($heroBlock !== '' ? '<div style="margin-top:30px">' . $heroBlock . '</div>' : '<div style="margin-top:16px"></div>')
            . '<div style="margin-top:30px">' . $stats . '</div>'
            . '<div style="margin-top:34px">' . $contactBar . '</div>'
            . '</td></tr></table>'
            . '</div>';
    }

    /** The trust strip — one component, vertical-aware copy. */
    private function featStrip(bool $isParts): string
    {
        $cells = $isParts
            ? ['✓ 100% genuine parts', '✓ Same-day dispatch', '✓ Fitment help on WhatsApp', '✓ Dealer prices available']
            : ['✓ Wholesale prices', '✓ Genuine brands', '✓ Bulk discounts', '✓ Delivery on request'];
        $tds = '';
        foreach ($cells as $c) {
            $tds .= '<td style="width:25%">' . $c . '</td>';
        }

        return '<table class="feat"><tr>' . $tds . '</tr></table>';
    }

    /** Page 2 — "What's inside": category index (+ motorcycle strip for parts tenants). */
    private function indexHtml(string $company, string $logo, string $date, array $plan,
        array $bikes, string $promoImg, int $totalItems, bool $hasCross, bool $isParts, \Closure $foot): string
    {
        $rows = [];
        foreach ($plan as $p) {
            $rows[] = '<tr>'
                . '<td style="width:26px">' . $this->catChip($p['category']) . '</td>'
                . '<td><div class="idxn m">' . e($this->clipLine($p['category'], 30)) . '</div>'
                . '<div class="idxc">' . (int) $p['count'] . ' products</div></td>'
                . '<td class="idxp">p.' . (int) $p['startPage'] . '</td>'
                . '</tr>';
        }
        $half = (int) ceil(count($rows) / 2);
        $index = '<table style="width:100%;border-collapse:separate;border-spacing:14px 0;margin-top:4px"><tr>'
            . '<td style="width:50%;vertical-align:top"><table class="idx">' . implode('', array_slice($rows, 0, $half)) . '</table></td>'
            . '<td style="width:50%;vertical-align:top"><table class="idx">' . implode('', array_slice($rows, $half)) . '</table></td>'
            . '</tr></table>';

        $bikeStrip = '';
        if ($bikes) {
            $tds = '';
            foreach ($bikes as [$name, $uri]) {
                $tds .= '<td class="bike"><img src="' . e($uri) . '"><div class="bikename">' . e($this->clipLine($name, 16)) . '</div></td>';
            }
            $bikeStrip = '<div class="eyebrowg" style="margin:16px 0 7px">PARTS FOR THESE MOTORCYCLES</div>'
                . '<table class="bikes"><tr>' . $tds . '</tr></table>';
        }

        $crossNote = $hasCross
            ? '<table class="feat" style="margin-top:14px"><tr>'
                . '<td style="width:26px;padding-right:0">' . $this->catChip('O E') . '</td>'
                . '<td><b style="color:' . self::C_INK . '">Order by OEM number</b> — the cross-reference at the back matches every manufacturer part number to our stock.</td>'
                . '</tr></table>'
            : '';

        return '<div class="page">'
            . $this->pageHeader($company, $logo, $date)
            . '<div style="margin:16px 0 2px;font-size:22px;font-weight:bold;letter-spacing:-.4px">What\'s inside</div>'
            . '<div style="font-size:9.5px;color:' . self::C_GREY . ';margin-bottom:8px">' . number_format($totalItems) . ' products across ' . count($plan) . ' categories — '
            . ($isParts ? 'every one genuine, stock confirmed on order.' : 'wholesale prices, stock confirmed on order.') . '</div>'
            . $index
            . $bikeStrip
            . $crossNote
            . $this->promoBand($promoImg)
            . $foot()
            . '</div>';
    }

    /** The "Don't see the part you need?" deep-green band (promo photo optional). */
    private function promoBand(string $promoImg): string
    {
        return '<table class="promo"><tr>'
            . '<td><div style="font-size:15px;font-weight:bold;color:#ffffff">Don\'t see what you need?</div>'
            . '<div style="font-size:9.5px;color:' . self::C_TINT . ';margin-top:5px;font-weight:bold">Message us on WhatsApp — we check with our supplier network and source it for you.</div></td>'
            . ($promoImg !== '' ? '<td style="width:170px;text-align:right" class="promoimg"><img src="' . e($promoImg) . '"></td>' : '')
            . '</tr></table>';
    }

    /**
     * Full intro page for a big category (≥ INTRO_MIN items): deep-green hero plate, top-picks
     * card row (the SAME card component as product pages), trust strip.
     */
    private function categoryIntro(array $p, array $thumbs, array $badges, string $currency,
        bool $withPrices, string $company, string $logo, string $date, bool $isParts, array $ctx, \Closure $foot): string
    {
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

        $pages = 'pages ' . (int) $p['startPage'] . '–' . ((int) $p['startPage'] + count($p['chunks']));
        $hero = '<table class="ihero"><tr>'
            . '<td style="vertical-align:middle;padding:24px 26px">'
            . '<div class="eyebrow">' . e(mb_strtoupper($this->clipLine($p['category'], 30))) . '</div>'
            . '<div style="font-size:25px;font-weight:bold;letter-spacing:-.4px;color:#ffffff;margin-top:6px">' . e($p['category']) . '</div>'
            . '<div style="margin-top:10px"><span class="pill">' . (int) $p['count'] . ' products</span>'
            . '&nbsp;&nbsp;<span style="font-size:8.5px;color:' . self::C_TINT . ';font-weight:bold">' . $pages . '</span></div>'
            . '<div style="margin-top:13px;font-size:9.5px;color:' . self::C_TINT . ';line-height:1.6">'
            . ($isParts ? 'Every part checked genuine before dispatch —<br>stock and fitment confirmed on your order.'
                : 'Wholesale prices on every pack size —<br>stock confirmed on your order.') . '</div>'
            . '</td>'
            . ($heroSrc !== '' ? '<td style="width:270px;text-align:center;vertical-align:middle;padding:16px"><div class="wcard"><img src="' . e($heroSrc) . '" style="max-height:206px;max-width:100%"></div></td>' : '')
            . '</tr></table>';

        $pickRow = $picks
            ? '<div class="eyebrowg" style="margin:13px 0 1px">★ TOP SELLERS IN ' . e(mb_strtoupper($this->clipLine($p['category'], 26))) . '</div>'
                . $this->grid($picks, $currency, $withPrices, $thumbs, $badges, $ctx)
            : '';

        return '<div class="page">'
            . $this->pageHeader($company, $logo, $date)
            . $hero
            . $pickRow
            . $this->featStrip($isParts)
            . $foot()
            . '</div>';
    }

    /** First page of a small category: green hero band — name, count, top-seller strip, photo. */
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
            $strip = '<div class="ts" style="margin-top:9px">★ TOP SELLERS</div><table class="tsrow"><tr>' . $tds . '</tr></table>';
        }
        $total = count($p['chunks']);
        $cont = $total > 1 ? ' <span style="font-weight:normal;color:' . self::C_TINT . ';font-size:10px">1 / ' . $total . '</span>' : '';

        // the brands stocked in this category, as chips ("Bajaj Boxer · TVS" tells a mechanic
        // instantly whether their bike is covered)
        $brands = [];
        foreach ($p['items'] as $it) {
            $bn = trim((string) ($it['brand'] ?? ''));
            if ($bn !== '') {
                $brands[$bn] = true;
            }
        }
        $brandChips = '';
        foreach (array_slice(array_keys($brands), 0, 3) as $bn) {
            $brandChips .= ' <span class="pill" style="background:' . self::C_TINT . '">' . e($this->clipLine($bn, 16)) . '</span>';
        }

        return '<table class="hero"><tr>'
            . '<td><div class="heroname">' . e($p['category']) . $cont . '</div>'
            . '<div style="margin-top:7px"><span class="pill">' . (int) $p['count'] . ' products</span>' . $brandChips . '</div>'
            . $strip . '</td>'
            . ($heroSrc !== '' ? '<td style="width:150px;text-align:right"><span class="heroimg" style="display:inline-block"><img src="' . e($heroSrc) . '" style="max-height:96px;max-width:128px"></span></td>' : '')
            . '</tr></table>';
    }

    /** OEM cross-reference page: manufacturer number → our part, SKU, page. */
    private function crossRefHtml(array $refs, string $company, string $logo, string $date,
        int $pageNo, int $pageTotal, \Closure $foot): string
    {
        $head = '<tr><th style="width:25%">OEM number</th><th>Part</th><th style="width:27%">Our SKU</th><th style="width:9%;text-align:right">Page</th></tr>';
        $mkRows = function (array $chunk) {
            $rows = '';
            foreach ($chunk as $r) {
                $rows .= '<tr>'
                    . '<td class="xoem m">' . e($this->clipLine($r['oem'], 15)) . '</td>'
                    . '<td>' . e($this->clipLine($r['name'], 21)) . '</td>'
                    . '<td class="xsku">' . e($this->clipLine($r['sku'] !== '' ? $r['sku'] : '—', 16)) . '</td>'
                    . '<td class="xpg">' . (int) $r['page'] . '</td>'
                    . '</tr>';
            }

            return $rows;
        };
        $half = (int) ceil(count($refs) / 2);
        $cols = '<table style="width:100%;border-collapse:separate;border-spacing:12px 0;margin-top:6px"><tr>'
            . '<td style="width:50%;vertical-align:top"><table class="xref">' . $head . $mkRows(array_slice($refs, 0, $half)) . '</table></td>'
            . '<td style="width:50%;vertical-align:top"><table class="xref">' . $head . $mkRows(array_slice($refs, $half)) . '</table></td>'
            . '</tr></table>';

        $pager = $pageTotal > 1 ? [$pageNo, $pageTotal] : null;

        return '<div class="page">'
            . $this->pageHeader($company, $logo, $date)
            . $this->catBand('OEM Cross-Reference', count($refs) . ' numbers', $pager)
            . '<div style="font-size:9px;color:' . self::C_GREY . ';margin:2px 0 4px">Order by the number stamped on your part or listed in the service manual — we match it to our stock. Send the OEM number on WhatsApp for instant confirmation.</div>'
            . $cols
            . $foot()
            . '</div>';
    }

    /** "How to order" guide: steps, order card with QR, business details grid. */
    private function orderGuideHtml(string $company, string $logo, string $date, string $phone,
        string $email, string $qr, string $promoImg, bool $withPromo, string $webText,
        string $webHref, string $deliveryLine, array $infoRows, string $badgeTxt, bool $isParts, \Closure $foot): string
    {
        $ink = self::C_INK;
        $promo = $withPromo ? $this->promoBand($promoImg) . '<div style="height:6px"></div>' : '';

        $ask = $isParts ? 'part name, OEM number or a photo all work' : 'product names and quantities — a photo of your list works too';
        $confirm = $isParts ? 'You get the exact price, availability and fitment confirmation back' : 'You get the exact total and availability back in one message';
        $steps = [
            ['1', 'Send your order on WhatsApp', ucfirst($phone !== '' ? 'message ' . e($phone) . ' — ' . $ask : $ask)],
            ['2', 'We confirm stock &amp; total', $confirm],
            ['3', 'Pick up or delivery', $deliveryLine !== '' ? e($this->clipLine($deliveryLine, 110)) : 'Collect at the store or ask about delivery'],
        ];
        $stepHtml = '';
        foreach ($steps as [$n, $t, $s]) {
            $stepHtml .= '<table class="step"><tr>'
                . '<td style="width:40px"><div class="stepn">' . $n . '</div></td>'
                . '<td><div style="font-size:14px;font-weight:bold;color:' . $ink . '">' . $t . '</div>'
                . '<div style="font-size:10px;color:' . self::C_GREY . ';margin-top:3px;line-height:1.5">' . $s . '</div></td>'
                . '</tr></table>';
        }

        return '<div class="backpage">'
            . $this->pageHeader($company, $logo, $date)
            . $this->catBand('How to order', null, null)
            . $promo
            . '<table style="width:100%;border-collapse:collapse;margin-top:14px"><tr><td style="width:55%;vertical-align:top;padding-right:16px">' . $stepHtml . '</td>'
            . '<td style="vertical-align:top">'
            . '<div class="ordercard">'
            . '<table style="width:100%;border-collapse:collapse"><tr><td>'
            . '<div class="eyebrow">ORDER ON WHATSAPP</div>'
            . '<div style="font-size:18px;font-weight:bold;color:#ffffff;margin-top:6px">' . ($phone !== '' ? e($phone) : $company) . '</div>'
            . ($email !== '' ? '<div style="font-size:10px;color:' . self::C_TINT . ';margin-top:8px">' . e($email) . '</div>' : '')
            . ($webText !== '' ? '<div style="font-size:11px;margin-top:9px;padding-top:9px;border-top:1px solid ' . self::C_GREEN_MID . '">'
                . '<span class="eyebrow" style="font-size:7px">OR ORDER ONLINE</span><br>'
                . '<a href="' . e($webHref) . '" style="color:#ffffff;font-weight:bold;text-decoration:none;font-size:12.5px">' . e($webText) . '</a></div>' : '')
            . '</td>'
            . ($qr !== '' ? '<td style="width:74px;text-align:right;vertical-align:top"><span class="qrbox"><img src="' . e($qr) . '" style="height:62px;width:62px"></span></td>' : '')
            . '</tr></table>'
            . '</div>'
            . '</td></tr></table>'
            . $this->backInfo($infoRows, $badgeTxt)
            . str_replace('<table class="feat">', '<table class="feat" style="margin-top:14px">', $this->featStrip($isParts))
            . $foot()
            . '</div>';
    }

    /**
     * Compact business-details block (hours, delivery areas, payment, email, location) + a
     * standing dealer-enquiry line. Rows come pre-filtered (only settings the shop filled).
     */
    private function backInfo(array $infoRows, string $badgeTxt): string
    {
        $cells = [];
        foreach ($infoRows as [$label, $value]) {
            $cells[] = '<td style="width:50%;padding:11px 13px;vertical-align:top" class="infocell">'
                . '<div style="font-size:7.5px;font-weight:bold;letter-spacing:1.2px;color:' . self::C_FAINT . '">' . strtoupper(e($label)) . '</div>'
                . '<div style="font-size:10.5px;font-weight:bold;color:' . self::C_INK . ';margin-top:3px">' . e($this->clipLine($value, 70)) . '</div>'
                . '</td>';
        }
        $rows = '';
        foreach (array_chunk($cells, 2) as $pair) {
            if (count($pair) === 1) $pair[] = '<td style="width:50%;border:none;background:none"></td>';
            $rows .= '<tr>' . implode('', $pair) . '</tr>';
        }
        $table = $rows !== ''
            ? '<table style="width:100%;border-collapse:separate;border-spacing:6px;margin-top:10px">' . $rows . '</table>'
            : '';
        $dealer = '<div style="margin-top:10px;font-size:10.5px;font-weight:bold;color:' . self::C_GREEN . '">'
            . 'Dealer &amp; bulk enquiries welcome — ask for the trade price list.'
            . ($badgeTxt !== '' ? ' &nbsp;·&nbsp; ' . e($this->clipLine($badgeTxt, 46)) : '')
            . '</div>';

        return $table . $dealer;
    }

    /** Closing page — deep-green contact plate: WhatsApp, website, details, QR, version. */
    private function contactHtml(Tenant $tenant, string $company, string $tagline, string $logo,
        string $phone, string $email, string $qr, string $webText, string $webHref,
        array $infoRows, string $currency, string $dateFull, bool $withPrices,
        string $version, string $date): string
    {
        $gd = self::C_GREEN_DEEP; $gm = self::C_GREEN_MID; $tint = self::C_TINT;

        $initials = '';
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $company, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $initials .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
            if (mb_strlen($initials, 'UTF-8') >= 2) break;
        }
        $logoBlock = $logo !== ''
            ? '<div style="background:#ffffff;border-radius:12px;padding:10px 18px;display:inline-block"><img src="' . e($logo) . '" style="height:40px;max-width:150px"></div>'
            : '<span style="display:inline-block;background:' . $gm . ';color:#ffffff;font-size:15px;font-weight:bold;border-radius:12px;padding:10px 14px;letter-spacing:1px">' . e($initials ?: '•') . '</span>';

        $detailCells = '';
        foreach (array_slice($infoRows, 0, 4) as [$label, $value]) {
            $detailCells .= '<td style="background:' . $gm . ';border-radius:10px;padding:9px 12px;vertical-align:top">'
                . '<div style="font-size:7px;font-weight:bold;letter-spacing:1.2px;color:' . $tint . '">' . strtoupper(e($label)) . '</div>'
                . '<div style="font-size:9px;font-weight:bold;color:#ffffff;margin-top:2px">' . e($this->clipLine($value, 42)) . '</div>'
                . '</td>';
        }
        $details = $detailCells !== ''
            ? '<table style="width:100%;border-collapse:separate;border-spacing:6px;margin-top:20px"><tr>' . $detailCells . '</tr></table>'
            : '';

        // the dealer programme is live (OTP sign-in, trade prices) — recruit from the catalogue
        $dealerCard = '<table style="width:100%;border-collapse:collapse;background:#ffffff;border-radius:12px;margin-top:26px"><tr>'
            . '<td style="padding:14px 18px;text-align:left">'
            . '<div class="eyebrowg" style="font-size:8.5px">BECOME A DEALER</div>'
            . '<div style="font-size:11px;font-weight:bold;color:' . self::C_INK . ';margin-top:4px">Garages &amp; shops: trade prices and priority stock.</div>'
            . '<div style="font-size:9px;color:' . self::C_GREY . ';margin-top:3px">Message ' . ($phone !== '' ? e($phone) : 'us') . ' with your business name to enrol — takes two minutes.</div>'
            . '</td></tr></table>';

        return '<div class="backpage">'
            . '<table class="plate"><tr><td style="padding:110px 40px 40px;text-align:center;height:910px;vertical-align:top">'
            . $logoBlock
            . '<div class="ctitle" style="margin-top:30px;font-size:29px">' . $company . '</div>'
            . ($tagline ? '<div style="margin-top:10px;font-size:10.5px;color:' . $tint . ';font-weight:bold">' . $tagline . '</div>' : '')
            . '<div style="margin-top:56px" class="eyebrow">ORDER ON WHATSAPP</div>'
            . ($phone !== '' ? '<div style="font-size:28px;font-weight:bold;color:#ffffff;margin-top:10px">' . e($phone) . '</div>' : '')
            . ($webText !== '' ? '<div style="margin-top:16px;font-size:13px;font-weight:bold"><a href="' . e($webHref) . '" style="color:' . self::C_WA . ';text-decoration:none">' . e($webText) . '</a></div>' : '')
            . ($email !== '' ? '<div style="margin-top:7px;font-size:9.5px;color:' . $tint . '">' . e($email) . '</div>' : '')
            . ($qr !== '' ? '<div style="margin-top:48px"><span class="qrbox" style="padding:8px"><img src="' . e($qr) . '" style="height:96px;width:96px"></span>'
                . '<div style="font-size:8px;font-weight:bold;letter-spacing:1.5px;color:' . $tint . ';margin-top:8px">SCAN · ORDER · DELIVERED</div></div>' : '')
            . str_replace('margin-top:20px', 'margin-top:48px', $details)
            . $dealerCard
            . '<div style="margin-top:32px;border-top:1px solid ' . $gm . ';padding-top:12px;font-size:7.5px;color:' . $tint . '">'
            . e($company) . ' · Catalogue ' . e($date) . ' · ' . e($version)
            . ($withPrices ? ' — prices in ' . e($currency) . ', valid as of ' . e($dateFull) . ', subject to change without notice. Availability confirmed on order.' : ' — availability confirmed on order.')
            . ' Thank you for your business.'
            . '</div>'
            . '</td></tr></table>'
            . '</div>';
    }

    private function pageHeader(string $company, string $logo, string $date): string
    {
        $logoTag = $logo !== '' ? '<img src="' . e($logo) . '" style="height:24px;max-width:90px">' : '';

        return '<table class="phead"><tr>'
            . '<td><div class="pcompany">' . $company . '</div></td>'
            . '<td style="text-align:right">' . ($logoTag !== '' ? $logoTag
                : '<div class="pdoc">Product Catalogue</div><div class="pdate">' . $date . '</div>') . '</td>'
            . '</tr></table>';
    }

    /**
     * THE product card (one component, everywhere): 60% photo with overlaid badge / stock
     * chip / product QR, then OEM+spec row, name, fitment line, price row.
     * $ctx: ['fits' => fitModels(), 'qr' => qrBatch(), 'pop' => set of POPULAR ids]
     * @param array<int,array<string,mixed>> $items
     */
    private function grid(array $items, string $currency, bool $withPrices, array $thumbs, array $badges = [], array $ctx = []): string
    {
        $fitModels = $ctx['fits'] ?? [];
        $qrMap = $ctx['qr'] ?? [];
        $popSet = $ctx['pop'] ?? [];

        $cells = [];
        foreach ($items as $it) {
            $img = trim((string) ($it['image'] ?? ''));
            $src = $img !== '' ? ($thumbs === [] ? $img : ($thumbs[$img] ?? '')) : '';

            // overlays: owner badge > auto POPULAR > auto NEW (top-left), stock (top-right), QR (bottom-right)
            $b = $this->badgeFor($it, $badges);
            $badgeTag = '';
            if ($b) {
                $badgeTag = '<span class="ov-tl" style="background:' . $b['bg'] . '">' . $b['label'] . '</span>';
            } elseif (! empty($it['id']) && isset($popSet[(int) $it['id']])) {
                $badgeTag = '<span class="ov-tl" style="background:' . self::C_GREEN_MID . '">POPULAR</span>';
            } elseif (! empty($it['new'])) {
                $badgeTag = '<span class="ov-tlo">NEW</span>';
            }
            $stockTag = array_key_exists('stock', $it)
                ? str_replace('class="stok ', 'class="ov-tr stok ', $this->stockChip($it['stock']))
                : '';
            $key = (string) ($it['id'] ?? '') !== '' ? 'i' . $it['id'] : (string) ($it['sku'] ?? $it['name'] ?? '');
            $qrUri = $qrMap[$key] ?? '';
            $qrTag = $qrUri !== '' ? '<span class="ov-br"><img src="' . e($qrUri) . '"></span>' : '';
            $photo = '<div class="well">'
                . ($src !== '' ? '<img class="ph" src="' . e($src) . '">' : '<span class="noimg">PHOTO<br>COMING SOON</span>')
                . $badgeTag . $stockTag . $qrTag
                . '</div>';

            $unit = trim((string) ($it['unit'] ?? ''));
            $oem = trim((string) ($it['oem'] ?? ''));
            $pbrand = trim((string) ($it['brand'] ?? ''));
            [$dispName, $spec] = $this->nameSpec((string) ($it['name'] ?? ''), $fitModels);

            // row 1 (above the name — mechanics find parts by number first): OEM chip + spec,
            // else spec alone, else the selling unit.
            $specTag = $spec !== '' ? '<span class="pspec">' . e($this->clipLine($spec, $oem !== '' ? 14 : 30)) . '</span>' : '';
            if ($oem !== '') {
                $line1 = '<span class="oem">OEM ' . e($this->clipLine($oem, 18)) . '</span>' . ($specTag !== '' ? ' ' . $specTag : '');
            } elseif ($specTag !== '') {
                $line1 = $specTag;
            } else {
                $line1 = $unit !== '' ? 'Sold per ' . e($unit) : '&nbsp;';
            }

            // fitment line: specific models when matchable, else brand family, else MOQ (FMCG)
            $fitLine = $this->fitsLineFor($it, $fitModels);
            if (! empty($it['moq'])) {
                $line2 = '<span style="color:' . self::C_GREY . ';font-weight:normal">Min order: ' . e((string) $it['moq']) . ' &times; ' . e($unit !== '' ? $unit : 'units') . '</span>';
            } elseif ($fitLine !== '') {
                $line2 = e($fitLine);
            } elseif ($pbrand !== '') {
                $line2 = '✓ Fits ' . e($this->clipLine($pbrand, 22)) . ' models';
            } else {
                $line2 = '&nbsp;';
            }

            if ($withPrices) {
                [$big, $small] = $this->moneyParts((float) $it['price'], $currency);
                $unitSuffix = ($unit !== '' && ! in_array(mb_strtolower($unit, 'UTF-8'), ['piece', 'pc', 'pcs', 'unit'], true))
                    ? ' <span class="prc">/ ' . e($this->clipLine($unit, 10)) . '</span>' : '';
                $cartonInner = $big . ($small !== '' ? ' <span class="prc">' . e($small) . '</span>' : '') . $unitSuffix;

                // Per-piece (loose) price — same gate & formula as the bot quote / storefront.
                $pack = (int) ($it['pack'] ?? 0);
                $isLoose = ! empty($ctx['loose']) && $pack > 1 && (float) $it['price'] > 0;
                if ($isLoose) {
                    $pp = \App\Services\Pricing::loosePrice((float) $it['price'], $pack, (float) ($ctx['loosePct'] ?? 20), \App\Services\Pricing::decimalsForCurrency($currency));
                    [$pbig, $psmall] = $this->moneyParts($pp, $currency);
                    $pcMain = $pbig . ($psmall !== '' ? ' <span class="prc">' . e($psmall) . '</span>' : '') . ' <span class="prc">/pc</span>';
                }

                if ($isLoose && ! empty($ctx['parts'])) {
                    // Parts shop: the piece price leads, carton shown small underneath.
                    $priceCell = '<div class="pfrom">PER PIECE</div><div class="pr">' . $pcMain . '</div>'
                        . '<div class="prc" style="color:' . self::C_GREY . '">Carton ' . $cartonInner . '</div>';
                } elseif ($isLoose) {
                    // Other verticals: carton leads, per-piece hint underneath.
                    $priceCell = '<div class="pfrom">FROM</div><div class="pr">' . $cartonInner . '</div>'
                        . '<div class="prc" style="color:' . self::C_GREY . '">or ' . $pcMain . '</div>';
                } else {
                    $priceCell = '<div class="pfrom">FROM</div><div class="pr">' . $cartonInner . '</div>';
                }
            } else {
                $priceCell = '<div class="pr" style="color:' . self::C_FAINT . ';font-size:10px">Ask for price</div>';
            }

            $cells[] = '<td class="cell">' . $photo
                . '<div class="pbody">'
                . '<div class="pu">' . $line1 . '</div>'
                . '<div class="m">' . $this->nameLines($dispName) . '</div>'
                . '<div class="pfits">' . $line2 . '</div>'
                . '<table class="prow"><tr><td>' . $priceCell . '</td>'
                . '<td class="sku">' . ($it['sku'] ? 'SKU ' . e((string) $it['sku']) : '') . '</td></tr></table>'
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
     * ellipsis if it can't fit two lines. Every card gets the same 30px name zone.
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

    /** Footer on every content page: validity + version left, WhatsApp + QR right. */
    private function pageFooter(string $company, string $phone, string $currency, string $dateFull,
        bool $withPrices, string $webText = '', string $webHref = '', string $version = '', string $qr = ''): string
    {
        $left = $company
            . ($withPrices ? ' · Prices in ' . e($currency) . ', valid as of ' . e($dateFull) . ' — subject to change' : ' · Product catalogue')
            . ($version !== '' ? ' · ' . e($version) : '');
        $parts = [];
        if ($phone !== '') {
            $parts[] = '<span class="pfootwa">WhatsApp ' . e($phone) . '</span>';
        }
        if ($webText !== '') {
            $parts[] = '<a href="' . e($webHref) . '" style="color:' . self::C_GREEN . ';text-decoration:none;font-weight:bold">' . e($webText) . '</a>';
        }
        $right = implode(' &nbsp;·&nbsp; ', $parts);
        $qrTd = $qr !== ''
            ? '<td style="width:34px;text-align:right;padding-left:8px"><img src="' . e($qr) . '" style="height:30px;width:30px"></td>'
            : '';

        return '<table class="pfoot"><tr>'
            . '<td>' . $left . '</td>'
            . '<td style="text-align:right">' . $right . '</td>'
            . $qrTd
            . '</tr></table>';
    }
}
