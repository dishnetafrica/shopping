<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Public customer storefront, one per tenant, served at mycloudbss.com/{shop}.
 * No auth: customers browse the tenant catalogue, build a cart, and place an
 * order that lands in the seller panel exactly like a bot/phone order
 * (OrderObserver assigns order_no + track_token and alerts the owner).
 */
class StorefrontController extends Controller
{
    /** Single-segment paths that must never be treated as a shop slug. */
    private const RESERVED = [
        'app', 'admin', 'panel', 'papi', 'api', 'storage', 'livewire',
        'build', 'vendor', 'up', 'login', 'logout', 'register',
    ];

    /** Resolve a shop slug to a tenant, set the tenant context, or 404. */
    private function tenant(string $shop): Tenant
    {
        $slug = strtolower(trim($shop));
        abort_if(in_array($slug, self::RESERVED, true), 404);

        $tenant = Tenant::where('slug', $slug)->first();
        abort_if(! $tenant, 404);
        abort_if(($tenant->status ?? 'active') === 'suspended', 404);

        app(TenantContext::class)->set($tenant->id);

        return $tenant;
    }

    /**
     * Resolve the shop from EITHER a slug (platform path /{slug}) OR the request Host (the tenant's
     * own custom domain, served at the root). Returns [tenant, base] where base is the path prefix
     * to build storefront URLs from: '/{slug}' on the platform, '' on a custom domain. Every URL the
     * storefront uses is base-relative, so the shop works identically at mycloudbss.com/shree and at
     * the tenant's own https://familyshoppers.net/.
     *
     * @return array{0:Tenant,1:string}
     */
    public function resolveShop(?string $shop): array      // public: TenantSiteController resolves the same way
    {
        if ($shop !== null && $shop !== '') {
            return [$this->tenant($shop), '/' . strtolower(trim($shop))];
        }
        $tenant = Tenant::byCustomDomain(request()->getHost());
        abort_if(! $tenant, 404);
        abort_if(($tenant->status ?? 'active') === 'suspended', 404);
        app(TenantContext::class)->set($tenant->id);
        return [$tenant, ''];
    }

    /** Root of a request. On a tenant's custom domain, that's their shop; otherwise the marketing site. */
    public function rootLanding()
    {
        $tenant = Tenant::byCustomDomain(request()->getHost());
        if ($tenant && ($tenant->status ?? 'active') !== 'suspended') {
            app(TenantContext::class)->set($tenant->id);
            if ($this->ispEnabled($tenant)) { return $this->isp($tenant, ''); }                     // ISP: no cart
            if ($this->technologyEnabled($tenant)) { return $this->technology('', $tenant, ''); }   // TECHNOLOGY
            if ($this->autoPartsEnabled($tenant)) { return $this->autoParts('', $tenant, ''); } // AUTO_PARTS
            return $this->brandSiteEnabled($tenant) ? $this->brand('', $tenant, '') : $this->renderShop($tenant, '');
        }
        return app(\App\Http\Controllers\Marketing\MarketingController::class)->home(request());
    }

    /** Make a stored image path into an absolute URL the browser can load. */
    /**
     * Category tile images: the uploaded per-tenant map wins; any category without an
     * uploaded image falls back to the first product image in that category — so tiles
     * never go blank, even if the uploaded map is ever empty or lost.
     */
    private function categoryImages($tenant, $rows): array
    {
        $explicit = (array) ($tenant->setting('category_images', []) ?: []);
        $fallback = [];
        foreach ($rows as $row) {
            $cat = (string) ($row['Category'] ?? 'Other');
            $img = (string) ($row['Image'] ?? '');
            if ($img !== '' && ! isset($fallback[$cat])) $fallback[$cat] = $img;
        }
        return $explicit + $fallback;   // uploaded keys take priority; fallback fills the gaps
    }

    private function imageUrl(?string $value): string
    {
        if (! $value) return '';
        // Already a usable URL or root-relative path (e.g. "/storage/..") — don't re-wrap.
        return Str::startsWith($value, ['http://', 'https://', '/'])
            ? $value
            : Storage::disk('public')->url($value);
    }

    /** v4.67 — wholesale home config (null = classic home). Hero image paths become URLs here. */
    private function wholesaleHome(Tenant $tenant): ?array
    {
        $home = \App\Services\Storefront\WholesaleHome::build(
            (array) ($tenant->settings ?? []),
            (string) $tenant->setting('city', '')
        );
        if ($home === null) {
            return null;
        }
        foreach (['img', 'imgM'] as $k) {
            if (($home['hero'][$k] ?? '') !== '') {
                $home['hero'][$k] = $this->imageUrl($home['hero'][$k]);
            }
        }
        return $home;
    }

    private function currency(Tenant $tenant): string
    {
        $c = (string) $tenant->setting('currency', 'UGX');
        return ($c === '' || strtolower($c) === 'auto') ? 'UGX' : strtoupper($c);
    }

    /** The shop page itself. */
    public function show(?string $shop = null)
    {
        [$tenant, $base] = $this->resolveShop($shop);
        if ($this->travelEnabled($tenant)) { return $this->travel($tenant, $base); }   // TRAVEL: no retail shop to show
        if ($this->ispEnabled($tenant)) { return $this->isp($tenant, $base); }         // ISP: plans + enquiry, no cart
        return $this->renderShop($tenant, $base);
    }

    /**
     * v4.27 — REAL, indexable URLs for the shop (the # is gone).
     *
     * GET /p/{slug}-{id} — a product's own page. The id at the end is authoritative; the slug in
     * front is decoration for Google and humans, so a stale slug still resolves and the canonical
     * always points at the current one. Serves the SAME shop shell with per-product title,
     * description, canonical, og:image (the product photo, so a WhatsApp share previews the item)
     * and Product JSON-LD — then tells the page to open that product.
     */
    public function productPage(?string $shop = null, ?string $slugId = null)
    {
        // On a custom domain the route has one segment; on the platform it is /{shop}/p/{slugId}.
        if ($slugId === null) { $slugId = (string) $shop; $shop = null; }
        [$tenant, $base] = $this->resolveShop($shop);
        if ($this->ispEnabled($tenant)) {                       // ISP: no product pages with a basket — the plans page
            return redirect($base . '/shop#plans', 301);
        }

        // trailing digits are the id — "sugar-2kg-kakira-412" → 412
        $id = preg_match('/(\d+)$/', (string) $slugId, $m) ? (int) $m[1] : 0;
        $product = $id > 0
            ? Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->find($id)
            : null;

        return $this->renderShop($tenant, $base, null, $product, $id);
    }

    /** The SEO slug for a product URL — id-suffixed so lookups never depend on the words. */
    public static function productSlug($product): string
    {
        $name = \Illuminate\Support\Str::slug((string) ($product->name ?? ''));
        $name = $name !== '' ? \Illuminate\Support\Str::limit($name, 60, '') : 'item';

        return trim($name, '-') . '-' . (int) $product->id;
    }

    /**
     * A customer-supplied map link is stored in the order/quote location and later rendered as a
     * clickable <a href> in the seller panel. Accept ONLY http(s) or geo: URLs — a javascript:
     * or data: scheme would become a click-trap for staff (the panel escapes HTML but not the URL
     * scheme). Returns '' when the value is missing or not a safe map link.
     */
    private static function safeMapUrl(string $raw): string
    {
        $raw = trim($raw);
        return preg_match('~^(https?://|geo:)~i', $raw) === 1 ? $raw : '';
    }

    /** Render the shop HTML for a resolved tenant. $base is '' on a custom domain, '/{slug}' on the platform. */
    private function renderShop(Tenant $tenant, string $base, ?string $seoCat = null, $seoProduct = null, int $wantedId = 0, array $chat = [])
    {

        $cfg = [
            'name'         => (string) $tenant->name,
            'slug'         => (string) $tenant->slug,
            'base'         => $base,
            'initials'     => $this->initials((string) $tenant->name),
            'currency'     => $this->currency($tenant),
            // Loose / per-piece retail selling. When on, the storefront's Single-unit view lets a
            // shopper order carton-packed items by the piece at carton-price ÷ pack × (1 + markup);
            // the server (repriceCart) is the price authority. Off for every shop that hasn't enabled it.
            'looseEnabled'   => (bool) $tenant->setting('loose_enabled', false),
            'looseMarkupPct' => (float) $tenant->setting('loose_markup_pct', 20),
            'logo'         => $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '',
            'waNumber'     => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
            'city'         => (string) $tenant->setting('city', ''),
            'catalogueUrl' => $base . '/catalogue',
            'orderUrl'     => $base . '/order',
            'quoteUrl'     => $base . '/quote',
            'panelUrl'     => url('/panel'),
            'branches'     => $this->branches($tenant),
            'delivery'     => (object) ($tenant->setting('delivery', []) ?: []),
            'vertical'     => \App\Support\Vertical::of($tenant),
            'combos'       => \App\Support\Combos::forTenant($tenant),
            'theme'        => $this->resolveTheme($tenant),
            // v4.67 (ADR-017 M1) — the wholesale home, as data. Null for every tenant that has not
            // set `storefront_home = wholesale`, and the SPA renders the classic home on null, so
            // this line is invisible to the other twelve shops. All copy comes from settings via
            // the pure builder; only the image paths need the controller's storage knowledge.
            'home'         => $this->wholesaleHome($tenant),
            // v4.79 (M-CHAT-2) — the chat UI renders only when the owner switched the channel on.
            'webChat'      => (bool) $tenant->setting('web_chat', false),
            'webChatContact' => (bool) $tenant->setting('web_chat_contact', true),   // v4.85 — contact-first gate; server enforces regardless
            // v4.93 — /chat mode. Absent (false) on every ordinary storefront render, so the normal
            // shop is byte-for-byte unaffected by this feature existing.
            'chatOnly'     => (bool) ($chat['chatOnly'] ?? false),
            'chatCtx'      => [
                'source'   => (string) ($chat['source'] ?? ''),
                'campaign' => (string) ($chat['campaign'] ?? ''),
                'category' => (string) ($chat['category'] ?? ''),
                'product'  => (string) ($chat['product'] ?? ''),
            ],
        ];

        $path = resource_path('storefront/shop.html');
        $html = is_file($path) ? file_get_contents($path) : '<h1>Shop unavailable</h1>';

        // Server-side identity injection (2026-07-18): WhatsApp/Facebook link previews
        // and non-JS crawlers read the STATIC html — so the real shop name must be in
        // the bytes, not only in the JS config. Placeholders are replaced exactly once.
        $safeName = e((string) $tenant->name);
        $html = str_replace('<title>Shop</title>', '<title>'.$safeName.' — order on WhatsApp</title>', $html);
        $html = str_replace('<div class="lg">SH</div>', '<div class="lg">'.e($cfg['initials']).'</div>', $html);
        $html = str_replace('<div><b>Shop</b><span class="sub"', '<div><b>'.$safeName.'</b><span class="sub"', $html);
        $html = str_replace('<span id="footName">Shop</span>', '<span id="footName">'.$safeName.'</span>', $html);
        // v4.27.1: skip the shop-wide tags on a product page. This block injects AFTER
        // </title>, and the per-product block below replaces the <title> element itself —
        // so these would survive underneath the product's own og:* and shadow them
        // (parsers that take the last occurrence showed the shop name, not the product).
        if (! str_contains($html, 'og:title') && ! $seoProduct) {
            $html = str_replace('</title>', '</title>'."\n".'<meta property="og:title" content="'.$safeName.'">'
                ."\n".'<meta property="og:description" content="Browse the catalogue and order on WhatsApp — '.$safeName.'">'
                .($cfg['logo'] ? "\n".'<meta property="og:image" content="'.e($cfg['logo']).'">' : ''), $html);
        }
        $html = str_replace('__SHOP_CONFIG__', json_encode($cfg, JSON_UNESCAPED_UNICODE), $html);

        // ---- v4.27 per-URL SEO: a product page is a real page, not a modal behind a # ----
        // Injected into <head> so crawlers and link previews read it in the bytes. The shell is
        // identical either way; only the metadata and the "open this product" flag differ.
        $seoHead = '';
        if ($wantedId > 0 && ! $seoProduct) {
            // asked for a product that is gone or not ours → a real 404, never a soft one
            $seoHead = '<meta name="robots" content="noindex,nofollow">'
                . '<title>' . e('Product not found — ' . $tenant->name) . '</title>';
            $html = str_replace('<title>' . $safeName . ' — order on WhatsApp</title>', $seoHead, $html);

            return response($html, 404)->header('Content-Type', 'text/html; charset=UTF-8');
        }
        if ($seoProduct) {
            $cur   = $this->currency($tenant);
            $price = (float) ($seoProduct->price ?? 0);
            $img   = (string) ($seoProduct->image_url ?? '') !== '' ? $this->imageUrl((string) $seoProduct->image_url) : '';
            // v4.27.1: a product with no photo still deserves *a* preview image, which the
            // shop-wide block used to supply. JSON-LD keeps the real photo only (never the
            // logo — that would be a false product image to Google); this is the OG fallback.
            $ogImg = $img !== '' ? $img : (string) $cfg['logo'];
            $canonUrl = request()->getSchemeAndHttpHost() . $base . '/p/' . self::productSlug($seoProduct);
            $pTitle = trim((string) $seoProduct->name) . ' — buy in ' . ((string) $tenant->setting('city', '') ?: 'Juba') . ' | ' . $tenant->name;
            // v4.28 — "how is it packed" belongs in the generated description: it is what a
            // wholesale buyer scanning search results needs, and it is the difference between
            // a listing that reads as retail and one that reads as trade.
            $uSize = trim((string) ($seoProduct->unit_size ?? ''));
            $pk    = (int) ($seoProduct->pack_size ?? 0);
            $uLab  = trim((string) ($seoProduct->unit_label ?? ''));
            $packBit = '';
            if ($pk > 1) {
                $packBit = '. Sold by ' . ($uLab !== '' ? mb_strtolower($uLab) : 'pack')
                    . ' of ' . $pk . ($uSize !== '' ? ' × ' . $uSize : '');
            } elseif ($uSize !== '') {
                $packBit = '. ' . $uSize;
            }
            $pDesc  = trim((string) ($seoProduct->description ?? '')) !== ''
                ? \Illuminate\Support\Str::limit(trim((string) $seoProduct->description), 150)
                : trim((string) $seoProduct->name)
                    . ((string) ($seoProduct->category ?? '') !== '' ? ' — ' . $seoProduct->category : '')
                    . $packBit
                    . ($price > 0 ? '. Price ' . $cur . ' ' . number_format($price) : '')
                    . '. Order on WhatsApp from ' . $tenant->name . '.';

            $ld = array_filter([
                '@context' => 'https://schema.org', '@type' => 'Product',
                'name'        => (string) $seoProduct->name,
                'description' => $pDesc,
                'image'       => $img ?: null,
                'sku'         => (string) ($seoProduct->sku ?? '') !== '' ? (string) $seoProduct->sku : null,
                'brand'       => (string) ($seoProduct->brand ?? '') !== ''
                    ? ['@type' => 'Brand', 'name' => (string) $seoProduct->brand] : null,
                'category'    => (string) ($seoProduct->category ?? '') ?: null,
                'size'            => $uSize ?: null,                                       // v4.28
                'countryOfOrigin' => trim((string) ($seoProduct->origin_country ?? '')) ?: null,
                'offers'      => $price > 0 ? [
                    '@type' => 'Offer', 'price' => (string) $price, 'priceCurrency' => $cur,
                    // v5.05 — do not tell Google a product is out of stock on the strength of a
                    // column no sale decrements (audit P0-2). Stated only when the shop has turned
                    // stock display on; otherwise InStock, which is what an order-to-confirm
                    // wholesale shop actually means.
                    'availability' => 'https://schema.org/'
                        . (((bool) $tenant->setting('storefront_show_stock', false) === false
                            || $seoProduct->stock === null || (int) $seoProduct->stock > 0) ? 'InStock' : 'OutOfStock'),
                    'url' => $canonUrl,
                    'seller' => ['@type' => 'Organization', 'name' => (string) $tenant->name],
                ] : null,
            ]);

            $jf = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;  // HEX_TAG: encode </script> so a product field can't break out of the ld+json script (stored XSS)
            $seoHead = implode("\n", array_filter([
                '<title>' . e($pTitle) . '</title>',
                '<meta name="description" content="' . e($pDesc) . '">',
                '<link rel="canonical" href="' . e($canonUrl) . '">',
                '<meta property="og:type" content="product">',
                '<meta property="og:title" content="' . e((string) $seoProduct->name) . '">',
                '<meta property="og:description" content="' . e($pDesc) . '">',
                '<meta property="og:url" content="' . e($canonUrl) . '">',
                $ogImg !== '' ? '<meta property="og:image" content="' . e($ogImg) . '">' : null,
                '<meta name="twitter:card" content="' . ($img !== '' ? 'summary_large_image' : 'summary') . '">',
                '<script type="application/ld+json">' . json_encode($ld, $jf) . '</script>',
            ]));
            // replace the generic title outright so there is only ONE title element
            $html = str_replace('<title>' . $safeName . ' — order on WhatsApp</title>', $seoHead, $html);
            $html = str_replace('__OPEN_PRODUCT__', (string) (int) $seoProduct->id, $html);
        }
        // no product on this URL → the placeholder must still disappear
        $html = str_replace('__OPEN_PRODUCT__', '0', $html);

        // Solar Experience Center CTA — a top strip on solar shops linking to the guided assessment.
        if (\App\Support\Vertical::of($tenant) === \App\Support\Vertical::SOLAR) {
            // Themed from the shop's palette (var --green-d/--green, set by the SPA applyTheme) with the
            // original amber as the fallback, so this solar-only strip follows each solar shop's colours.
            $cta = '<a href="' . e($base) . '/solar" style="display:block;background:linear-gradient(135deg,var(--green-d,#0A6E1A),var(--green,#0C831F));'
                . 'color:#fff;text-align:center;padding:11px 14px;font-weight:700;text-decoration:none;'
                . 'font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px">'
                . '☀️ Design your solar system in 3 minutes — Start my free assessment →</a>';
            $html = str_replace('<body>', '<body>' . $cta, $html);
        }

        // ---- Premium dynamic footer (2026-07-19): trust + navigation + SEO, server-rendered
        //      from the live catalogue. Only links that actually resolve are generated. ----
        // Anchor on the div alone (2026-07-19): the identity injection above already
        // rewrote footName "Shop" -> tenant name, so any anchor containing "Shop" is dead.
        $html = str_replace(
            '<div id="footer">',
            $this->premiumFooter($tenant, $base).'<div id="footer" style="display:none">',
            $html
        );

        // ---- SEO category landing (/c/{slug}): unique title/meta/canonical for crawlers,
        //      and a one-line hop into the aisle for humans. ----
        if ($seoCat !== null) {
            $safeCat = e($seoCat);
            $tName = e((string) $tenant->name);
            $html = preg_replace('/<title>[^<]*<\/title>/',
                '<title>'.$safeCat.' — buy wholesale &amp; retail | '.$tName.'</title>', $html, 1);
            $html = str_replace('</title>',
                '</title>'."\n".'<meta name="description" content="'.$safeCat.' at wholesale and retail prices from '.$tName.'. Order on WhatsApp, delivery in Juba &amp; Kampala.">'
                ."\n".'<link rel="canonical" href="'.e(url()->current()).'">', $html);
            // ---- ItemList + BreadcrumbList JSON-LD so AI search (ChatGPT/Perplexity/Google AI) and
            //      rich results can read the WHOLE aisle from the raw HTML — the visible grid is drawn
            //      in JS, so this is what a non-JS crawler sees. Best-effort: never break the page. ----
            try {
                $cur      = $this->currency($tenant);
                $host     = request()->getSchemeAndHttpHost();
                $showStock = (bool) $tenant->setting('storefront_show_stock', false);
                $catProducts = \App\Models\Product::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)->where('active', true)
                    ->where('category', $seoCat)
                    ->orderByDesc('id')->limit(200)->get();
                $items = [];
                $nos   = [];   // visible-to-crawlers <noscript> list, built from the same loop
                foreach ($catProducts as $p) {
                    if ($p->hidden ?? false) continue;
                    $purl   = $host.$base.'/p/'.self::productSlug($p);
                    $pprice = (float) ($p->price ?? 0);
                    $pimg   = (string) ($p->image_url ?? '') !== '' ? $this->imageUrl((string) $p->image_url) : '';
                    $prod = array_filter([
                        '@type' => 'Product',
                        'name'  => (string) $p->name,
                        'url'   => $purl,
                        'image' => $pimg ?: null,
                        'sku'   => (string) ($p->sku ?? '') !== '' ? (string) $p->sku : null,
                        'brand' => (string) ($p->brand ?? '') !== '' ? ['@type' => 'Brand', 'name' => (string) $p->brand] : null,
                        'offers' => $pprice > 0 ? [
                            '@type' => 'Offer', 'price' => (string) $pprice, 'priceCurrency' => $cur,
                            'availability' => 'https://schema.org/'
                                . (($showStock === false || $p->stock === null || (int) $p->stock > 0) ? 'InStock' : 'OutOfStock'),
                            'url' => $purl,
                            'seller' => ['@type' => 'Organization', 'name' => (string) $tenant->name],
                        ] : null,
                    ]);
                    $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'item' => $prod];
                    $nos[]   = '<li><a href="'.e($purl).'">'.e((string) $p->name).'</a>'
                        .($pprice > 0 ? ' — '.e($cur.' '.number_format($pprice, $pprice == floor($pprice) ? 0 : 2)) : '').'</li>';
                }
                if ($items) {
                    $jf = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;  // HEX_TAG: encode </script> so a product field can't break out of the ld+json script (stored XSS)
                    $itemList = ['@context' => 'https://schema.org', '@type' => 'ItemList',
                        'name' => $seoCat, 'numberOfItems' => count($items), 'itemListElement' => $items];
                    $crumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $host.($base ?: '/')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $seoCat, 'item' => url()->current()],
                    ]];
                    $html = str_replace('</body>',
                        '<script type="application/ld+json">'.json_encode($itemList, $jf).'</script>'
                        ."\n".'<script type="application/ld+json">'.json_encode($crumbs, $jf).'</script>'."\n".'</body>', $html);
                    // Visible-to-crawlers fallback for bots that don't run JS or parse JSON-LD: the SPA
                    // paints the grid in JS, so without this a text-only crawler reads an empty aisle.
                    // <noscript> is inert for real shoppers (the SPA requires JS), so zero visual impact.
                    $html = str_replace('</body>',
                        '<noscript><section aria-label="'.$safeCat.'"><h1>'.$safeCat.'</h1><ul>'.implode('', $nos).'</ul>'
                        .'<p>Order on WhatsApp from '.e((string) $tenant->name).'.</p></section></noscript>'."\n".'</body>', $html);
                }
            } catch (\Throwable $e) { /* SEO enrichment is best-effort; a query hiccup must not 500 the aisle */ }
            $html = str_replace('</body>',
                '<script>if(!location.search){location.replace("'.($base ?: '').'/?cat='.rawurlencode($seoCat).'");}</script></body>', $html);
        }

        $html = str_replace('</body>', $this->poweredByBadge($tenant) . '</body>', $html);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Tenant landing at /{shop}. Manufacturers get the brand site; everyone else gets the shop.
     * The shop itself always lives at /{shop}/shop, so brand-site order links point there.
     */
    public function landing(?string $shop = null)
    {
        [$tenant, $base] = $this->resolveShop($shop);
        if ($this->travelEnabled($tenant)) { return $this->travel($tenant, $base); }                   // TRAVEL
        if ($this->ispEnabled($tenant)) { return $this->isp($tenant, $base); }                         // ISP (own home page: custom-domain root only)
        if ($this->technologyEnabled($tenant)) { return $this->technology($shop, $tenant, $base); }   // TECHNOLOGY
        if ($this->autoPartsEnabled($tenant)) { return $this->autoParts($shop, $tenant, $base); } // AUTO_PARTS
        return $this->brandSiteEnabled($tenant) ? $this->brand($shop, $tenant, $base) : $this->renderShop($tenant, $base);
    }

    /**
     * v4.93 — /chat : the SAME storefront page, opened straight into the shopping conversation with
     * the website chrome hidden. Deliberately not a separate view.
     *
     * The chat's product cards read prices through prod(), its Add button is the storefront's own
     * cartAdd(), and the cart it fills is the page's CART. A standalone chat page would have none of
     * those, so Add to Cart would look fine and quietly do nothing. Serving the same page keeps every
     * one of those paths intact and makes this reversible by deleting one route.
     */
    public function chatLanding(Request $request, ?string $shop = null)
    {
        [$tenant, $base] = $this->resolveShop($shop);
        if ($this->travelEnabled($tenant)) {                                                            // TRAVEL
            return $this->travel($tenant, $base, [
                'openChat' => true,
                'source'   => $this->ctxWord($request->query('source', $request->query('utm_source', ''))),
                'campaign' => $this->ctxWord($request->query('campaign', $request->query('utm_campaign', ''))),
            ]);
        }

        if ($this->ispEnabled($tenant)) {                                                               // ISP
            return $this->isp($tenant, $base, [
                'openChat' => true,
                'source'   => $this->ctxWord($request->query('source', $request->query('utm_source', ''))),
                'campaign' => $this->ctxWord($request->query('campaign', $request->query('utm_campaign', ''))),
            ]);
        }

        return $this->renderShop($tenant, $base, null, null, 0, [
            'chatOnly' => true,
            // Campaign context, sanitised HERE. The page never invents these and the model is never
            // handed a raw query string.
            'source'   => $this->ctxWord($request->query('source', $request->query('utm_source', ''))),
            'campaign' => $this->ctxWord($request->query('campaign', $request->query('utm_campaign', ''))),
            'category' => $this->ctxWord($request->query('category', '')),
            'product'  => $this->ctxWord($request->query('product', '')),
        ]);
    }

    /** Campaign words are attribution, not content: lowercase, short, and nothing exotic. */
    private function ctxWord($v): string
    {
        $v = strtolower(trim((string) $v));
        $v = (string) preg_replace('/[^a-z0-9._-]+/', '-', $v);

        return mb_substr(trim($v, '-'), 0, 48);
    }

    private function brandSiteEnabled($tenant): bool
    {
        $flag = $tenant->setting('brand_site', null);
        if ($flag !== null && $flag !== '') return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        return \App\Support\Vertical::of($tenant) === \App\Support\Vertical::MANUFACTURER;
    }

    // >>> AUTO_PARTS: auto-parts tenants get the EPC storefront at /{shop}; the shop (cart) stays at /{shop}/shop
    private function autoPartsEnabled($tenant): bool
    {
        $flag = $tenant->setting('auto_parts_site', null);
        if ($flag !== null && $flag !== '') return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        return \App\Support\Vertical::of($tenant) === \App\Support\Vertical::AUTO_PARTS;
    }
    // <<< AUTO_PARTS

    // >>> TECHNOLOGY
    /**
     * Is this tenant on the technology storefront?
     *
     * Mirrors brandSiteEnabled()/autoPartsEnabled() exactly: an explicit `technology_site`
     * setting wins, otherwise it follows the tenant's vertical. Both halves are false for
     * every tenant that has not opted in, so no existing shop's render can change — a
     * grocery/restaurant/manufacturer/solar/auto-parts tenant never reaches this template.
     */
    /** A travel agency's website is an enquiry page: it has no catalogue, cart or checkout to show. */
    private function travelEnabled($tenant): bool
    {
        return \App\Support\Vertical::of($tenant) === \App\Support\Vertical::TRAVEL;
    }

    /**
     * Travel agency storefront (issue #145): what the agency offers and a chat that takes the
     * enquiry through the same brain as WhatsApp. Nothing here is a price, a product or a cart.
     */
    private function travel($tenant, string $base, array $chat = [])
    {
        $theme    = $this->resolveTheme($tenant);
        $services = array_values(array_filter(array_map(
            fn ($s) => mb_substr(trim((string) $s), 0, 40),
            preg_split('/[,;\n]+/', (string) $tenant->setting('travel_services', '')) ?: []
        )));
        $cfg = [
            'name'           => (string) $tenant->name,
            'slug'           => (string) $tenant->slug,
            'base'           => $base,
            'initials'       => $this->initials((string) $tenant->name),
            'logo'           => $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '',
            'accent'         => (string) ($theme['accent'] ?? ''),
            'accentDark'     => (string) ($theme['accentDark'] ?? ''),
            'services'       => array_slice($services, 0, 12),
            'note'           => mb_substr(trim((string) $tenant->setting('travel_profile_note', '')), 0, 900),
            'waNumber'       => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
            'webChat'        => (bool) $tenant->setting('web_chat', false),
            'webChatContact' => (bool) $tenant->setting('web_chat_contact', true),
            'openChat'       => (bool) ($chat['openChat'] ?? false),
            'ctx'            => ['source' => (string) ($chat['source'] ?? ''), 'campaign' => (string) ($chat['campaign'] ?? '')],
        ];

        $path  = resource_path('storefront/travel.html');
        $html  = is_file($path) ? file_get_contents($path) : '<h1>Unavailable</h1>';
        $name  = e((string) $tenant->name);
        $url   = $base === '' ? url('/') : url($base);
        $desc  = e((string) $tenant->name . ' — tell us about your trip and our travel team will prepare a quotation.');
        $meta  = '<meta name="description" content="' . $desc . '">' . "\n"
               . '<link rel="canonical" href="' . e($url) . '">' . "\n"
               . '<meta property="og:title" content="' . $name . '">' . "\n"
               . '<meta property="og:description" content="' . $desc . '">';
        // HEX_TAG so a tenant field cannot break out of the <script>; INVALID_UTF8_SUBSTITUTE so a
        // pasted stray byte cannot make json_encode() return false and blank the page.
        $jf    = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
        $html  = str_replace(
            ['__TITLE__', '__META__', '__NAME__', '__TRAVEL_CFG__'],
            [$name, $meta, $name, (string) json_encode($cfg, $jf)],
            $html
        );

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    /** An internet provider sells a service through a salesperson: plans, coverage and an enquiry — never a cart. */
    private function ispEnabled($tenant): bool
    {
        return \App\Support\Vertical::of($tenant) === \App\Support\Vertical::ISP;
    }

    /**
     * Internet-provider website (resources/storefront/isp.html), styled after DishNet's own site. Everything
     * on it is APPROVED data only: prices from the switched-on catalogue (monthly vs one-time), coverage
     * from the PUBLISHED Sales Brain areas marked covered, and a chat that runs the same governed brain as
     * WhatsApp. No cart, no checkout: the customer's salesperson prepares the quotation.
     */
    private function isp($tenant, string $base, array $chat = [])
    {
        $cur   = $this->currency($tenant);
        $dec   = \App\Services\Pricing::decimalsForCurrency($cur);
        $hidden = \App\Services\Catalog\Visibility::hiddenCategories($tenant);

        $plans = [];
        foreach (Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereNull('archived_at')
            ->orderBy('display_order')->orderBy('price')->limit(60)->get() as $p) {
            if (\App\Services\Catalog\Visibility::isHidden((string) ($p->category ?? ''), $p->hidden ?? null, $hidden)) {
                continue;
            }
            $price = (float) ($p->price ?? $p->base_price ?? 0);
            $plans[] = [
                'name'    => (string) $p->name,
                'price'   => $price > 0 ? (floor($price) == $price ? (int) $price : round($price, $dec)) : null,   // null → "price from our team"
                'billing' => in_array($p->billing, ['monthly', 'one_off'], true) ? $p->billing : '',
                'line'    => mb_strtolower((string) ($p->service_line ?? '')),
                'group'   => mb_substr((string) ($p->category ?? ''), 0, 40),
                'desc'    => mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($p->description ?? ''))), 0, 180),
                'image'   => $p->image_url ? $this->imageUrl((string) $p->image_url) : '',
            ];
        }

        $covered = [];
        try {
            foreach (app(\App\Services\Sales\Brain\SalesBrain::class)->published($tenant, 'coverage') as $entry) {
                foreach ((array) (($entry['data'] ?? [])['areas'] ?? []) as $a) {
                    if (mb_strtolower((string) ($a['status'] ?? '')) === 'covered' && trim((string) ($a['name'] ?? '')) !== '') {
                        $covered[] = ['name' => mb_substr(trim((string) $a['name']), 0, 60), 'line' => mb_strtolower((string) ($a['service_line'] ?? ''))];
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);                                                        // a website never fails on coverage
        }

        // The business's own website pages (site bundle), when it has them — only pages served as-is.
        $pages = [];
        if ($dir = TenantSiteController::bundleDir($tenant)) {
            $hold = (array) (TenantSiteController::manifest($dir)['hold'] ?? []);
            if (($home = TenantSiteController::homeFile($tenant)) !== null) {
                $pages[] = ['href' => $base === '' ? '/' : $base . '/' . $home, 'label' => 'Home'];
            }
            foreach (['about.html' => 'About', 'tutorials/index.html' => 'Help & tutorials', 'guides.html' => 'Guides', 'blog.html' => 'Blog',
                'gallery.html' => 'Gallery', 'get-the-app.html' => 'Get the app', 'careers.html' => 'Careers', 'contact.html' => 'Contact',
                'privacy.html' => 'Privacy'] as $file => $label) {
                if (is_file($dir . '/' . $file) && ! array_key_exists($file, $hold)) {
                    $pages[] = ['href' => ($base === '' ? '/' : $base . '/') . $file, 'label' => $label];
                }
            }
        }

        $cfg = [
            'name'           => (string) $tenant->name,
            'slug'           => (string) $tenant->slug,
            'base'           => $base,
            'pages'          => $pages,
            'initials'       => $this->initials((string) $tenant->name),
            'logo'           => $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '',
            // The business's own colours when it set them; otherwise the internet-provider look (DishNet red).
            'accent'         => (string) ($tenant->setting('theme_accent', '') ?: '#C8102E'),
            'accentDark'     => (string) ($tenant->setting('theme_accent_dark', '') ?: ($tenant->setting('theme_accent', '') ? '' : '#A00D24')),
            'tagline'        => mb_substr(trim((string) $tenant->setting('isp_tagline', '')), 0, 140),
            'where'          => trim((string) $tenant->setting('city', '') . ((string) $tenant->setting('city', '') !== '' && (string) $tenant->setting('country', '') !== '' ? ', ' : '') . (string) $tenant->setting('country', '')),
            'currency'       => $cur,
            'decimals'       => $dec,
            'plans'          => $plans,
            'covered'        => array_slice($covered, 0, 200),
            'waNumber'       => preg_replace('/[^0-9]/', '', (string) ($tenant->setting('public_phone', '') ?: ($tenant->whatsapp_number ?? ''))),
            'webChat'        => (bool) $tenant->setting('web_chat', false),
            'webChatContact' => (bool) $tenant->setting('web_chat_contact', true),
            'openChat'       => (bool) ($chat['openChat'] ?? false),
            'ctx'            => ['source' => (string) ($chat['source'] ?? ''), 'campaign' => (string) ($chat['campaign'] ?? '')],
        ];

        $path = resource_path('storefront/isp.html');
        $html = is_file($path) ? file_get_contents($path) : '<h1>Unavailable</h1>';
        $name = e((string) $tenant->name);
        $url  = $base === '' ? url('/') : url($base);
        $desc = e((string) $tenant->name . ' — internet plans for home and business. Ask our team for a quotation.');
        $meta = '<meta name="description" content="' . $desc . '">' . "\n"
              . '<link rel="canonical" href="' . e($url) . '">' . "\n"
              . '<meta property="og:title" content="' . $name . '">' . "\n"
              . '<meta property="og:description" content="' . $desc . '">';
        $jf   = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
        $html = str_replace(['__TITLE__', '__META__', '__NAME__', '__ISP_CFG__'], [$name, $meta, $name, (string) json_encode($cfg, $jf)], $html);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    private function technologyEnabled($tenant): bool
    {
        $flag = $tenant->setting('technology_site', null);
        if ($flag !== null && $flag !== '') return filter_var($flag, FILTER_VALIDATE_BOOLEAN);

        return \App\Support\Vertical::of($tenant) === \App\Support\Vertical::TECHNOLOGY;
    }

    /**
     * Technology storefront — software, IT hardware, cybersecurity and networking.
     *
     * Reuses the existing machinery wholesale: the catalogue feed (/catalogue), the order
     * endpoint (/order), the quote endpoint (/quote), tenant resolution, FX/currency, the
     * product-visibility scope and the SEO helpers. The only new thing is presentation,
     * and every part of it is resolved from tenant settings by App\Support\TechTheme —
     * no tenant name, colour, logo, phone number, category or brand is hardcoded here.
     */
    public function technology(?string $shop = null, $tenant = null, ?string $base = null)
    {
        if ($tenant === null) { [$tenant, $base] = $this->resolveShop($shop); }
        if ($base === null)   { $base = '/' . (string) $tenant->slug; }

        $settings = is_array($tenant->settings) ? $tenant->settings : [];

        $cfg = \App\Support\TechTheme::build($settings, [
            'name'         => (string) $tenant->name,
            'initials'     => $this->initials((string) $tenant->name),
            'base'         => $base,
            'currency'     => $this->currency($tenant),
            'waNumber'     => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
            'logo'         => $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '',
            'favicon'      => $tenant->setting('favicon', '') ? $this->imageUrl((string) $tenant->setting('favicon', '')) : '',
            'catalogueUrl' => $base . '/catalogue',
            'orderUrl'     => $base . '/order',
            'quoteUrl'     => $base . '/quote',
            'shopUrl'      => $base . '/shop',
            'url'          => $base === '' ? url('/') : url($base),
        ]);

        // ---- SEO: server-rendered so crawlers and link previews read real bytes ----
        $seo   = \App\Support\TechTheme::seo($settings, (string) $tenant->name);
        $title = $seo['title'];
        $desc  = $seo['description'];
        $url   = $base === '' ? url('/') : url($base);
        $logo  = $cfg['logo'];

        $org = array_filter([
            '@context' => 'https://schema.org', '@type' => 'Organization',
            'name' => (string) $tenant->name, 'url' => $url, 'logo' => $logo ?: null,
            'telephone' => $cfg['contact']['phones'][0] ?? null,
            'email'     => $cfg['contact']['emails'][0] ?? null,
            'address'   => $cfg['contact']['address']
                ? ['@type' => 'PostalAddress', 'streetAddress' => $cfg['contact']['address']] : null,
        ]);

        // HEX_TAG: a tenant field can't break out of the <script>. INVALID_UTF8_SUBSTITUTE:
        // a panel-pasted value with a stray non-UTF-8 byte (Word/Excel/PDF paste, half an emoji)
        // must not make json_encode() return false — that injects `var CFG = ;` and white-screens
        // the whole storefront. Substitute U+FFFD and keep valid JSON instead.
        $jf = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE;

        $metaParts = [
            '<meta name="description" content="' . e($desc) . '">',
            '<meta name="robots" content="index,follow">',
            '<link rel="canonical" href="' . e($url) . '">',
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="' . e((string) $tenant->name) . '">',
            '<meta property="og:title" content="' . e($title) . '">',
            '<meta property="og:description" content="' . e($desc) . '">',
            '<meta property="og:url" content="' . e($url) . '">',
            $logo !== '' ? '<meta property="og:image" content="' . e($logo) . '">' : '',
            '<meta name="twitter:card" content="summary_large_image">',
            '<script type="application/ld+json">' . json_encode($org, $jf) . '</script>',
        ];
        if ($cfg['faq']) {
            $metaParts[] = '<script type="application/ld+json">' . json_encode([
                '@context' => 'https://schema.org', '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($f) => [
                    '@type' => 'Question', 'name' => $f['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
                ], $cfg['faq']),
            ], $jf) . '</script>';
        }
        $meta = implode("\n", array_filter($metaParts));

        $path = resource_path('storefront/technology.html');
        $html = is_file($path) ? file_get_contents($path) : '<h1>Storefront unavailable</h1>';

        $html = str_replace('__SEO_TITLE__',   e($title), $html);
        $html = str_replace('__SEO_META__',    $meta, $html);
        $html = str_replace('__TECH_CONFIG__', json_encode($cfg, $jf), $html);
        $html = str_replace('__OPEN_PRODUCT__', '0', $html);
        $html = str_replace('</body>', $this->poweredByBadge($tenant) . '</body>', $html);

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Growth loop — a small "Powered by ShopBot" pill injected bottom-right of every FREE
     * tenant's public storefront, linking to signup with attribution (so every free shop
     * quietly markets the platform). Paid plans (starter/pro) drop it — a standard SaaS
     * free-tier badge — and a tenant can force-hide it with the `hide_powered_badge` setting.
     * Pure HTML + CSS, no scripts. Signup URL is configurable via services.shopbot.signup_url.
     */
    private function poweredByBadge($tenant): string
    {
        if ($tenant === null || $tenant->effectivePlan() !== 'free') return '';
        if ((bool) $tenant->setting('hide_powered_badge', false)) return '';
        $base = rtrim((string) (config('services.shopbot.signup_url') ?: 'https://mycloudbss.com'), '/');
        $href = e($base . '?utm_source=shopbot_badge&utm_medium=owned&utm_campaign=storefront&utm_content=' . rawurlencode((string) $tenant->slug));

        return '<a href="' . $href . '" target="_blank" rel="noopener" class="sbot-badge" aria-label="Powered by ShopBot — start your own WhatsApp shop">'
            . '<span class="sbot-ic" aria-hidden="true">⚡</span>'
            . '<span class="sbot-tx">Powered by <b>ShopBot</b></span>'
            . '<span class="sbot-cta">Start your own shop →</span></a>'
            . '<style>'
            . '.sbot-badge{position:fixed;right:14px;bottom:14px;z-index:2147483000;display:inline-flex;align-items:center;gap:8px;'
            . 'padding:9px 14px;border-radius:999px;background:#0f172a;color:#fff;text-decoration:none;'
            . "font:600 13px/1 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;"
            . 'box-shadow:0 6px 24px rgba(2,8,23,.28);border:1px solid rgba(255,255,255,.12);transition:transform .15s ease,box-shadow .15s ease}'
            . '.sbot-badge:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(2,8,23,.36)}'
            . '.sbot-ic{font-size:14px;line-height:1}'
            . '.sbot-tx b{font-weight:800}'
            . '.sbot-cta{max-width:0;overflow:hidden;white-space:nowrap;opacity:0;color:#5eead4;font-weight:700;'
            . 'transition:max-width .25s ease,opacity .2s ease,margin .25s ease}'
            . '.sbot-badge:hover .sbot-cta{max-width:170px;opacity:1;margin-left:2px}'
            . '@media(max-width:520px){.sbot-tx{display:none}.sbot-badge:hover .sbot-cta{max-width:150px}}'
            . '</style>';
    }
    // <<< TECHNOLOGY

    /** Manufacturer brand site — content from tenant settings with sensible defaults. */
    public function brand(?string $shop = null, $tenant = null, ?string $base = null)
    {
        if ($tenant === null) { [$tenant, $base] = $this->resolveShop($shop); }
        if ($base === null)   { $base = '/' . (string) $tenant->slug; }
        $theme  = $this->resolveTheme($tenant);

        // The hardcoded brand defaults are ONE customer's paper brand — only apply them to the
        // tenant that owns them. Every other manufacturer tenant gets neutral fallbacks (its own
        // name as the hero title, its own FAQ setting, and no foreign brand/stat blocks).
        $useBd         = \App\Support\BrandDefaults::enabledFor($tenant);
        $defaultBrands = $useBd ? \App\Support\BrandDefaults::brands($theme['accent']) : [];
        $defaultStats  = $useBd ? \App\Support\BrandDefaults::stats() : [];
        $defaultFaq    = $useBd ? \App\Support\BrandDefaults::faq() : [];
        $td            = $useBd ? \App\Support\BrandDefaults::text()
            : ['tagline' => '', 'eyebrow' => '', 'trustLine' => '', 'heroTitle' => (string) $tenant->name, 'heroText' => '', 'metaDescription' => ''];

        $cfg = [
            'name'        => (string) $tenant->name,
            'initials'    => $this->initials((string) $tenant->name),
            'logo'        => $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '',
            'heroImage'   => $tenant->setting('hero_image', '') ? $this->imageUrl((string) $tenant->setting('hero_image', '')) : '',
            'factoryImage'=> $tenant->setting('factory_image', '') ? $this->imageUrl((string) $tenant->setting('factory_image', '')) : '',
            'accent'      => $theme['accent'],
            'accentDark'  => $theme['accentDark'],
            'eyebrow'     => $theme['eyebrow'] ?: $td['eyebrow'],
            'heroTitle'   => (string) ($tenant->setting('hero_title', '') ?: $td['heroTitle']),
            'heroText'    => (string) ($tenant->setting('hero_text', '') ?: $td['heroText']),
            'trustLine'   => $theme['trustLine'] ?: $td['trustLine'],
            'website'     => (string) $tenant->setting('website', ''),
            'phone'       => (string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number),
            'email'       => (string) $tenant->setting('public_email', ''),
            'address'     => (string) $tenant->setting('address', ''),
            'waNumber'    => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
            'currency'    => $this->currency($tenant),
            'shopUrl'     => $base . '/shop',
            'catalogueUrl'=> $base . '/catalogue',
            'panelUrl'    => url('/panel'),
            'brands'      => $tenant->setting('brands', $defaultBrands),
            'stats'       => $tenant->setting('brand_stats', $defaultStats),
            'faq'         => $tenant->setting('faq', $defaultFaq),
        ];

        // ---- SEO: server-rendered title, meta, Open Graph + JSON-LD (Organization + FAQPage) ----
        $brandNames = array_values(array_filter(array_map(
            fn ($b) => is_array($b) ? ($b['name'] ?? '') : '', (array) $cfg['brands']
        )));
        $title = $tenant->name . ' — Paper & Tissue Manufacturer';
        $desc  = (string) ($tenant->setting('meta_description', '') ?: trim(
            $tenant->name . ' manufactures ' . implode(', ', $brandNames)
            . ' — virgin-pulp toilet paper, napkins and copier paper. Wholesale trade pricing, UNBS & ISO 9001 certified. Order on WhatsApp.'
        ));
        $url  = url('/' . $tenant->slug);
        $logo = $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '';

        $org = array_filter([
            '@context' => 'https://schema.org', '@type' => 'Organization',
            'name' => $tenant->name, 'url' => $url, 'logo' => $logo ?: null,
            'telephone' => $cfg['phone'] ?: null, 'email' => $cfg['email'] ?: null,
            'address' => $cfg['address'] ? ['@type' => 'PostalAddress', 'streetAddress' => $cfg['address'], 'addressCountry' => 'UG'] : null,
            'sameAs' => $cfg['website'] ? [$cfg['website']] : null,
            'brand' => $brandNames ? array_map(fn ($n) => ['@type' => 'Brand', 'name' => $n], $brandNames) : null,
        ]);
        $faqLd = [
            '@context' => 'https://schema.org', '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($f) => [
                '@type' => 'Question', 'name' => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
            ], (array) $cfg['faq']),
        ];

        $jflags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;  // HEX_TAG: encode </script> so a tenant/product field can't break out of the ld+json script (stored XSS)
        $meta = implode("\n", array_filter([
            '<meta name="description" content="' . e($desc) . '">',
            '<meta name="robots" content="index,follow">',
            '<link rel="canonical" href="' . e($url) . '">',
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="' . e($tenant->name) . '">',
            '<meta property="og:title" content="' . e($title) . '">',
            '<meta property="og:description" content="' . e($desc) . '">',
            '<meta property="og:url" content="' . e($url) . '">',
            $logo ? '<meta property="og:image" content="' . e($logo) . '">' : '',
            '<meta name="twitter:card" content="summary_large_image">',
            '<script type="application/ld+json">' . json_encode($org, $jflags) . '</script>',
            '<script type="application/ld+json">' . json_encode($faqLd, $jflags) . '</script>',
        ]));

        $path = resource_path('storefront/brand.html');
        $html = is_file($path) ? file_get_contents($path) : '<h1>Site unavailable</h1>';
        $html = str_replace('__SEO_TITLE__', e($title), $html);
        $html = str_replace('__SEO_META__', $meta, $html);
        $html = str_replace('__BRAND_CONFIG__', json_encode($cfg, $jflags), $html);

        $html = str_replace('</body>', $this->poweredByBadge($tenant) . '</body>', $html);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    // >>> AUTO_PARTS: EPC storefront (genuine spare parts). Mirrors brand(); one cart at /{shop}/shop.
    public function autoParts(?string $shop = null, $tenant = null, ?string $base = null)
    {
        if ($tenant === null) { [$tenant, $base] = $this->resolveShop($shop); }
        if ($base === null)   { $base = '/' . (string) $tenant->slug; }
        $theme = $this->resolveTheme($tenant);

        $defaultSystems = [
            ['id' => 'engine', 'name' => 'Engine'], ['id' => 'transmission', 'name' => 'Transmission'],
            ['id' => 'fuel', 'name' => 'Fuel System'], ['id' => 'electrical', 'name' => 'Electrical'],
            ['id' => 'brakes', 'name' => 'Brakes'], ['id' => 'suspension', 'name' => 'Suspension'],
            ['id' => 'frame', 'name' => 'Frame & Body'], ['id' => 'controls', 'name' => 'Controls'],
            ['id' => 'wheels', 'name' => 'Wheels & Tyres'], ['id' => 'accessories', 'name' => 'Accessories'],
        ];
        $defaultModels = [
            ['name' => 'Boxer 100', 'tagline' => 'The reliable workhorse'],
            ['name' => 'Boxer 125 HD', 'tagline' => 'The heavy-duty champion'],
            ['name' => 'Boxer 150', 'tagline' => 'More power. More load.'],
            ['name' => 'Bajaj V15', 'tagline' => 'Power ahead'],
        ];

        $cfg = [
            'name'        => (string) $tenant->name,
            'initials'    => $this->initials((string) $tenant->name),
            'logo'        => $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '',
            'accent'      => $theme['accent'],
            'accentDark'  => $theme['accentDark'],
            'eyebrow'     => $theme['eyebrow'] ?: 'Genuine Motorcycle Spares',
            'heroTitle'   => (string) ($tenant->setting('hero_title', '') ?: 'The right part. Right away.'),
            'heroText'    => (string) ($tenant->setting('hero_text', '') ?: ($tenant->setting('tagline', '') ?: 'Genuine motorcycle spares, exploded diagrams and expert help.')),
            'trustLine'   => $theme['trustLine'] ?: '100% Genuine Parts · Interactive Diagrams · Fast Delivery · Expert Support',
            'phone'       => (string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number),
            'email'       => (string) $tenant->setting('public_email', ''),
            'website'     => (string) $tenant->setting('website', ''),
            'waNumber'    => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
            'currency'    => $this->currency($tenant),
            // Per-piece (loose) selling — the auto-parts store computes the piece price client-side
            // (carton / pack * (1 + markup)) since its checkout is WhatsApp, not a server-priced cart.
            'looseEnabled'   => (bool) $tenant->setting('loose_enabled', false),
            'looseMarkupPct' => (float) $tenant->setting('loose_markup_pct', 20),
            'shopUrl'     => $base . '/shop',
            'catalogueUrl'=> $base . '/catalogue',
            'quoteUrl'    => $base . '/quote',   // customer-built quotation (server-priced, PDF, WhatsApp)
            'panelUrl'    => url('/panel'),
            'systems'     => $tenant->setting('epc_systems', $defaultSystems),
            'models'      => $tenant->setting('epc_models', $defaultModels),
            // Which bike models the storefront shows — driven by the per-model on/off toggles
            // in Settings → Storefront (each defaults to ON). Only models toggled on are listed;
            // the storefront shows exactly these, in this order.
            'enabledModels' => array_values(array_filter([
                'boxer100'   => (bool) $tenant->setting('show_model_boxer100', true)   ? 'boxer100'   : null,
                'boxer125hd' => (bool) $tenant->setting('show_model_boxer125hd', true) ? 'boxer125hd' : null,
                'boxer150'   => (bool) $tenant->setting('show_model_boxer150', true)   ? 'boxer150'   : null,
                'boxer125x'  => (bool) $tenant->setting('show_model_boxer125x', true)  ? 'boxer125x'  : null,
                'ct125hd'    => (bool) $tenant->setting('show_model_ct125hd', true)    ? 'ct125hd'    : null,
                'boxer150x'  => (bool) $tenant->setting('show_model_boxer150x', true)  ? 'boxer150x'  : null,
                // v4.7.5: the TVS HLX 125 — the other half of the boda market. The SPA now ships the
                // model + a brand-family fitment guard, so TVS-line parts check against it correctly.
                'tvshlx125'  => (bool) $tenant->setting('show_model_tvshlx125', true)  ? 'tvshlx125'  : null,
            ])),
            // v4.7.8 → v4.15: per-model photo overrides, now for EVERY model + the homepage
            // hero. Settings → Storefront → "<model> photo" → Choose photo (branding-upload
            // stores it and drops the URL here). Uploads live in /storage (survive rebuilds,
            // no repo involvement); blank = the built-in repo photo; a dead override URL
            // falls back to the built-in via mimgErr. The PDF catalogue reads the same keys.
            'modelImages' => array_filter([
                'hero'       => (string) $tenant->setting('model_image_hero', ''),
                'boxer100'   => (string) $tenant->setting('model_image_boxer100', ''),
                'boxer125hd' => (string) $tenant->setting('model_image_boxer125hd', ''),
                'boxer150'   => (string) $tenant->setting('model_image_boxer150', ''),
                'boxer125x'  => (string) $tenant->setting('model_image_boxer125x', ''),
                'ct125hd'    => (string) $tenant->setting('model_image_ct125hd', ''),
                'boxer150x'  => (string) $tenant->setting('model_image_boxer150x', ''),
                'tvshlx125'  => (string) $tenant->setting('model_image_tvshlx125', ''),
            ]),
            // v4.17: per-model photo GALLERIES from the bike photo pack (gal-{model}-{n}.jpg
            // entries → model_gallery_{id} JSON array). When set, the model page gallery shows
            // the owner's photos instead of the built-in set.
            'modelGalleries' => (function () use ($tenant) {
                $out = [];
                foreach (['boxer100', 'boxer125hd', 'boxer150', 'boxer125x', 'ct125hd', 'boxer150x', 'tvshlx125'] as $mid) {
                    $raw = (string) $tenant->setting('model_gallery_' . $mid, '');
                    if ($raw === '') {
                        continue;
                    }
                    $arr = json_decode($raw, true);
                    if (! is_array($arr)) {
                        continue;
                    }
                    $urls = array_values(array_filter(array_map('strval', $arr),
                        fn ($u) => $u !== '' && (str_starts_with($u, '/') || preg_match('~^https?://~i', $u))));
                    if ($urls) {
                        $out[$mid] = array_slice($urls, 0, 8);
                    }
                }

                return $out;
            })(),
            // v4.8 — the Best-sellers rail reads the same comma list the catalogue PDF badges use.
            'bestSellers' => (string) $tenant->setting('catalog_best_sellers', ''),
            // Trust & delivery promises shown across the storefront (editable in Settings → Storefront).
            'genuineLine' => (string) ($tenant->setting('sf_genuine', '') ?: '100% genuine Bajaj — verify the hologram, or your money back.'),
            'deliveryEta' => (string) ($tenant->setting('sf_delivery_eta', '') ?: 'Same-day in town · 24–48h upcountry'),
            'payMethods'  => (string) ($tenant->setting('sf_pay_methods', '') ?: 'Mobile Money · Pay on Delivery'),
            'warranty'    => (string) ($tenant->setting('sf_warranty', '') ?: '1-year genuine-parts warranty'),
            'returns'     => (string) ($tenant->setting('sf_returns', '') ?: '7-day returns on unfitted parts'),
            // Sell only what you have: when on, parts with 0 stock are hidden from the storefront.
            'hideOOS'     => (bool) $tenant->setting('sf_hide_oos', false),
            // v4.10.5 — exploded diagrams hidden by default (parts-first suppliers like boraboda
            // have no plates; empty diagrams confuse). Flip sf_diagrams ON when real plates exist.
            'showDiagrams' => (bool) $tenant->setting('sf_diagrams', false),
            // v4.11 — real URLs: the SPA router needs to know its mount point ('' on a custom
            // domain, '/{slug}' on the platform host) to build and parse history-API paths.
            'basePath' => $shop ? '/' . $tenant->slug : '',
        ];

        $title = $tenant->name . ' — Genuine Motorcycle Spare Parts';
        $desc  = (string) ($tenant->setting('meta_description', '') ?: (
            $tenant->name . ' — genuine Bajaj Boxer motorcycle spare parts with exploded diagrams and part numbers. Fast delivery. Order on WhatsApp.'
        ));
        // v4.10.4 (SEO audit) — canonical, Open Graph, Twitter card, Organization + WebSite JSON-LD.
        // v4.11 (real URLs) — the shell is now served on REAL paths (/part/13, /parts, /search/…),
        // and each URL gets its own title/description/canonical; part pages additionally get an
        // og:image of the part and full Product JSON-LD (name, OEM as mpn, price, availability),
        // so Google indexes every part and a WhatsApp-shared link previews the actual product.
        $canon = request()->getSchemeAndHttpHost() . ($shop ? '/' . $tenant->slug : '/');
        $reqPath = trim(request()->path(), '/');
        if ($shop && str_starts_with($reqPath, $tenant->slug)) {
            $reqPath = trim(substr($reqPath, strlen($tenant->slug)), '/');
        }
        $spaViews = ['part', 'parts', 'system', 'model', 'search', 'cart', 'ai', 'diagrams', 'plate', 'diagram'];
        $seg = explode('/', $reqPath);
        $spaRoute = in_array($seg[0] ?? '', $spaViews, true) ? $reqPath : '';
        $status = 200; $robots = 'index,follow'; $productLd = null; $ogImage = null;
        $pageCanon = $spaRoute !== '' ? rtrim($canon, '/') . '/' . $spaRoute : $canon;
        if (($seg[0] ?? '') === 'part' && isset($seg[1])) {
            $pp = Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->where('active', true)->find((int) $seg[1]);
            if ($pp) {
                $oem   = trim((string) ($pp->oem_number ?? ''));
                $title = $pp->name . ($oem !== '' ? ' — OEM ' . $oem : '') . ' | ' . $tenant->name;
                $cur   = (string) ($tenant->setting('currency', 'UGX'));
                $desc  = $pp->name . ($oem !== '' ? ' (OEM ' . $oem . ')' : '') . ' — genuine motorcycle spare part.'
                    . ((float) ($pp->price ?? 0) > 0 ? ' Price ' . $cur . ' ' . number_format((float) $pp->price) . '.' : '')
                    . ' Order on WhatsApp from ' . $tenant->name . '.';
                if ((string) ($pp->image_url ?? '') !== '') $ogImage = $this->imageUrl((string) $pp->image_url);
                $productLd = array_filter([
                    '@context' => 'https://schema.org', '@type' => 'Product',
                    'name'  => (string) $pp->name,
                    'mpn'   => $oem !== '' ? $oem : null,
                    'sku'   => (string) ($pp->sku ?? '') !== '' ? (string) $pp->sku : null,
                    'image' => $ogImage,
                    'brand' => (string) ($pp->brand ?? '') !== '' ? ['@type' => 'Brand', 'name' => (string) $pp->brand] : null,
                    'offers' => ((float) ($pp->price ?? 0) > 0) ? [
                        '@type' => 'Offer', 'price' => (string) (float) $pp->price, 'priceCurrency' => $cur ?: 'UGX',
                        'availability' => 'https://schema.org/' . (((bool) $tenant->setting('storefront_show_stock', false) === false
                            || $pp->stock === null || (int) $pp->stock > 0) ? 'InStock' : 'OutOfStock'),   // v5.05 — see audit P0-2
                        'url' => $pageCanon,
                    ] : null,
                ]);
            } else {
                $status = 404; $robots = 'noindex,nofollow';
                $title = 'Part not found | ' . $tenant->name;
            }
        } elseif (($seg[0] ?? '') === 'system' && isset($seg[1])) {
            $title = urldecode($seg[1]) . ' — motorcycle parts | ' . $tenant->name;
            $desc  = 'Genuine ' . urldecode($seg[1]) . ' parts with OEM numbers and prices. ' . $tenant->name . '.';
        } elseif (($seg[0] ?? '') === 'parts') {
            $title = 'All parts by category | ' . $tenant->name;
        } elseif (($seg[0] ?? '') === 'model' && isset($seg[1])) {
            $title = 'Parts for your motorcycle | ' . $tenant->name;
        } elseif (in_array($seg[0] ?? '', ['search', 'cart', 'ai', 'diagrams', 'plate', 'diagram'], true)) {
            $robots = 'noindex,follow';   // internal search/cart/tools should not be indexed
        }
        $logo  = $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '';
        $jflags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;  // HEX_TAG: encode </script> so a tenant/product field can't break out of the ld+json script (stored XSS)
        $orgLd = array_filter([
            '@context' => 'https://schema.org', '@type' => 'AutoPartsStore',
            'name' => $tenant->name, 'url' => $canon, 'logo' => $logo ?: null,
            'telephone' => trim((string) $tenant->setting('public_phone', '')) ?: null,
            'address' => trim((string) $tenant->setting('address', '')) !== ''
                ? ['@type' => 'PostalAddress', 'streetAddress' => trim((string) $tenant->setting('address', '')), 'addressCountry' => 'UG'] : null,
        ]);
        $siteLd = [
            '@context' => 'https://schema.org', '@type' => 'WebSite',
            'name' => $tenant->name, 'url' => $canon,
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => rtrim($canon, '/') . '/#/search/{search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
        $meta = implode("\n", array_filter([
            '<meta name="description" content="' . e($desc) . '">',
            '<meta name="robots" content="' . e($robots) . '">',
            '<link rel="canonical" href="' . e($pageCanon) . '">',
            '<meta property="og:type" content="' . ($productLd ? 'product' : 'website') . '">',
            '<meta property="og:site_name" content="' . e($tenant->name) . '">',
            '<meta property="og:title" content="' . e($title) . '">',
            '<meta property="og:description" content="' . e($desc) . '">',
            '<meta property="og:url" content="' . e($pageCanon) . '">',
            ($ogImage ?: $logo) ? '<meta property="og:image" content="' . e($ogImage ?: $logo) . '">' : '',
            '<meta name="twitter:card" content="summary_large_image">',
            '<script type="application/ld+json">' . json_encode($orgLd, $jflags) . '</script>',
            '<script type="application/ld+json">' . json_encode($siteLd, $jflags) . '</script>',
            $productLd ? '<script type="application/ld+json">' . json_encode($productLd, $jflags) . '</script>' : '',
        ]));

        $path = resource_path('storefront/autoparts.html');
        $html = is_file($path) ? file_get_contents($path) : '<h1>Store unavailable</h1>';
        $html = str_replace('__SEO_TITLE__', e($title), $html);
        $html = str_replace('__SEO_META__', $meta, $html);
        $html = str_replace('__AUTOPARTS_CONFIG__', json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $html);
        $html = str_replace('</body>', $this->poweredByBadge($tenant) . '</body>', $html);

        // v4.8.1 — first-load performance. The single-file storefront is ~4 MB of text; gzip cuts
        // it to ~1.1 MB, and a short private cache stops repeat visitors re-downloading it on every
        // page view. Config baked into the HTML (models/settings) tolerates 5 minutes of staleness —
        // the same window the /catalogue feed already caches server-side.
        return $this->compressed($html, 'text/html; charset=utf-8', 'private, max-age=300', $status);
    }

    /**
     * v4.8.2 — POST /{shop}/part-id (Storefront 2.0 Phase 3). A customer photographs their old part;
     * the same vision capability the WhatsApp bot has had for weeks identifies it against THIS shop's
     * catalogue and returns up to 3 candidate products. Session-less and throttled at the route; the
     * SPA falls back to the WhatsApp deep-link whenever this returns ok=false.
     */
    public function partId(\Illuminate\Http\Request $request, ?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);

        $file = $request->file('photo');
        if (! $file || ! $file->isValid()) return response()->json(['ok' => false, 'error' => 'no_photo']);
        $mime = strtolower((string) $file->getMimeType());
        if (! str_starts_with($mime, 'image/')) return response()->json(['ok' => false, 'error' => 'not_image']);
        if ($file->getSize() > 6 * 1024 * 1024) return response()->json(['ok' => false, 'error' => 'too_big']);

        $bytes = (string) file_get_contents($file->getRealPath());
        // Big phone photos → downscale for the vision call when GD is available (cost + latency).
        if (strlen($bytes) > 900 * 1024 && function_exists('imagecreatefromstring')) {
            try {
                $im = @imagecreatefromstring($bytes);
                if ($im !== false) {
                    $w = imagesx($im); $h = imagesy($im);
                    $scale = min(1.0, 1024 / max($w, $h));
                    if ($scale < 1.0) {
                        $im2 = imagescale($im, (int) round($w * $scale), (int) round($h * $scale));
                        if ($im2 !== false) { $im = $im2; }
                    }
                    ob_start(); imagejpeg($im, null, 82); $j = ob_get_clean();
                    if ($j !== false && $j !== '') { $bytes = $j; $mime = 'image/jpeg'; }
                    imagedestroy($im);
                }
            } catch (\Throwable $e) {
            }
        }

        // Per-tenant daily budget on paid vision calls — the per-IP route throttle alone lets IP
        // rotation amplify the OpenAI bill. Counts attempts (that reach the model) per tenant per day.
        $visionCap = (int) $tenant->setting('partid_daily_cap', 300);
        if ($visionCap > 0) {
            $capKey = "sf_partid_day:{$tenant->id}:" . now()->format('Ymd');
            $usedToday = (int) \Illuminate\Support\Facades\Cache::get($capKey, 0);
            if ($usedToday >= $visionCap) {
                return response()->json(['ok' => false, 'error' => 'busy',
                    'message' => 'Photo identification is busy right now — please try again later, or send us the photo on WhatsApp.'], 429);
            }
            \Illuminate\Support\Facades\Cache::put($capKey, $usedToday + 1, 90000);   // ~25h TTL rolls the day
        }

        $res = app(\App\Services\Vision\PartIdentifier::class)->identify($tenant, base64_encode($bytes), $mime);
        if (! ($res['ok'] ?? false)) return response()->json(['ok' => false, 'error' => (string) ($res['error'] ?? 'failed')]);

        // Hydrate matches into card-ready products (same field names the /catalogue feed uses).
        $ids = array_column($res['matches'], 'id');
        $byId = $ids ? Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereIn('id', $ids)->where('active', true)->get()->keyBy('id') : collect();
        $matches = [];
        foreach ($res['matches'] as $m) {
            $p = $byId->get($m['id']);
            if (! $p) continue;   // model may not invent ids — but never trust it
            $matches[] = [
                '_row' => (int) $p->id, 'Product Name' => (string) $p->name,
                'Brand' => (string) ($p->brand ?? ''), 'Category' => (string) ($p->category ?? ''),
                'Price_UGX' => (float) ($p->price ?? 0), 'Image' => $this->imageUrl($p->image_url),
                'OEM' => (string) ($p->oem_number ?? ''), 'SKU' => (string) ($p->sku ?? ''),
                'Stock' => $p->stock === null ? null : (int) $p->stock,
                'Keywords' => (string) ($p->keywords ?? ''),
                'confidence' => $m['confidence'], 'reason' => $m['reason'],
            ];
        }
        \App\Support\BotTrace::log($tenant->id, 'sf_partid', $request->ip() ?: 'web', 'web_part_id',
            'seen=' . ($res['seen'] ?? '') . ' matches=' . count($matches));

        return response()->json(['ok' => true, 'seen' => (string) ($res['seen'] ?? ''), 'matches' => $matches]);
    }

    /**
     * v4.9.2 — POST /{shop}/part-request {part, bike?, phone} — the mechanic-human promise from
     * the web. When search finds nothing, the customer leaves the part + their WhatsApp number;
     * PartRequestService records it (AI Follow-ups draft + owner alert + BotTrace) and the shop's
     * own bot sends the customer a one-line confirmation, opening the WhatsApp thread the team
     * will answer on. Session-less, throttled at the route, CSRF-exempt like /order and /quote.
     */
    public function partRequest(\Illuminate\Http\Request $request, ?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);
        $part  = trim(mb_substr((string) $request->input('part', ''), 0, 160));
        $bike  = trim(mb_substr((string) $request->input('bike', ''), 0, 60));
        $phone = preg_replace('/[^0-9]/', '', (string) $request->input('phone', ''));
        if ($part === '' || strlen($phone) < 7) {
            return response()->json(['ok' => false, 'error' => 'Tell us the part you need and a WhatsApp number we can reply to.']);
        }

        $new = app(\App\Services\Bot\PartRequestService::class)->capture(
            $tenant, $phone, $part, 'storefront', 'Storefront search/request: ' . $part, $bike
        );

        // Confirmation on WhatsApp — only for a NEW request (a repeat within the dedupe window
        // was already confirmed) and at most 2/hour/phone so the form can't be used to pester
        // an arbitrary number. Best-effort: the request is recorded either way.
        if ($new) {
            try {
                $cKey = "partreq:conf:{$tenant->id}:{$phone}";
                $sent = (int) \Illuminate\Support\Facades\Cache::get($cKey, 0);
                if ($sent < 2 && $tenant->whatsapp_instance) {
                    \Illuminate\Support\Facades\Cache::put($cKey, $sent + 1, 3600);
                    app(\App\Services\WhatsApp\WhatsAppManager::class)->driver('evolution')->sendText(
                        (string) $tenant->whatsapp_instance, $phone,
                        "\u{1F527} Got your request from our website — *{$part}*" . ($bike !== '' ? " for your {$bike}" : '') . ".\n"
                        . "I'm checking with my colleague and will come back to you here with options and prices, usually the same day. \u{1F44D}"
                    );
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('part-request confirm send failed: ' . $e->getMessage());
            }
        }

        return response()->json(['ok' => true,
            'message' => "Got it — we're checking with the team. You'll get a WhatsApp reply with options."]);
    }

    // ── v4.9.1 Dealer Programme 4b: WhatsApp-code sign-in + the dealer overlay feed ──────────────

    /** POST /{shop}/dealer-otp {phone} — sends a 6-digit code on WhatsApp when the number is an enrolled dealer. */
    public function dealerOtp(\Illuminate\Http\Request $request, ?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);
        $phone = preg_replace('/[^0-9]/', '', (string) $request->input('phone', ''));
        // The reply is deliberately identical whether or not the number is enrolled — no probing.
        $neutral = ['ok' => true, 'message' => 'If this number is enrolled as a dealer, a sign-in code has been sent on WhatsApp.'];
        if (strlen($phone) < 7) return response()->json($neutral);

        $cntKey = "dlrotp:cnt:{$tenant->id}:{$phone}";
        $sent = (int) \Illuminate\Support\Facades\Cache::get($cntKey, 0);
        if ($sent >= 3) return response()->json($neutral);                       // 3 codes / hour / phone

        $profile = \App\Services\Pricing\PriceResolver::profileFor($tenant, $phone);
        if (! $profile) return response()->json($neutral);                        // not a dealer — same reply

        try {
            $code = (string) random_int(100000, 999999);   // 6 digits, parity with the customer OTP
            \Illuminate\Support\Facades\Cache::put("dlrotp:{$tenant->id}:{$phone}", ['code' => $code, 'tries' => 0], 300);
            \Illuminate\Support\Facades\Cache::put($cntKey, $sent + 1, 3600);
            app(\App\Services\WhatsApp\WhatsAppManager::class)->driver('evolution')->sendText(
                (string) $tenant->whatsapp_instance, $phone,
                "🔐 {$tenant->name} dealer sign-in code: *{$code}*\nValid 5 minutes. Never share this code."
            );
            \App\Support\BotTrace::log($tenant->id, 'dealer_otp', $phone, 'sent');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('dealer-otp send failed: ' . $e->getMessage());
        }
        return response()->json($neutral);
    }

    /** POST /{shop}/dealer-verify {phone, code} → {ok, token, tier, pct, name}. */
    public function dealerVerify(\Illuminate\Http\Request $request, ?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);
        $phone = preg_replace('/[^0-9]/', '', (string) $request->input('phone', ''));
        $code  = preg_replace('/[^0-9]/', '', (string) $request->input('code', ''));
        $key   = "dlrotp:{$tenant->id}:{$phone}";
        $rec   = \Illuminate\Support\Facades\Cache::get($key);
        if (! is_array($rec)) return response()->json(['ok' => false, 'error' => 'Code expired — request a new one.']);
        if ((int) ($rec['tries'] ?? 0) >= 5) {
            \Illuminate\Support\Facades\Cache::forget($key);
            return response()->json(['ok' => false, 'error' => 'Too many attempts — request a new code.']);
        }
        if (! hash_equals((string) $rec['code'], $code)) {
            $rec['tries'] = (int) ($rec['tries'] ?? 0) + 1;
            \Illuminate\Support\Facades\Cache::put($key, $rec, 300);
            return response()->json(['ok' => false, 'error' => 'Wrong code — check WhatsApp and try again.']);
        }
        \Illuminate\Support\Facades\Cache::forget($key);
        $profile = \App\Services\Pricing\PriceResolver::profileFor($tenant, $phone);
        if (! $profile) return response()->json(['ok' => false, 'error' => 'This number is not enrolled as a dealer.']);
        $token = \App\Services\Pricing\DealerAuth::mint($tenant->id, $phone, time() + \App\Services\Pricing\DealerAuth::TTL_DAYS * 86400);
        \App\Support\BotTrace::log($tenant->id, 'dealer_login', $phone, 'web_signin');
        return response()->json([
            'ok' => true, 'token' => $token,
            'tier' => (string) $profile->dealer_tier,
            'pct'  => \App\Services\Pricing\PriceResolver::pctFor($tenant, $profile),
            'name' => (string) ($profile->name ?? ''),
        ]);
    }

    /** GET /{shop}/dealer-feed?t=<token> — per-product dealer prices + recent orders. Always no-store. */
    public function dealerFeed(\Illuminate\Http\Request $request, ?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);
        $auth = \App\Services\Pricing\DealerAuth::check((string) ($request->query('t', '') ?: $request->header('X-Dealer-Token', '')));
        if (! $auth || $auth['tenant'] !== (int) $tenant->id) {
            return response()->json(['ok' => false, 'error' => 'signin_required'], 401)->header('Cache-Control', 'no-store');
        }
        $profile = \App\Services\Pricing\PriceResolver::profileFor($tenant, $auth['phone']);
        if (! $profile) return response()->json(['ok' => false, 'error' => 'signin_required'], 401)->header('Cache-Control', 'no-store');

        $pct   = \App\Services\Pricing\PriceResolver::pctFor($tenant, $profile);
        $floor = \App\Services\Pricing\PriceResolver::floorFor($tenant);
        $hasDlr = \Illuminate\Support\Facades\Schema::hasColumn('products', 'dealer_price');
        $prices = [];
        // v4.54 — dealers are customers. A hidden line is not priced for them either.
        $q = \App\Services\Catalog\Visibility::scope(Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereNull('archived_at'), $tenant);
        foreach ($q->get(array_merge(['id', 'price', 'cost_price'], $hasDlr ? ['dealer_price'] : [])) as $p) {
            [$dp, $src] = \App\Services\Pricing\PriceResolver::compute(
                (float) ($p->price ?? 0),
                $p->cost_price !== null ? (float) $p->cost_price : null,
                $hasDlr && $p->dealer_price !== null ? (float) $p->dealer_price : null,
                $pct, $floor
            );
            if ($src !== 'retail') $prices[(int) $p->id] = $dp;
        }

        $history = [];
        try {
            $qs = \App\Models\Quotation::withoutGlobalScopes()->with('items')
                ->where('tenant_id', $tenant->id)->where('customer_phone', $auth['phone'])
                ->latest('id')->limit(5)->get();
            foreach ($qs as $qq) {
                $history[] = [
                    'no'    => (string) ($qq->quote_no ?? $qq->id),
                    'date'  => $qq->created_at ? $qq->created_at->format('d M Y') : '',
                    'total' => (float) ($qq->total ?? 0),
                    'status' => (string) ($qq->status ?? ''),
                    'items' => $qq->items->map(fn ($it) => ['name' => (string) $it->name, 'qty' => (int) $it->qty])->values()->all(),
                ];
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok' => true, 'tier' => (string) $profile->dealer_tier, 'pct' => $pct,
            'name' => (string) ($profile->name ?? ''), 'prices' => (object) $prices, 'history' => $history,
        ])->header('Cache-Control', 'no-store');
    }

    /** gzip a response when the client accepts it (proxies won't double-encode a set Content-Encoding). */
    private function compressed(string $body, string $type, string $cache, int $status = 200)
    {
        $resp = response($body, $status)->header('Content-Type', $type)->header('Cache-Control', $cache);
        try {
            if (function_exists('gzencode')
                && str_contains((string) request()->header('Accept-Encoding', ''), 'gzip')) {
                $gz = gzencode($body, 6);
                if ($gz !== false && strlen($gz) < strlen($body)) {
                    $resp->setContent($gz);
                    $resp->header('Content-Encoding', 'gzip')->header('Vary', 'Accept-Encoding');
                }
            }
        } catch (\Throwable $e) {
        }
        return $resp;
    }
    // <<< AUTO_PARTS

    /** Public catalogue feed (same JSON shape the panel/products feed uses). */
    public function catalogue(?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);

        // Cache the built feed for a few minutes so a large catalogue is not re-hydrated
        // on every page load, and use toBase()+column select to avoid loading full models.
        // v4.54 — `use ($tenant)` added. This closure never needed the tenant before (the global scope
        // supplied it), and the visibility filter below is the first thing inside it to name $tenant.
        // Without this the variable is undefined, Laravel promotes the warning to an ErrorException,
        // and the whole catalogue endpoint answers 500 — which is exactly what happened.
        $rows = \Illuminate\Support\Facades\Cache::remember('catalogue:' . $tenant->id, now()->addMinutes(5), function () use ($tenant) {
            // Modifier groups per product (restaurant customisation), keyed by product id.
            $mods = [];
            try {
                $groups = \App\Models\ModifierGroup::where('active', true)
                    ->with(['options' => fn ($q) => $q->where('active', true)->orderBy('sort')])
                    ->orderBy('sort')->get()->keyBy('id');
                if ($groups->isNotEmpty()) {
                    $pivot = \Illuminate\Support\Facades\DB::table('product_modifier_group')
                        ->whereIn('modifier_group_id', $groups->keys()->all())->orderBy('sort')->get();
                    foreach ($pivot as $pv) {
                        $g = $groups->get($pv->modifier_group_id);
                        if (! $g) continue;
                        $mods[$pv->product_id][] = [
                            'id' => $g->id, 'name' => $g->name, 'required' => (bool) $g->required,
                            'min' => (int) $g->min_select, 'max' => (int) $g->max_select,
                            'options' => $g->options->map(fn ($o) => [
                                'id' => $o->id, 'name' => $o->name, 'delta' => (float) $o->price_delta,
                            ])->values()->all(),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $mods = [];
            }

            // v4.8.1 — product specs for the storefront specs table (one query each, tenant-scoped).
            $specMap = [];
            try {
                $defs = \App\Models\SpecDefinition::query()->get()->keyBy('id');
                if ($defs->isNotEmpty()) {
                    foreach (\App\Models\ProductSpec::query()->whereNotNull('value_text')->get() as $psx) {
                        $d = $defs->get($psx->spec_definition_id);
                        if (! $d) continue;
                        $label = trim((string) ($d->label ?: $d->key)) . ($d->unit ? ' (' . $d->unit . ')' : '');
                        if ($label !== '') $specMap[$psx->product_id][$label] = (string) $psx->value_text;
                    }
                }
            } catch (\Throwable $e) {
                $specMap = [];
            }

            // v5.05 — availability is stated only if the shop has said its stock figures can be
            // trusted. Default OFF: see the note on 'Stock' below for why.
            $showStock = (bool) $tenant->setting('storefront_show_stock', false);

            // v4.54 — hidden products never enter the public feed. This one query feeds the
            // product grid, the storefront's own search box and the category tiles, so filtering
            // here covers all three; anything added downstream inherits it for free.
            return \App\Services\Catalog\Visibility::scope(Product::where('active', true), $tenant)
                ->orderByDesc('display_order')->orderBy('name')
                ->toBase()
                ->get(array_merge(
                    ['id', 'name', 'description', 'category', 'brand', 'keywords', 'base_price', 'price', 'piece_price', 'stock', 'image_url', 'display_order', 'moq', 'pack_size', 'unit_label', 'popularity', 'created_at', 'sku',
                     'gallery_1', 'gallery_2', 'gallery_3'],
                    // v4.7.7 column — guarded so the feed keeps serving if the migration hasn't run yet.
                    \Illuminate\Support\Facades\Schema::hasColumn('products', 'oem_number') ? ['oem_number'] : []
                ))
                ->map(function ($p) use ($mods, $specMap, $showStock) {
                    return [
                        'Product Name' => (string) $p->name,
                        'Variant'      => '',
                        'Brand'        => (string) ($p->brand ?? ''),
                        'Description'  => (string) ($p->description ?? ''),
                        'Category'     => (string) ($p->category ?? 'Other'),
                        'Keywords'     => (string) ($p->keywords ?? ''),
                        'OEM'          => (string) ($p->oem_number ?? ''),   // v4.7.7 — real part-number chip + diagram cross-links
                        'SKU'          => (string) ($p->sku ?? ''),          // v4.8 — searchable + shown on cards
                        'Price_UGX'    => (float) ($p->price ?? $p->base_price ?? 0),   // price = selling truth (price-drift fix 2026-07-18)
                        // Explicit per-piece retail override (loose selling). Null = derive from carton÷pack×markup.
                        'PiecePrice'   => $p->piece_price === null ? null : (float) $p->piece_price,
                        // v5.05 (launch audit P0-2) — `products.stock` is a TYPED column that no sale
                        // decrements. Nothing on the order path writes it; new products are seeded at
                        // 100. The shop's own Juba Stock screen derives real stock from purchase and
                        // sales documents and disagrees with it — popcorn reads 150 here against 50
                        // bought and 50 sold. This column drove "Only N left", the Add button, the
                        // home rails and the InStock flag handed to Google.
                        //
                        // A number you cannot vouch for is worse than no number: it either sells goods
                        // you do not have or hides goods you do. Availability is therefore OFF unless
                        // the shop turns it on. Null means "not stated" everywhere downstream — oosOf()
                        // reads null as in-stock, so nothing is hidden and nothing is disabled.
                        //
                        // To show it again once the two screens agree:
                        //   Tenant::find(17)->update(['settings' => [...$s, 'storefront_show_stock' => true]])
                        'Stock'        => $showStock ? ($p->stock === null ? null : (int) $p->stock) : null,
                        // Primary card photo. Falls back hero → gallery_1..3 so a product whose only photos
                        // are in the gallery still shows its first photo on the card, matching the detail
                        // page instead of a placeholder. NOTE: this query uses ->toBase(), so $p is a
                        // stdClass, not a Product model — resolve via the pure static helper on the raw
                        // columns, never a model method (that would fatal on stdClass).
                        'Image'        => $this->imageUrl(\App\Models\Product::firstImage($p->image_url, $p->gallery_1, $p->gallery_2, $p->gallery_3)),
                        'Modifiers'    => $mods[$p->id] ?? [],
                        'MOQ'          => $p->moq === null ? null : (int) $p->moq,
                        'PackSize'     => $p->pack_size === null ? null : (int) $p->pack_size,
                        'Unit'         => (string) ($p->unit_label ?? ''),
                        // Merchandising signals for the self-updating home rails (Popular / New arrivals).
                        'Popularity'   => (int) ($p->popularity ?? 0),
                        'Created'      => $p->created_at ? strtotime((string) $p->created_at) : 0,
                        // v4.8.1 — extra photos (slot 1 = packaging by convention) + specs table.
                        'Gallery'      => array_values(array_filter([
                            $this->imageUrl($p->gallery_1), $this->imageUrl($p->gallery_2), $this->imageUrl($p->gallery_3),
                        ])),
                        'Specs'        => (object) ($specMap[$p->id] ?? []),
                        '_row'         => (int) $p->id,
                    ];
                })->values();
        });

        // Hidden departments: a Category toggled inactive in the admin panel is removed from the
        // storefront — both its chip and its products drop out of browsing and search. Applied
        // post-cache (the product feed is cached 5 min, this filter is not) so toggling a category
        // on/off takes effect on the next page load rather than after the cache expires.
        $hiddenCats = \App\Models\Category::where('tenant_id', $tenant->id)
            ->where('active', false)->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->filter(fn ($n) => $n !== '')->flip();
        if ($hiddenCats->isNotEmpty()) {
            $rows = $rows->reject(fn ($p) => $hiddenCats->has(
                mb_strtolower(trim((string) ($p['Category'] ?? '')))
            ))->values();
        }

        // v4.8.1 — the ~89-product feed serializes to a few hundred KB; gzip + a 2-minute private
        // cache make in-site navigation and quick return visits effectively free on mobile data.
        $payload = [
            'products' => $rows,
            'branches' => $this->branches($tenant),
            'settings' => [
                'waNumber' => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
                'currency' => $this->currency($tenant),
                'delivery' => (object) ($tenant->setting('delivery', []) ?: []),
                'category_images' => (object) $this->categoryImages($tenant, $rows),
                'category_groups' => (object) ($tenant->setting('category_groups', []) ?: []),
                'category_order' => \App\Models\Category::where('tenant_id', $tenant->id)
                    ->where('active', true)
                    ->orderBy('sort')->orderBy('name')->pluck('name')->all(),
                // Business-type merchandising banners (Shop by Business). Empty object when unset —
                // the storefront then renders nothing new (fully backward compatible).
                'business_banners' => (object) ($tenant->setting('business_banners', []) ?: []),
                // Ready-made Kits — curated bundles a customer adds to a quotation in one tap.
                'kits' => array_values((array) ($tenant->setting('kits', []) ?: [])),
                'thali' => $this->todayThali($tenant),
                'vertical' => \App\Support\Vertical::of($tenant),
                'trending' => $this->trendingIds($tenant),
            ],
        ];

        return $this->compressed(
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'application/json', 'private, max-age=120'
        );
    }

    /** Today's set-meal for the storefront (computed server-side so it matches the bot). */
    private function todayThali(Tenant $tenant): ?array
    {
        $cfg = (array) ($tenant->setting('thali', []) ?: []);
        if (! \App\Services\Bot\ThaliMenu::enabled($cfg)) return null;
        $tz       = (string) $tenant->setting('timezone', 'Africa/Kampala');
        $ctx      = \App\Services\Bot\ThaliMenu::effective($cfg, $tz);
        $day      = $ctx['day'];
        $session  = $ctx['session'];
        $rollover = $ctx['rollover'];
        $both     = \App\Services\Bot\ThaliMenu::hasBoth($cfg, $day);
        $hasNight = \App\Services\Bot\ThaliMenu::hasNight($cfg);

        $active = $this->thaliSessionPayload($cfg, $day, $session);

        return [
            'price'      => $active['price'],
            'note'       => $active['note'],
            'day'        => $day,
            'day_name'   => \App\Services\Bot\ThaliMenu::dayName($day),
            'items'      => $active['items'],
            'image'      => $active['image'],
            'meal'       => $hasNight ? $active['label'] : '',
            'toggle'     => $both,                                            // customer may switch lunch/dinner
            'current'    => $session,                                         // default by time
            'rollover'   => $rollover,                                        // showing tomorrow's lunch (after cutoff)
            'day_menu'   => $both ? $this->thaliSessionPayload($cfg, $day, 'day') : null,
            'night_menu' => $both ? $this->thaliSessionPayload($cfg, $day, 'night') : null,
        ];
    }

    /** Items/price/note/label/image for one thali session on a given day. */
    private function thaliSessionPayload(array $cfg, string $day, string $sess): array
    {
        [$items, $price, $note, $label] = \App\Services\Bot\ThaliMenu::resolve($cfg, $day, $sess);
        $imgs  = is_array($cfg['images'] ?? null) ? $cfg['images'] : [];
        $nimgs = is_array($cfg['night_images'] ?? null) ? $cfg['night_images'] : [];
        $raw   = ($sess === 'night' && ($nimgs[$day] ?? '') !== '') ? (string) $nimgs[$day] : (string) ($imgs[$day] ?? '');
        return [
            'items' => $items,
            'price' => $price,
            'note'  => $note,
            'label' => $sess === 'night' ? 'Dinner' : 'Lunch',
            'image' => $raw !== '' ? $this->imageUrl($raw) : '',
        ];
    }

    /**
     * Cheap, dependency-free abuse guard for the public order/quote POST endpoints.
     *
     * Two layers: (1) a honeypot — hidden form fields a real customer never sees or fills;
     * a non-empty value means a bot, and we return a fake success so it doesn't learn it was
     * caught. (2) a per-IP + tenant rate limit so a script can't hammer the endpoint even
     * without tripping the honeypot. Returns a response to short-circuit on abuse, else null.
     */
    private function botGuard(Request $r, Tenant $tenant, string $action): ?\Illuminate\Http\JsonResponse
    {
        foreach (['website', 'company_url', 'hp_email'] as $trap) {
            if (trim((string) $r->input($trap, '')) !== '') {
                return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
            }
        }

        $key = 'sf' . $action . ':' . $tenant->id . ':' . $r->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 12)) {
            return response()->json(['ok' => false, 'error' => 'Too many attempts. Please wait a minute and try again.'], 429);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);

        return null;
    }

    /**
     * v5.05 — price a posted cart from the CATALOGUE, not from the browser.
     *
     * The launch audit's first P0: `$price = (float) ($it['price'] ?? 0)` took the money straight
     * off the wire. A curl with `"price":1` bought fifty cartons for fifty. This is the fix, and it
     * is deliberately the dullest possible one — look the name up, use the shop's own number.
     *
     * Matching is EXACT on the case-folded, whitespace-collapsed name. Not fuzzy, not partial: the
     * same rule `OrderItemSync` and `PurchaseNameMatch` already use, for the same reason — fifty
     * cartons priced off the wrong product is worse than fifty priced off nothing, because the
     * second one refuses to save and the first one looks fine.
     *
     * The price column follows the catalogue feed (`price ?? base_price`, line ~1035) so what the
     * customer was shown is what they are charged. Quantity is floored at the product's MOQ — a
     * wholesale shop's minimum is not advisory, and the browser now opens every Add at that number
     * anyway, so a lower quantity can only arrive from a crafted request. Modifier deltas are
     * clamped at zero so they cannot be used to discount a line.
     *
     * @param  array<int,mixed> $items
     * @return array{0:array<int,array>,1:array<int,string>,2:float,3:array<int,string>}
     *         [clean lines, text parts, total, names that matched no live product]
     */
    private function repriceCart(Tenant $tenant, array $items): array
    {
        $wanted = [];
        foreach ($items as $it) {
            if (! is_array($it)) continue;
            $n = $this->cartKey((string) ($it['name'] ?? ''));
            if ($n !== '') $wanted[$n] = true;
        }

        $byName = [];
        if ($wanted !== []) {
            $rows = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('active', true)->whereNull('archived_at')
                ->get(['id', 'name', 'price', 'piece_price', 'base_price', 'moq', 'pack_size']);
            foreach ($rows as $p) {
                $k = $this->cartKey((string) $p->name);
                if ($k === '' || isset($byName[$k])) continue;      // first wins; duplicates are a data fault
                $byName[$k] = $p;
            }
        }

        // Loose / per-piece retail: a carton product the customer chose to buy as single pieces.
        // Off unless the tenant turned it on. Markup default 20%.
        $looseOn  = (bool) $tenant->setting('loose_enabled', false);
        $loosePct = (float) $tenant->setting('loose_markup_pct', 20);

        $clean = []; $textParts = []; $total = 0.0; $unpriced = [];
        foreach ($items as $it) {
            if (! is_array($it)) continue;
            $pname = trim((string) ($it['name'] ?? ''));
            if ($pname === '') continue;

            // The cart stores "Product + Modifier A, Modifier B" in one string; the product is the
            // part before the first " + ". Same split the line-item writer below already does.
            $base = trim((string) preg_replace('/\s+\+\s+.*$/', '', $pname));
            $p    = $byName[$this->cartKey($base)] ?? null;
            if (! $p) { $unpriced[] = $base !== '' ? $base : $pname; continue; }

            $price = (float) ($p->price ?? $p->base_price ?? 0);
            $qty   = max(1, (int) ($it['qty'] ?? 1));

            // A loose line prices per PIECE: carton price ÷ pack × (1 + markup). Derived here so the
            // client can never dictate it; the carton MOQ does not apply (loose minimum is 1). Only
            // honoured when the tenant enabled loose selling and the product is genuinely carton-packed.
            $pack  = (int) ($p->pack_size ?? 0);
            $loose = $looseOn && ! empty($it['loose']) && $pack > 1 && $price > 0;
            if ($loose) {
                // Explicit per-product piece price wins; otherwise carton price ÷ pack × (1 + markup).
                $override = isset($p->piece_price) && $p->piece_price !== null ? (float) $p->piece_price : null;
                $price = \App\Services\Pricing::piecePrice($override, $price, $pack, $loosePct, 2);
            } else {
                $moq = (int) ($p->moq ?? 0);
                if ($moq > 1 && $qty < $moq) $qty = $moq;
            }

            $mods = [];
            if (! empty($it['modifiers']) && is_array($it['modifiers'])) {
                foreach ($it['modifiers'] as $m) {
                    if (! is_array($m)) continue;
                    $mn = trim((string) ($m['name'] ?? ''));
                    if ($mn === '') continue;
                    $delta = max(0.0, (float) ($m['price_delta'] ?? 0));
                    $mods[] = ['group' => (string) ($m['group'] ?? ''), 'name' => $mn, 'price_delta' => $delta];
                    $price += $delta;
                }
            }

            $display = (string) $p->name . ($mods ? ' + ' . implode(', ', array_map(fn ($m) => $m['name'], $mods)) : '');
            $clean[]     = ['name' => $display, 'qty' => $qty, 'price' => $price, 'modifiers' => $mods, 'product_id' => (int) $p->id, 'unit' => $loose ? 'piece' : ''];
            $textParts[] = $qty . 'x ' . $display . ($loose ? ' (per piece)' : '');
            $total      += $qty * $price;
        }

        return [$clean, $textParts, round($total, 2), array_values(array_unique($unpriced))];
    }

    /** The one normalisation for cart-name matching: case-folded, inner whitespace collapsed. */
    private function cartKey(string $n): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($n))));
    }

    /** Create a real order from the web cart. */
    public function placeOrder(Request $r, ?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);

        if ($resp = $this->botGuard($r, $tenant, 'order')) {
            return $resp;
        }

        $name  = trim((string) $r->input('name', ''));
        $phone = preg_replace('/[^0-9]/', '', (string) $r->input('phone', ''));
        $items = $r->input('items', []);

        if ($name === '' || strlen($phone) < 7 || ! is_array($items) || ! count($items)) {
            return response()->json(['ok' => false, 'error' => 'Please add items and your name + WhatsApp number.'], 422);
        }

        // v5.05 — THE SERVER PRICES THE CART. Until now `price` and `total` came from the POST body
        // and were never checked against the catalogue, so anybody could order at a price they chose
        // (launch audit P0-1). Every line is now re-read from `products` by exact, case-folded name —
        // the same match `OrderItemSync` uses — and the client's numbers decide nothing.
        [$clean, $textParts, $calcTotal, $unpriced] = $this->repriceCart($tenant, $items);

        if ($unpriced !== []) {
            // A line matching no live product cannot be priced, and guessing a price is exactly the
            // hole this closes. Ask for a refresh instead of inventing a number.
            return response()->json([
                'ok' => false, 'error' => 'stale_cart',
                'message' => 'Some items are no longer available: ' . implode(', ', array_slice($unpriced, 0, 3))
                    . (count($unpriced) > 3 ? ' and ' . (count($unpriced) - 3) . ' more' : '')
                    . '. Please refresh the page and try again.',
            ], 422);
        }
        if (! count($clean)) {
            return response()->json(['ok' => false, 'error' => 'Your cart is empty.'], 422);
        }

        // --- Segment-aware credit guard (Phase 1, fail-open) ---------------------------------
        // Wholesale / institutional customers may order on credit. We HARD-block only when the
        // account is already over its limit (canSell=false — a currency-safe check straight from
        // CustomerInsight); an order that would merely push over the limit is allowed but flagged
        // for staff. Anything uncertain or erroring → allow the order (fail open): a wrongly blocked
        // sale is worse than one that staff review in the panel, where they have full control.
        $orderTotal = $calcTotal;                        // v5.05 — server-computed; `total` is ignored
        $onCredit = filter_var($r->input('on_credit', false), FILTER_VALIDATE_BOOLEAN)
            || stripos((string) $r->input('payment', ''), 'credit') !== false;
        $creditNote = '';
        if ($onCredit) {
            try {
                $buyer = app(\App\Services\Storefront\BuyerContext::class)->for($tenant, $phone);
                $cr = is_array($buyer['credit'] ?? null) ? $buyer['credit'] : null;
                if (in_array($buyer['mode'] ?? 'retail', ['wholesale', 'institutional'], true) && $cr) {
                    if (($cr['canSell'] ?? true) === false) {
                        return response()->json([
                            'ok' => false, 'error' => 'credit_blocked',
                            'message' => 'Your account is over its credit limit. Please settle your balance to order on credit, or choose cash / mobile money.',
                        ], 422);
                    }
                    $avail = $cr['available'] ?? null;
                    if ($avail !== null
                        && strtoupper($this->currency($tenant)) === strtoupper((string) ($cr['currency'] ?? ''))
                        && $orderTotal > (float) $avail) {
                        $creditNote = 'Credit review: order exceeds available credit ('
                            . number_format((float) $avail) . ' ' . $cr['currency'] . ').';
                    }
                }
            } catch (\Throwable $e) { /* fail open */ }
        }

        // Special instructions / change request (e.g. "in the thali remove dal & add kadhi").
        $note = trim((string) $r->input('note', ''));
        $note = mb_substr(preg_replace('/\s+/', ' ', $note), 0, 300);
        $itemsText = implode(', ', $textParts);
        if ($note !== '') {
            foreach ($clean as &$cl) {                       // stamp the thali line for the kitchen
                if (stripos($cl['name'], 'thali') !== false) $cl['name'] .= ' · Note: ' . $note;
            }
            unset($cl);
            $itemsText .= '  |  Note: ' . $note;             // always visible on the order + alerts
        }
        if ($creditNote !== '') {
            $itemsText .= '  |  ⚠ ' . $creditNote;           // flag over-available credit for staff review
        }
        // Address + optional map pin go into one human-readable location string for the rider.
        $location = trim((string) $r->input('location', ''));
        if ($maps = self::safeMapUrl((string) $r->input('maps_url', ''))) {
            $location = $location !== '' ? ($location . ' — ' . $maps) : $maps;
        }

        $o = new Order();
        $o->status         = 'New';
        $o->channel        = 'web';

        // Optional "schedule for later" — sets scheduled_for (feeds the panel's Scheduled page).
        $whenRaw = trim((string) $r->input('when', ''));
        if ($whenRaw !== '') {
            try {
                $when = \Illuminate\Support\Carbon::parse($whenRaw);
                if ($when->isFuture()) {
                    $o->scheduled_for = $when;
                    $o->sched_stage   = 'scheduled';
                    $itemsText .= '  |  Scheduled for: ' . $when->format('D j M, g:i A');
                }
            } catch (\Throwable $e) { /* ignore unparseable date */ }
        }
        $o->customer_name  = $name;
        $o->customer_phone = $phone;
        $o->items_json     = $clean;
        $o->items_text     = $itemsText;
        if ($note !== '') $o->notes = $note;             // shows on the Kitchen Board ticket
        $o->total          = $calcTotal;                 // v5.05 — never the client's `total`
        if ($pay = trim((string) $r->input('payment', ''))) $o->payment = $pay;
        elseif ($onCredit) $o->payment = 'Credit';
        if ($location !== '') $o->location = $location;

        // v5.05 — one order per submission (audit P1-1). `orders.idempotency_key` has carried a
        // UNIQUE index since June and POS has used it since; the public storefront — the one path
        // where the customer is on a Juba mobile connection that times out after the order has
        // already committed — was the path that skipped it. The key is the cart itself, so a
        // genuine second order of the same goods a minute later is a different key and still saves.
        $idem = 'web:' . $tenant->id . ':' . substr(sha1($phone . '|' . $itemsText . '|' . $calcTotal
                 . '|' . now()->format('Y-m-d H:i')), 0, 40);
        $existing = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idem)->first();
        if ($existing) {
            return response()->json([
                'ok' => true, 'order_no' => (string) $existing->order_no, 'duplicate' => true,
                'track_url' => $existing->track_token ? url('/papi/track?id=' . $existing->id . '&t=' . $existing->track_token) : null,
            ]);
        }
        $o->idempotency_key = $idem;

        // The order and its line rows commit together, or neither does. Before this the order was
        // saved (and staff notified by the observer) and only THEN were the lines written outside
        // any transaction — so a failure in the loop left a committed, notified, item-less order
        // while the customer read "Network error, please try again" (audit P1-10).
        \Illuminate\Support\Facades\DB::transaction(function () use ($o, $clean) {
            $o->save(); // OrderObserver: assigns order_no + track_token, dispatches the notifications
            if (! $o->wasRecentlyCreated) return;
            foreach ($clean as $cl) {
                \App\Models\OrderItem::create([
                    'order_id'   => $o->id,
                    // resolved in repriceCart() by the same case-folded match, so a name whose case
                    // was edited in the panel no longer silently loses its product link
                    'product_id' => ($cl['product_id'] ?? 0) ?: null,
                    'name'       => $cl['name'],
                    'price'      => $cl['price'],
                    'qty'        => $cl['qty'],
                    // 'piece' marks a loose (per-piece) line so COGS costs it per piece, not per carton.
                    'unit'       => (! empty($cl['unit']) ? (string) $cl['unit'] : null),
                    'notes'      => null,
                    'modifiers'  => ! empty($cl['modifiers']) ? $cl['modifiers'] : null,
                ]);
            }
        });

        // Checkout opt-in: a "keep me updated on WhatsApp" tick records explicit marketing consent for
        // this number. Best-effort and never lifts a prior opt-out (recordOptIn is one-way). Only acts
        // when the customer actively ticked it — an absent/false flag records nothing.
        if ($o->wasRecentlyCreated && $r->boolean('marketing_opt_in')) {
            try {
                \App\Services\Comms\Marketing\MarketingConsent::recordOptIn($tenant, $phone, 'checkout');
            } catch (\Throwable $e) { /* consent capture must never fail an order */ }
        }

        return response()->json([
            'ok'        => true,
            'order_no'  => (string) $o->order_no,
            'track_url' => $o->track_token ? url('/papi/track?o=' . $o->id . '&t=' . $o->track_token) : null,
        ]);
    }

    /**
     * Quotation-first submit: turn the storefront cart into a professional Quotation (NOT an Order).
     *
     * This is the B2B trading flow — the customer receives a quotation instantly, then collaborates
     * on it and approves it, at which point it converts to a Sales Order. It is a pure sibling of
     * placeOrder() and reuses the FROZEN quotation engine end to end:
     *   QuotationWriteService::createFor()  → OrderCalculator pricing + branded PDF (QuotationService)
     *                                         + persists Quotation/QuotationItem + WhatsApp send.
     * No quotation logic, pricing, numbering or PDF code is duplicated here.
     */
    public function requestQuote(Request $r, ?string $shop = null)
    {
        [$tenant, $base] = $this->resolveShop($shop);

        if ($resp = $this->botGuard($r, $tenant, 'quote')) {
            return $resp;
        }

        $name  = trim((string) $r->input('name', ''));
        $phone = preg_replace('/[^0-9]/', '', (string) $r->input('phone', ''));
        $items = $r->input('items', []);

        if ($name === '' || strlen($phone) < 7 || ! is_array($items) || ! count($items)) {
            return response()->json(['ok' => false, 'error' => 'Please add items and your name + WhatsApp number.'], 422);
        }

        // v4.9.1 — a valid dealer token makes the SERVER the price authority: every line is
        // re-priced through PriceResolver (client-sent prices ignored for dealers). The token's
        // phone must belong to an enrolled dealer of THIS tenant.
        $dealerProfile = null;
        $dealerPct = null; $dealerFloor = 0.0; $dealerPriceCol = false; $productsByName = null;
        if ($tok = trim((string) $r->input('dealer_token', ''))) {
            $auth = \App\Services\Pricing\DealerAuth::check($tok);
            if ($auth && $auth['tenant'] === (int) $tenant->id) {
                $dealerProfile = \App\Services\Pricing\PriceResolver::profileFor($tenant, $auth['phone']);
                if ($dealerProfile) {
                    $dealerPct = \App\Services\Pricing\PriceResolver::pctFor($tenant, $dealerProfile);
                    $dealerFloor = \App\Services\Pricing\PriceResolver::floorFor($tenant);
                    $dealerPriceCol = \Illuminate\Support\Facades\Schema::hasColumn('products', 'dealer_price');
                    $productsByName = Product::withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)->where('active', true)->whereNull('archived_at')
                        ->get(array_merge(['id', 'name', 'price', 'cost_price'], $dealerPriceCol ? ['dealer_price'] : []))
                        ->keyBy(fn ($p) => mb_strtolower(trim((string) $p->name)));
                }
            }
        }

        // v5.05 — the catalogue prices this cart, exactly as it prices an order (audit P0-1). The
        // quote path was the worse half of that defect: a crafted price became a NUMBERED, PDF'd
        // quotation the portal can approve into a real sales order. `$repriced` is keyed by the
        // display name repriceCart() returns, which is the catalogue's own spelling.
        [$repricedLines, , , $quoteUnpriced] = $this->repriceCart($tenant, $items);
        if ($quoteUnpriced !== []) {
            return response()->json([
                'ok' => false, 'error' => 'stale_cart',
                'message' => 'Some items are no longer available: ' . implode(', ', array_slice($quoteUnpriced, 0, 3))
                    . '. Please refresh the page and try again.',
            ], 422);
        }

        // Map the storefront cart to the line shape the frozen engine expects ({name, qty, price}).
        $lines = [];
        foreach ($repricedLines as $it) {
            $pname = trim((string) ($it['name'] ?? ''));
            if ($pname === '') {
                continue;
            }
            // A loose line is priced per piece; label it so the quotation doesn't read as N cartons.
            // Keep $pname clean for the dealer lookup below; only the printed name gets the suffix.
            $isLoose = (string) ($it['unit'] ?? '') === 'piece';
            $price = (isset($it['price']) && $it['price'] !== '' && $it['price'] !== null) ? (float) $it['price'] : null;
            if ($dealerProfile && $productsByName) {
                $p = $productsByName->get(mb_strtolower($pname));
                if ($p) {
                    [$dp] = \App\Services\Pricing\PriceResolver::compute(
                        (float) ($p->price ?? 0),
                        $p->cost_price !== null ? (float) $p->cost_price : null,
                        $dealerPriceCol && $p->dealer_price !== null ? (float) $p->dealer_price : null,
                        $dealerPct, $dealerFloor
                    );
                    $price = $dp;
                }
            }
            $lines[] = [
                'name'  => $pname . ($isLoose ? ' (per piece)' : ''),
                'qty'   => max(1, (int) ($it['qty'] ?? 1)),
                'price' => $price,
            ];
        }
        if (! $lines) {
            return response()->json(['ok' => false, 'error' => 'Your cart is empty.'], 422);
        }

        // Reuse the frozen engine: prices, renders the branded PDF, persists, sends WhatsApp.
        $res = app(\App\Services\Sales\QuotationWriteService::class)
            ->createFor($tenant, $phone, $name, $lines, $this->currency($tenant), true);

        if (! ($res['ok'] ?? false) || empty($res['id'])) {
            return response()->json([
                'ok'    => false,
                'error' => (string) ($res['message'] ?? 'Could not create the quotation. Please try again shortly.'),
            ], 422);
        }

        // Checkout opt-in (quotation path): a ticked "keep me updated" records explicit marketing consent.
        // Best-effort; never lifts a prior opt-out; never fails the quotation.
        if ($r->boolean('marketing_opt_in')) {
            try {
                \App\Services\Comms\Marketing\MarketingConsent::recordOptIn($tenant, $phone, 'checkout');
            } catch (\Throwable $e) { /* consent capture must never fail a quotation */ }
        }

        // Stamp storefront extras (delivery address, note, source) onto the persisted record and
        // read back the public portal token (auto-assigned by the Quotation model on create).
        $q = \App\Models\Quotation::find($res['id']);
        if ($q) {
            $location = trim((string) $r->input('location', ''));
            if ($maps = self::safeMapUrl((string) $r->input('maps_url', ''))) {
                $location = $location !== '' ? ($location . ' — ' . $maps) : $maps;
            }
            $note  = mb_substr(trim((string) $r->input('note', '')), 0, 300);
            $dirty = false;
            if ($location !== '' && (string) $q->delivery_address === '') { $q->delivery_address = $location; $dirty = true; }
            if ($note !== '' && (string) $q->remarks === '')             { $q->remarks = $note; $dirty = true; }
            if ((string) $q->source !== 'web')                            { $q->source = 'web'; $dirty = true; }
            if ($dirty) {
                $q->save();
            }

            // Regenerate the PDF now that the portal token exists, so the delivered PDF carries the
            // QR / live-portal link. Best-effort — the quotation already has a valid PDF from createFor.
            try {
                $svc = app(\App\Services\Bot\QuotationService::class);
                if ($svc->available() && $q->public_token) {
                    $lines = $q->items->map(fn ($it) => [
                        'name' => (string) $it->name, 'qty' => (int) $it->qty, 'price' => (float) $it->unit_price,
                        'sum' => (float) ($it->line_total ?: $it->qty * $it->unit_price), 'unit' => (string) ($it->unit_label ?? ''),
                        'image' => (string) ($it->image_url ?? ''), 'matched' => true,
                    ])->all();
                    $doc = $svc->generate($tenant, (string) $q->customer_phone, (string) $q->customer_name,
                        ['lines' => $lines, 'currency' => (string) $q->currency, 'total' => (float) $q->total],
                        (string) $q->quote_no, url(rtrim($base, '/') . '/q/' . $q->public_token), (float) ($q->freight ?? 0));
                    if ($doc && ! empty($doc['path'])) {
                        $q->forceFill(['pdf_path' => $doc['path']])->save();
                    }
                }
            } catch (\Throwable $e) { /* keep the createFor PDF */ }
        }

        $token = $q?->public_token;

        return response()->json([
            'ok'          => true,
            'quote_no'    => (string) $res['quote_no'],
            'id'          => $res['id'],
            'total'       => (float) ($res['total'] ?? 0),
            'currency'    => (string) ($res['currency'] ?? $this->currency($tenant)),
            'valid_until' => optional($q?->valid_until)->toDateString(),
            'portal_url'  => $token ? url(rtrim($base, '/') . '/q/' . $token) : null,
            'pdf_url'     => $token ? url(rtrim($base, '/') . '/q/' . $token . '/pdf') : null,
            'sent'        => (bool) ($res['sent'] ?? false),
        ]);
    }

    /**
     * Record a storefront interaction (view / add / checkout) into the shared product_events log.
     * Fire-and-forget from the shop; never throws (ProductEvents swallows errors), lightly IP-throttled
     * so a misbehaving client can't flood the table. This is the live signal behind the Trending rail
     * and the future storefront BI dashboards — additive, no engine writes.
     */
    public function recordEvent(Request $r, ?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);

        $ipKey = 'sfevt:' . $tenant->id . ':' . $r->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($ipKey, 600)) {
            return response()->json(['ok' => true])->header('Cache-Control', 'no-store'); // silently drop
        }
        \Illuminate\Support\Facades\RateLimiter::hit($ipKey, 60);

        $event = strtolower(trim((string) $r->input('event', 'view')));
        // v4.81 (M-CHAT-4) — the three chat facts only the BROWSER knows (open, add-from-chat,
        // order-after-chat). Server-side stages live in bot_events already. Additive: no
        // existing event changes meaning, and nothing sends these unless the gated chat UI is on.
        if (! in_array($event, ['view', 'add', 'gallery', 'checkout', 'search', 'whatsapp', 'chat_open', 'chat_add', 'chat_order'], true)) {
            $event = 'view';
        }

        $ids = $r->input('product_ids');
        if (is_array($ids)) {
            \App\Support\ProductEvents::logMany($tenant->id, array_map('intval', $ids), $event);
        } else {
            $pid = (int) $r->input('product_id', 0);
            \App\Support\ProductEvents::log($tenant->id, $pid ?: null, $event);
        }

        // A storefront search also carries its query + result count — feed it into search_events so
        // it shows up in the existing "Top searches" and "Search gaps" panel reports (which read that
        // table). product_events has nowhere to keep the query text; search_events does.
        if ($event === 'search') {
            $q = trim((string) $r->input('q', ''));
            $results = (int) $r->input('results', 0);
            try {
                \App\Models\SearchEvent::create([
                    'tenant_id'  => $tenant->id,
                    'type'       => $results > 0 ? 'search' : 'zero_result',
                    'query'      => $q !== '' ? mb_substr($q, 0, 180) : null,
                    'results'    => max(0, $results),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // best-effort analytics
            }
        }

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    /**
     * Capture a front-end JavaScript error from the storefront so client crashes surface in the
     * same place as server errors (structured log + Sentry if configured) — previously only
     * server-side exceptions were visible. Fire-and-forget, tightly IP-rate-limited, never trusts
     * the payload beyond a few short strings.
     */
    public function clientError(Request $r, ?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);

        $ipKey = 'sfjserr:' . $tenant->id . ':' . $r->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($ipKey, 60)) {
            return response()->json(['ok' => true])->header('Cache-Control', 'no-store'); // silently drop floods
        }
        \Illuminate\Support\Facades\RateLimiter::hit($ipKey, 60);

        $cut = fn ($v, $n) => mb_substr(trim((string) $v), 0, $n);
        $ctx = [
            'tenant_id' => $tenant->id,
            'message'   => $cut($r->input('message'), 500),
            'source'    => $cut($r->input('source'), 300),
            'line'      => (int) $r->input('line', 0),
            'col'       => (int) $r->input('col', 0),
            'stack'     => $cut($r->input('stack'), 2000),
            'url'       => $cut($r->input('url'), 500),
            'ua'        => $cut($r->userAgent(), 300),
        ];

        if ($ctx['message'] !== '') {
            \Illuminate\Support\Facades\Log::warning('storefront.js_error', $ctx);
            if (function_exists('Sentry\\captureMessage')) {
                try {
                    \Sentry\configureScope(function ($scope) use ($ctx) { $scope->setContext('storefront', $ctx); });
                    \Sentry\captureMessage('Storefront JS error: ' . $ctx['message']);
                } catch (\Throwable $e) {
                    // never let error reporting throw
                }
            }
        }

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    /**
     * Product ids trending in the shop over the last few days, weighted (add/checkout beat a view).
     * Cached briefly so the home rail is fresh but the aggregate doesn't run on every catalogue load.
     *
     * @return array<int,int>
     */
    private function trendingIds(Tenant $tenant): array
    {
        try {
            return \Illuminate\Support\Facades\Cache::remember('trending:' . $tenant->id, now()->addMinutes(10), function () use ($tenant) {
                $ids = \Illuminate\Support\Facades\DB::table('product_events')
                    ->where('tenant_id', $tenant->id)
                    ->where('created_at', '>=', now()->subDays(3))
                    ->whereNotNull('product_id')
                    ->whereIn('event', ['view', 'add', 'checkout'])
                    ->select('product_id', \Illuminate\Support\Facades\DB::raw("sum(case event when 'checkout' then 5 when 'add' then 3 else 1 end) as score"))
                    ->groupBy('product_id')
                    ->orderByDesc('score')
                    ->limit(12)
                    ->pluck('product_id')
                    ->map(fn ($x) => (int) $x)
                    ->all();

                // v4.54 — this rail counts EVENTS and never joins products, so on its own it would
                // happily publish a hidden id that no other surface exposes. A product hidden AFTER
                // people had already viewed it is exactly the case that leaks here.
                return \App\Services\Catalog\Visibility::visibleIds($tenant, $ids);
            });
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function resolveTheme($tenant): array
    {
        $presets = [
            'default' => [
                'accent' => '#0C831F', 'accentDark' => '#0A6E1A',
                'tagline' => '', 'searchHint' => '',
                'eyebrow' => '', 'trustLine' => '',
                'premiumTiles' => false, 'specChips' => false,
            ],
            'wholesale' => [
                'accent' => '#103A8C', 'accentDark' => '#0C2C6B',
                'tagline' => '', 'searchHint' => 'Search products…',
                'eyebrow' => '', 'trustLine' => '',
                'premiumTiles' => true, 'specChips' => true,
            ],
        ];
        $name = (string) $tenant->setting('theme', 'default');
        $t = $presets[$name] ?? $presets['default'];

        // Solar / equipment shops get the richer presentation by default (spec chips + premium tiles),
        // so specifications read well without any manual theme setup. Explicit per-tenant overrides
        // below still win, so a solar shop can turn either off.
        if (\App\Support\Vertical::of($tenant) === \App\Support\Vertical::SOLAR) {
            $t['premiumTiles'] = true;
            $t['specChips']    = true;
        }

        // text overrides — any non-empty tenant setting wins over the preset
        $text = [
            'accent' => 'theme_accent', 'accentDark' => 'theme_accent_dark',
            'tagline' => 'tagline', 'searchHint' => 'search_hint',
            'eyebrow' => 'eyebrow', 'trustLine' => 'trust_line',
        ];
        foreach ($text as $key => $setting) {
            $v = $tenant->setting($setting, null);
            if ($v !== null && $v !== '') $t[$key] = (string) $v;
        }
        // boolean feature overrides
        foreach (['premiumTiles' => 'premium_tiles', 'specChips' => 'spec_chips'] as $key => $setting) {
            $v = $tenant->setting($setting, null);
            if ($v !== null && $v !== '') $t[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
        }

        // Premium palette (2026-08) — expose the rest of the storefront's CSS variables as per-tenant
        // settings so a shop can commit to a full look (e.g. dark-green + gold solar) rather than just
        // recolouring the green accent. EMPTY BY DEFAULT: a tenant that sets none gets an empty `vars`
        // map and the SPA applies nothing, so every other shop renders byte-identical (freeze-safe,
        // per STOREFRONT-THEME-NOTES). Keys are a fixed server-side allow-list mapped to CSS vars;
        // values are owner-set colour strings. --green/--green-d stay handled by accent/accentDark above.
        $palette = [
            '--amber'    => 'theme_gold',       // primary CTA / highlight (solar gold)
            '--bg'       => 'theme_bg',          // page canvas
            '--ink'      => 'theme_ink',         // body text
            '--muted'    => 'theme_muted',
            '--line'     => 'theme_line',        // hairlines / borders
            '--card'     => 'theme_card',        // card surface
            '--tile'     => 'theme_tile',        // category-tile wash
            '--tileicon' => 'theme_tile_icon',
        ];
        $vars = [];
        foreach ($palette as $cssVar => $setting) {
            $v = $tenant->setting($setting, null);
            if ($v !== null && $v !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$|^(?:rgb|hsl)a?\([\d%.,\/\s]+\)$/', trim((string) $v))) {
                $vars[$cssVar] = trim((string) $v);
            }
        }
        $t['vars'] = $vars;

        return $t;
    }

    private function initials(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9 ]/', '', $name);
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $a = strtoupper(substr($parts[0] ?? 'S', 0, 1));
        $b = strtoupper(substr($parts[1] ?? ($parts[0] ?? 'H'), count($parts) > 1 ? 0 : 1, 1));
        return ($a . $b) ?: 'SH';
    }

    private function branches(Tenant $tenant): array
    {
        $raw = $tenant->setting('branches', []);

        // v5.05 (launch audit P1-7) — NOTHING writes settings['branches']. The panel's branch CRUD
        // writes the `branches` TABLE, and there is no such key in SettingsSchema, so this returned
        // [] on every shop and shop.html fell back to a single invented branch at Kampala's
        // coordinates. On a two-city shop that means the second city can never be chosen and a real
        // Juba customer who shares their location is measured ~1,000 km from "the store" and told
        // to message us about delivery. Read the table the panel actually writes.
        if (! is_array($raw) || $raw === []) {
            try {
                $raw = \App\Models\Branch::where('tenant_id', $tenant->id)
                    ->orderBy('name')->get(['name', 'lat', 'lng'])
                    ->map(fn ($b) => ['name' => (string) $b->name, 'lat' => $b->lat, 'lng' => $b->lng])
                    ->all();
            } catch (\Throwable $e) {
                $raw = [];      // a missing column or table must never take the storefront down
            }
        }
        if (! is_array($raw)) return [];
        $out = [];
        foreach ($raw as $b) {
            $lat = (float) ($b['lat'] ?? 0);
            $lng = (float) ($b['lng'] ?? 0);
            if ($lat == 0.0 && $lng == 0.0) continue;
            $out[] = ['name' => (string) ($b['name'] ?? 'Branch'), 'lat' => $lat, 'lng' => $lng];
        }
        return $out;
    }

    /** All active categories with product counts — the footer and sitemap share this. */
    private function liveCategories(Tenant $tenant): array
    {
        return \Illuminate\Support\Facades\Cache::remember('sf_cats:'.$tenant->id, 600, function () use ($tenant) {
            // v4.54 — a hidden department must not appear as a chip, must not get a /c/ landing
            // page, and must not inflate a public count.
            return \App\Services\Catalog\Visibility::scope(\App\Models\Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('active', true), $tenant)
                ->whereNotNull('category')->where('category', '!=', '')
                ->selectRaw('category, count(*) as n')->groupBy('category')
                ->orderBy('category')->pluck('n', 'category')->all();
        });
    }

    /** The Blinkit-class footer: categories, industries, company, contact, trust, JSON-LD.
     *  NO public price-PDF link by design (2026-07-19): the catalogue travels only through
     *  WhatsApp, inside conversations the relationship engine controls. */
    private function premiumFooter(Tenant $tenant, string $base): string
    {
        $cats = $this->liveCategories($tenant);
        $name = e((string) $tenant->name);
        $addr = e((string) $tenant->setting('shop_address', ''));
        $site = (string) $tenant->setting('website', '');
        $waCh = \App\Models\WhatsappChannel::where('tenant_id', $tenant->id)
            ->orderByRaw('case when instance = ? then 0 when is_primary then 1 else 2 end', [(string) $tenant->whatsapp_instance])
            ->first();
        $wa = preg_replace('/[^0-9]/', '', (string) optional($waCh)->number);
        $b = $base ?: '';

        $catLinks = '';
        foreach ($cats as $cat => $n) {
            $catLinks .= '<li><a href="'.$b.'/c/'.\Illuminate\Support\Str::slug($cat).'">'.e($cat).' <em>('.$n.')</em></a></li>';
        }

        $indLinks = '';
        $banners = (array) data_get($tenant->settings, 'business_banners.businesses', []);
        foreach ($banners as $biz) {
            if (empty($biz['active'])) continue;
            $target = (string) data_get($biz, 'collections.0.cat', '') ?: (string) data_get($biz, 'match.categories.0', '');
            if ($target === '' || ! isset($cats[$target])) continue;
            $indLinks .= '<li><a href="'.$b.'/c/'.\Illuminate\Support\Str::slug($target).'">'.e((string) ($biz['label'] ?? '')).'</a></li>';
        }

        $trust = '';
        foreach (['Genuine products', 'Wholesale prices', 'Fast delivery — Juba & Kampala', 'Order on WhatsApp', 'Team support'] as $tb) {
            $trust .= '<li>&#10004; '.$tb.'</li>';
        }

        $company = '';
        if ($addr !== '') $company .= '<li>'.nl2br($addr).'</li>';
        if ($wa !== '') {
            $company .= '<li><a href="https://wa.me/'.$wa.'" rel="noopener">WhatsApp: +'.$wa.'</a></li>';
            $company .= '<li><a href="https://wa.me/'.$wa.'?text='.rawurlencode('Hi! Please send me your catalogue').'" rel="noopener">Get our catalogue on WhatsApp</a></li>';
        }
        $company .= '<li><a href="#" onclick="event.preventDefault();showFaq()">How to order &amp; FAQ</a></li>';
        $company .= '<li><a href="#" onclick="event.preventDefault();var s=document.getElementById(\'staffLink\');if(s)s.click();">Staff login</a></li>';

        $jsonLd = json_encode(['@context' => 'https://schema.org', '@type' => 'LocalBusiness',
            'name' => (string) $tenant->name,
            'url' => $site !== '' ? $site : url($b.'/'),
            'address' => (string) $tenant->setting('shop_address', ''),
            'telephone' => $wa !== '' ? '+'.$wa : '',
            'priceRange' => '$$',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $css = '<style>'
            .'#sfoot{background:var(--card,#fff);border-top:1px solid var(--line,#e5e7eb);margin-top:48px;padding:40px 20px calc(96px + env(safe-area-inset-bottom,0px));font-size:14px;color:#374151}'
            .'#sfoot .wrap{max-width:1180px;margin:0 auto;display:grid;gap:36px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr))}'
            .'#sfoot h4{font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;margin:0 0 14px}'
            .'#sfoot ul{list-style:none;margin:0;padding:0}#sfoot li{margin:0 0 9px}'
            .'#sfoot a{color:#374151;text-decoration:none;transition:color .15s}#sfoot a:hover{color:var(--green-d,#0b7a3b)}'
            .'#sfoot em{color:#9ca3af;font-style:normal;font-size:12px}'
            .'#sfoot .brandline{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;border-top:1px solid var(--line,#eef0f3);padding-top:22px;color:#6b7280;font-size:13px}'
            .'#sfoot .sftrust{background:transparent!important;color:inherit!important;padding:0!important}#sfoot .sftrust li{color:#0b7a3b;font-weight:500;background:transparent}'
            .'@media (prefers-color-scheme:dark){#sfoot{background:#111827;border-color:#1f2937;color:#d1d5db}#sfoot a{color:#d1d5db}#sfoot h4{color:#9ca3af}#sfoot .brandline{border-color:#1f2937;color:#9ca3af}}'
            .'</style>';

        return $css
            .'<footer id="sfoot"><div class="wrap">'
            .'<section><h4>Shop by category</h4><nav><ul>'.$catLinks.'</ul></nav></section>'
            .($indLinks !== '' ? '<section><h4>Shop by industry</h4><nav><ul>'.$indLinks.'</ul></nav></section>' : '')
            .'<section><h4>'.$name.'</h4><ul>'.$company.'</ul></section>'
            .'<section class="sftrust"><h4>Why shop with us</h4><ul>'.$trust.'</ul></section>'
            .'<div class="brandline"><span>&copy; '.date('Y').' '.$name.' &middot; Wholesale &amp; retail, Kampala &rarr; Juba</span>'
            .'<span>Order anytime on WhatsApp &middot; team confirms every delivery</span></div>'
            .'</div><script type="application/ld+json">'.$jsonLd.'</script></footer>';
    }

    /** SEO landing: /c/{slug} → the shop, category-preset, with unique title + meta. */
    public function category(string $slugOrShop, ?string $slug = null)
    {
        $tenant = $slug === null
            ? Tenant::byCustomDomain(request()->getHost())
            : Tenant::where('slug', $slugOrShop)->first();
        abort_unless($tenant, 404);
        if ($this->ispEnabled($tenant)) {                       // ISP: no category pages with a basket — the plans page
            return redirect(($slug === null ? '' : '/' . strtolower($slugOrShop)) . '/shop#plans', 301);
        }
        $want = $slug ?? $slugOrShop;
        foreach (array_keys($this->liveCategories($tenant)) as $cat) {
            if (\Illuminate\Support\Str::slug($cat) === $want) {
                $base = $slug === null ? '' : '/'.$tenant->slug;
                return $this->renderShop($tenant, $base, $cat);
            }
        }
        abort(404);
    }

    /** /sitemap.xml — root + every category landing, on the tenant's own domain. */
    public function sitemap()
    {
        $tenant = Tenant::byCustomDomain(request()->getHost());
        abort_unless($tenant, 404);
        $urls = ['<url><loc>'.e(url('/')).'</loc><changefreq>daily</changefreq></url>'];
        if ($this->ispEnabled($tenant)) {
            // ISP: the plans page plus the business's own website pages served as-is (never held ones,
            // never product or category pages — those redirect to the plans page).
            $urls[] = '<url><loc>'.e(url('/shop')).'</loc><changefreq>daily</changefreq></url>';
            if ($dir = TenantSiteController::bundleDir($tenant)) {
                $hold = (array) (TenantSiteController::manifest($dir)['hold'] ?? []);
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
                    if (str_ends_with($rel, '.html') && ! array_key_exists($rel, $hold) && preg_match('#^' . TenantSiteController::PAGE . '$#', $rel)) {
                        $urls[] = '<url><loc>'.e(url('/' . $rel)).'</loc><changefreq>monthly</changefreq></url>';
                    }
                }
            }

            return response('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>', 200)
                ->header('Content-Type', 'application/xml; charset=utf-8');
        }
        foreach (array_keys($this->liveCategories($tenant)) as $cat) {
            $urls[] = '<url><loc>'.e(url('/c/'.\Illuminate\Support\Str::slug($cat))).'</loc><changefreq>weekly</changefreq></url>';
        }
        // v4.27 — every active product on a normal shop has its own page at /p/{slug}-{id}.
        if (\App\Support\Vertical::of($tenant) !== \App\Support\Vertical::AUTO_PARTS) {
            // v4.54 — a hidden product must not be handed to Google. The sitemap leaks harder
            // than any other surface: it publishes the URL even when nothing links to it.
            \App\Services\Catalog\Visibility::scope(Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true), $tenant)
                ->orderBy('id')->limit(5000)->get(['id', 'name'])
                ->each(function ($p) use (&$urls) {
                    $urls[] = '<url><loc>' . e(url('/p/' . self::productSlug($p))) . '</loc><changefreq>weekly</changefreq></url>';
                });
        }
        // v4.11 — real URLs: every active part gets its own indexable page on the SPA.
        if (\App\Support\Vertical::of($tenant) === \App\Support\Vertical::AUTO_PARTS) {
            $urls[] = '<url><loc>'.e(url('/parts')).'</loc><changefreq>daily</changefreq></url>';
            Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)
                ->orderBy('id')->limit(5000)->get(['id'])
                ->each(function ($p) use (&$urls) {
                    $urls[] = '<url><loc>'.e(url('/part/'.$p->id)).'</loc><changefreq>weekly</changefreq></url>';
                });
        }

        return response('<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>',
            200, ['Content-Type' => 'application/xml']);
    }
}
