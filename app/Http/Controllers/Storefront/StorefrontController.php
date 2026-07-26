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
    private function resolveShop(?string $shop): array
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

    private function currency(Tenant $tenant): string
    {
        $c = (string) $tenant->setting('currency', 'UGX');
        return ($c === '' || strtolower($c) === 'auto') ? 'UGX' : strtoupper($c);
    }

    /** The shop page itself. */
    public function show(?string $shop = null)
    {
        [$tenant, $base] = $this->resolveShop($shop);
        return $this->renderShop($tenant, $base);
    }

    /** Render the shop HTML for a resolved tenant. $base is '' on a custom domain, '/{slug}' on the platform. */
    private function renderShop(Tenant $tenant, string $base, ?string $seoCat = null)
    {

        $cfg = [
            'name'         => (string) $tenant->name,
            'slug'         => (string) $tenant->slug,
            'base'         => $base,
            'initials'     => $this->initials((string) $tenant->name),
            'currency'     => $this->currency($tenant),
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
        if (! str_contains($html, 'og:title')) {
            $html = str_replace('</title>', '</title>'."\n".'<meta property="og:title" content="'.$safeName.'">'
                ."\n".'<meta property="og:description" content="Browse the catalogue and order on WhatsApp — '.$safeName.'">'
                .($cfg['logo'] ? "\n".'<meta property="og:image" content="'.e($cfg['logo']).'">' : ''), $html);
        }
        $html = str_replace('__SHOP_CONFIG__', json_encode($cfg, JSON_UNESCAPED_UNICODE), $html);

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
            $html = str_replace('</body>',
                '<script>if(!location.search){location.replace("'.($base ?: '').'/?cat='.rawurlencode($seoCat).'");}</script></body>', $html);
        }

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
        return $this->brandSiteEnabled($tenant) ? $this->brand($shop, $tenant, $base) : $this->renderShop($tenant, $base);
    }

    private function brandSiteEnabled($tenant): bool
    {
        $flag = $tenant->setting('brand_site', null);
        if ($flag !== null && $flag !== '') return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        return \App\Support\Vertical::of($tenant) === \App\Support\Vertical::MANUFACTURER;
    }

    /** Manufacturer brand site — content from tenant settings with sensible defaults. */
    public function brand(?string $shop = null, $tenant = null, ?string $base = null)
    {
        if ($tenant === null) { [$tenant, $base] = $this->resolveShop($shop); }
        if ($base === null)   { $base = '/' . (string) $tenant->slug; }
        $theme  = $this->resolveTheme($tenant);

        $defaultBrands = \App\Support\BrandDefaults::brands($theme['accent']);
        $defaultStats  = \App\Support\BrandDefaults::stats();
        $defaultFaq    = \App\Support\BrandDefaults::faq();
        $td            = \App\Support\BrandDefaults::text();

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

        $jflags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
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

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Public catalogue feed (same JSON shape the panel/products feed uses). */
    public function catalogue(?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);

        // Cache the built feed for a few minutes so a large catalogue is not re-hydrated
        // on every page load, and use toBase()+column select to avoid loading full models.
        $rows = \Illuminate\Support\Facades\Cache::remember('catalogue:' . $tenant->id, now()->addMinutes(5), function () {
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

            return Product::where('active', true)->orderByDesc('display_order')->orderBy('name')
                ->toBase()
                ->get(['id', 'name', 'description', 'category', 'brand', 'keywords', 'base_price', 'price', 'stock', 'image_url', 'display_order', 'moq', 'pack_size', 'unit_label', 'popularity', 'created_at'])
                ->map(function ($p) use ($mods) {
                    return [
                        'Product Name' => (string) $p->name,
                        'Variant'      => '',
                        'Brand'        => (string) ($p->brand ?? ''),
                        'Description'  => (string) ($p->description ?? ''),
                        'Category'     => (string) ($p->category ?? 'Other'),
                        'Keywords'     => (string) ($p->keywords ?? ''),
                        'Price_UGX'    => (float) ($p->price ?? $p->base_price ?? 0),   // price = selling truth (price-drift fix 2026-07-18)
                        'Stock'        => $p->stock === null ? null : (int) $p->stock,
                        'Image'        => $this->imageUrl($p->image_url),
                        'Modifiers'    => $mods[$p->id] ?? [],
                        'MOQ'          => $p->moq === null ? null : (int) $p->moq,
                        'PackSize'     => $p->pack_size === null ? null : (int) $p->pack_size,
                        'Unit'         => (string) ($p->unit_label ?? ''),
                        // Merchandising signals for the self-updating home rails (Popular / New arrivals).
                        'Popularity'   => (int) ($p->popularity ?? 0),
                        'Created'      => $p->created_at ? strtotime((string) $p->created_at) : 0,
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

        return response()->json([
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
        ])->header('Cache-Control', 'no-store');
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

    /** Create a real order from the web cart. */
    public function placeOrder(Request $r, ?string $shop = null)
    {
        [$tenant] = $this->resolveShop($shop);

        $name  = trim((string) $r->input('name', ''));
        $phone = preg_replace('/[^0-9]/', '', (string) $r->input('phone', ''));
        $items = $r->input('items', []);

        if ($name === '' || strlen($phone) < 7 || ! is_array($items) || ! count($items)) {
            return response()->json(['ok' => false, 'error' => 'Please add items and your name + WhatsApp number.'], 422);
        }

        $clean = [];
        $textParts = [];
        $calcTotal = 0.0;
        foreach ($items as $it) {
            $pname = trim((string) ($it['name'] ?? ''));
            if ($pname === '') continue;
            $qty   = max(1, (int) ($it['qty'] ?? 1));
            $price = (float) ($it['price'] ?? 0);
            $mods  = [];
            if (! empty($it['modifiers']) && is_array($it['modifiers'])) {
                foreach ($it['modifiers'] as $m) {
                    $mn = trim((string) ($m['name'] ?? ''));
                    if ($mn !== '') {
                        $mods[] = ['group' => (string) ($m['group'] ?? ''), 'name' => $mn, 'price_delta' => (float) ($m['price_delta'] ?? 0)];
                    }
                }
            }
            $display = $pname . ($mods ? ' + ' . implode(', ', array_map(fn ($m) => $m['name'], $mods)) : '');
            $clean[] = ['name' => $display, 'qty' => $qty, 'price' => $price, 'modifiers' => $mods];
            $textParts[] = $qty . 'x ' . $display;
            $calcTotal += $qty * $price;
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
        $orderTotal = $r->filled('total') ? (float) $r->input('total') : $calcTotal;
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
        if ($maps = trim((string) $r->input('maps_url', ''))) {
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
        $o->total          = $r->filled('total') ? (float) $r->input('total') : $calcTotal;
        if ($pay = trim((string) $r->input('payment', ''))) $o->payment = $pay;
        elseif ($onCredit) $o->payment = 'Credit';
        if ($location !== '') $o->location = $location;
        $o->save(); // OrderObserver: assigns order_no + track_token, dispatches NotifyOwnerNewOrder

        // Create line rows so the order shows its items on the Kitchen Board / panel — web orders
        // previously stored only items_json, which left the KOT ticket empty. Best-effort product_id.
        if ($o->wasRecentlyCreated) {
            foreach ($clean as $cl) {
                $base = preg_replace('/\s+\+\s+.*$/', '', (string) $cl['name']);
                $pid  = Product::where('name', $base)->value('id');
                \App\Models\OrderItem::create([
                    'order_id'   => $o->id,
                    'product_id' => $pid ?: null,
                    'name'       => $cl['name'],
                    'price'      => $cl['price'],
                    'qty'        => $cl['qty'],
                    'notes'      => null,
                    'modifiers'  => ! empty($cl['modifiers']) ? $cl['modifiers'] : null,
                ]);
            }
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

        $name  = trim((string) $r->input('name', ''));
        $phone = preg_replace('/[^0-9]/', '', (string) $r->input('phone', ''));
        $items = $r->input('items', []);

        if ($name === '' || strlen($phone) < 7 || ! is_array($items) || ! count($items)) {
            return response()->json(['ok' => false, 'error' => 'Please add items and your name + WhatsApp number.'], 422);
        }

        // Map the storefront cart to the line shape the frozen engine expects ({name, qty, price}).
        $lines = [];
        foreach ($items as $it) {
            $pname = trim((string) ($it['name'] ?? ''));
            if ($pname === '') {
                continue;
            }
            $lines[] = [
                'name'  => $pname,
                'qty'   => max(1, (int) ($it['qty'] ?? 1)),
                'price' => (isset($it['price']) && $it['price'] !== '' && $it['price'] !== null) ? (float) $it['price'] : null,
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

        // Stamp storefront extras (delivery address, note, source) onto the persisted record and
        // read back the public portal token (auto-assigned by the Quotation model on create).
        $q = \App\Models\Quotation::find($res['id']);
        if ($q) {
            $location = trim((string) $r->input('location', ''));
            if ($maps = trim((string) $r->input('maps_url', ''))) {
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
        if (! in_array($event, ['view', 'add', 'gallery', 'checkout'], true)) {
            $event = 'view';
        }

        $ids = $r->input('product_ids');
        if (is_array($ids)) {
            \App\Support\ProductEvents::logMany($tenant->id, array_map('intval', $ids), $event);
        } else {
            $pid = (int) $r->input('product_id', 0);
            \App\Support\ProductEvents::log($tenant->id, $pid ?: null, $event);
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
                return \Illuminate\Support\Facades\DB::table('product_events')
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
            return \App\Models\Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('active', true)
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
        foreach (array_keys($this->liveCategories($tenant)) as $cat) {
            $urls[] = '<url><loc>'.e(url('/c/'.\Illuminate\Support\Str::slug($cat))).'</loc><changefreq>weekly</changefreq></url>';
        }

        return response('<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>',
            200, ['Content-Type' => 'application/xml']);
    }
}
