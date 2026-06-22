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
        // 2) categories table image_url column (Day2Days category images): name => url
        $fromTable = [];
        foreach (\App\Models\Category::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('image_url')->where('image_url', '!=', '')
                ->pluck('image_url', 'name') as $cname => $curl) {
            $fromTable[(string) $cname] = $this->imageUrl((string) $curl);
        }
        $fallback = [];
        foreach ($rows as $row) {
            $cat = (string) ($row['Category'] ?? 'Other');
            $img = (string) ($row['Image'] ?? '');
            if ($img !== '' && ! isset($fallback[$cat])) $fallback[$cat] = $img;
        }
        return $explicit + $fromTable + $fallback;   // priority: tenant setting > categories.image_url column > product image
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
        ];

        $path = resource_path('storefront/shop.html');
        $html = is_file($path) ? file_get_contents($path) : '<h1>Shop unavailable</h1>';
        $html = str_replace('__SHOP_CONFIG__', json_encode($cfg, JSON_UNESCAPED_UNICODE), $html);

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
                ->get(['id', 'name', 'category', 'keywords', 'base_price', 'price', 'stock', 'image_url', 'display_order'])
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
