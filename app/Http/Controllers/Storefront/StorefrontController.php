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
    public function show(string $shop)
    {
        $tenant = $this->tenant($shop);

        $cfg = [
            'name'         => (string) $tenant->name,
            'slug'         => (string) $tenant->slug,
            'initials'     => $this->initials((string) $tenant->name),
            'currency'     => $this->currency($tenant),
            'logo'         => $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '',
            'waNumber'     => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
            'city'         => (string) $tenant->setting('city', ''),
            'catalogueUrl' => url('/' . $tenant->slug . '/catalogue'),
            'orderUrl'     => url('/' . $tenant->slug . '/order'),
            'panelUrl'     => url('/panel'),
            'branches'     => $this->branches($tenant),
            'delivery'     => (object) ($tenant->setting('delivery', []) ?: []),
            'vertical'     => \App\Support\Vertical::of($tenant),
            'theme'        => $this->resolveTheme($tenant),
        ];

        $path = resource_path('storefront/shop.html');
        $html = is_file($path) ? file_get_contents($path) : '<h1>Shop unavailable</h1>';
        $html = str_replace('__SHOP_CONFIG__', json_encode($cfg, JSON_UNESCAPED_UNICODE), $html);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Tenant landing at /{shop}. Manufacturers get the brand site; everyone else gets the shop.
     * The shop itself always lives at /{shop}/shop, so brand-site order links point there.
     */
    public function landing(string $shop)
    {
        $tenant = $this->tenant($shop);
        return $this->brandSiteEnabled($tenant) ? $this->brand($shop, $tenant) : $this->show($shop);
    }

    private function brandSiteEnabled($tenant): bool
    {
        $flag = $tenant->setting('brand_site', null);
        if ($flag !== null && $flag !== '') return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        return \App\Support\Vertical::of($tenant) === \App\Support\Vertical::MANUFACTURER;
    }

    /** Manufacturer brand site — content from tenant settings with sensible defaults. */
    public function brand(string $shop, $tenant = null)
    {
        $tenant = $tenant ?: $this->tenant($shop);
        $theme  = $this->resolveTheme($tenant);

        $defaultBrands = [
            ['name' => 'EuroPearl', 'color' => $theme['accent'], 'tag' => 'Truly white & very soft — premium virgin tissue',
             'items' => ['Toilet paper — 150 / 200 / 300 sheets, 2-ply', 'Copier paper — A4 80 GSM', 'Thermal & POS rolls'],
             'chips' => ['2-Ply', '100% Virgin', 'Premium']],
            ['name' => 'Angel Soft', 'color' => '#1C7A41', 'tag' => 'A piece of heaven — sophistication at the table',
             'items' => ['Paper serviettes & napkins', 'Virgin, 100 sheets · 300×300mm', '60 packs / carton'],
             'chips' => ['Virgin', 'Soft & Gentle', 'Carton']],
            ['name' => 'Orchid', 'color' => '#9A6A20', 'tag' => 'Everyday value — built for bulk supply',
             'items' => ['Blended economy toilet paper', 'Economy napkins', '100 rolls / carton'],
             'chips' => ['Economy', 'Bulk', 'Carton']],
        ];
        $defaultStats = [
            ['k' => '3 own brands', 'l' => 'EuroPearl · Angel Soft · Orchid'],
            ['k' => '100% Virgin Pulp', 'l' => 'Premium tissue grade'],
            ['k' => 'UNBS · ISO 9001', 'l' => 'Certified quality'],
            ['k' => 'Kampala & Juba', 'l' => 'Wholesale delivery'],
        ];

        $defaultFaq = [
            ['q' => 'How do I place an order?', 'a' => 'Browse the shop, tap "Add to order" on the items you want, then check out on WhatsApp. We confirm stock and arrange delivery.'],
            ['q' => 'What is the minimum order?', 'a' => 'Wholesale items sell by the carton with a minimum (often 3 cartons). Retail packs are available with no minimum.'],
            ['q' => 'Do you sell small quantities or single packs?', 'a' => 'Yes — we offer retail packs (2 and 4-roll) alongside full cartons for wholesale buyers.'],
            ['q' => 'Do you offer wholesale / trade pricing?', 'a' => 'Yes. Prices are per carton, direct from the factory — no middleman.'],
            ['q' => 'Do you deliver, and where?', 'a' => 'We deliver across Kampala and Juba, and nationwide on request. Delivery time is confirmed when we take your order.'],
            ['q' => 'How do I pay?', 'a' => 'Payment is arranged on WhatsApp when we confirm your order — for example Mobile Money or on delivery.'],
            ['q' => 'Are your products certified?', 'a' => 'Yes — UNBS certified, ISO 9001:2015, made from 100% virgin pulp.'],
            ['q' => 'Can I become a distributor or reseller?', 'a' => 'Yes. Use the "Become a distributor" form, or message us on WhatsApp with your area and the brands you want to carry.'],
            ['q' => 'Do you supply offices and institutions?', 'a' => 'Yes — we supply shops, offices, schools and institutions with bulk carton pricing and reliable repeat supply.'],
        ];

        $cfg = [
            'name'        => (string) $tenant->name,
            'initials'    => $this->initials((string) $tenant->name),
            'logo'        => $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '',
            'accent'      => $theme['accent'],
            'accentDark'  => $theme['accentDark'],
            'eyebrow'     => $theme['eyebrow'] ?: 'Made in Uganda',
            'heroTitle'   => (string) $tenant->setting('hero_title', 'Paper & tissue,<br>manufactured in Uganda.'),
            'heroText'    => (string) $tenant->setting('hero_text', 'We manufacture our own brands — virgin-pulp tissue, napkins and copier paper supplied to shops, offices and institutions across the region.'),
            'trustLine'   => $theme['trustLine'] ?: 'Manufacturer · 100% Virgin Pulp · UNBS & ISO 9001 · Wholesale Trade Pricing',
            'website'     => (string) $tenant->setting('website', ''),
            'phone'       => (string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number),
            'email'       => (string) $tenant->setting('public_email', ''),
            'address'     => (string) $tenant->setting('address', ''),
            'waNumber'    => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
            'currency'    => $this->currency($tenant),
            'shopUrl'     => url('/' . $tenant->slug . '/shop'),
            'catalogueUrl'=> url('/' . $tenant->slug . '/catalogue'),
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
    public function catalogue(string $shop)
    {
        $tenant = $this->tenant($shop);

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
                ->get(['id', 'name', 'category', 'keywords', 'base_price', 'price', 'stock', 'image_url', 'display_order', 'moq', 'pack_size', 'unit_label'])
                ->map(function ($p) use ($mods) {
                    return [
                        'Product Name' => (string) $p->name,
                        'Variant'      => '',
                        'Brand'        => '',
                        'Category'     => (string) ($p->category ?? 'Other'),
                        'Keywords'     => (string) ($p->keywords ?? ''),
                        'Price_UGX'    => (float) ($p->base_price ?? $p->price ?? 0),
                        'Stock'        => $p->stock === null ? null : (int) $p->stock,
                        'Image'        => $this->imageUrl($p->image_url),
                        'Modifiers'    => $mods[$p->id] ?? [],
                        'MOQ'          => $p->moq === null ? null : (int) $p->moq,
                        'PackSize'     => $p->pack_size === null ? null : (int) $p->pack_size,
                        'Unit'         => (string) ($p->unit_label ?? ''),
                        '_row'         => (int) $p->id,
                    ];
                })->values();
        });

        return response()->json([
            'products' => $rows,
            'branches' => $this->branches($tenant),
            'settings' => [
                'waNumber' => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
                'currency' => $this->currency($tenant),
                'delivery' => (object) ($tenant->setting('delivery', []) ?: []),
                'category_images' => (object) $this->categoryImages($tenant, $rows),
                'category_groups' => (object) ($tenant->setting('category_groups', []) ?: []),
                'thali' => $this->todayThali($tenant),
                'vertical' => \App\Support\Vertical::of($tenant),
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
    public function placeOrder(string $shop, Request $r)
    {
        $tenant = $this->tenant($shop);

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
}
