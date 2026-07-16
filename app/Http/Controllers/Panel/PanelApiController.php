<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\LedgerEntry;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Campaign;
use App\Jobs\SendCampaign;
use App\Services\Marketing\AudienceResolver;
use App\Models\ReturnRecord;
use App\Models\Rider;
use App\Models\User;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * JSON API for the Family Shopper seller panel.
 *
 * The panel (resources/panel/seller.html) is the customer's existing UI, unchanged.
 * It calls these endpoints (GET, with query-string params for writes) and expects
 * the SAME JSON shapes its old n8n webhooks returned. We map our Eloquent models
 * to those exact shapes here. Tenant scoping is automatic: the route group runs
 * SetTenantFromUser, so every Order/Product query is filtered by the logged-in
 * staff member's tenant via the BelongsToTenant global scope.
 *
 * Phase 3a (this file): reads (orders, products, riders) + the core writes that
 * persist for real (status, save order, add/update product, image upload) +
 * bot-config read. The remaining writes return {ok:false} so the panel shows its
 * honest "saved on this device only" fallback until Phase 3b wires them.
 */
class PanelApiController extends Controller
{
    /* ----------------------------------------------------------- auth (no-op) */
    // Login screen is skipped (we inject a session token), but keep these working
    // so logout -> re-login inside the panel also succeeds against our session.
    public function authRequest()
    {
        return response()->json(['ok' => true]);
    }

    public function authVerify(Request $r)
    {
        if (! $r->user()) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        return response()->json(['ok' => true, 'token' => 'session', 'role' => 'owner', 'branch' => '']);
    }

    /* -------------------------------------------------------------- reads */
    public function orders()
    {
        $rows = Order::with('rider')->orderByDesc('id')->limit(800)->get()->map(function (Order $o) {
            return [
                'id'            => (int) $o->id,
                'order_no'      => (string) ($o->order_no ?? ''),
                'created_at'    => optional($o->created_at)->format('Y-m-d H:i:s') ?? '',
                'delivery_date' => optional($o->delivered_at)->format('Y-m-d H:i:s') ?? '',
                'name'          => (string) ($o->customer_name ?? ''),
                'phone'         => (string) ($o->customer_phone ?? ''),
                'location'      => (string) ($o->location ?? ''),
                'payment'       => (string) ($o->payment ?? ''),
                'sell_currency' => $o->sellCurrency(),                 // E4.5 — typed money
                'payment_method' => (string) ($o->paymentMethodResolved() ?? ''), // E4.5 — typed money
                'status'        => (string) ($o->status ?: 'New'),
                'channel'       => (string) ($o->channel ?? ''),
                'rider'         => (string) ($o->rider->name ?? ''),
                'rider_phone'   => (string) ($o->rider->phone ?? ''),
                'track_token'   => (string) ($o->track_token ?? ''),
                'branch'        => (string) ($o->branch_id ?? ''),
                'items_json'    => is_array($o->items_json) ? $o->items_json : [],
                'items'         => (string) ($o->items_text ?? ''),
                'total_ugx'     => (float) ($o->total ?? 0),
            ];
        });

        return response()->json(['orders' => $rows]);
    }

    public function products(Request $r)
    {
        $q = Product::whereNull('archived_at')->orderByDesc('display_order')->orderBy('name');
        $scope = $r->user()->categoryScope();
        if (is_array($scope)) $q->whereIn('category', $scope); // [] → sees nothing until assigned

        // E6.4 — read on-hand from the stock ledger projection when the tenant has opted in
        // (stock_v2 + stock_ledger). Batched into ONE query (no N+1); products with no ledger rows
        // fall back to the legacy column, so nothing shows a spurious 0 mid-cutover.
        $t = $r->user()->tenant;
        $ohMap = ($t && \App\Services\Inventory\StockLedger::readsFromLedger($t))
            ? \App\Services\Inventory\StockLedger::forTenant($t)->onHandMap(\App\Services\Inventory\StockLedger::LOC_KAMPALA)
            : [];

        $rows = $q->get()->map(function (Product $p) use ($ohMap) {
            $onHand = array_key_exists($p->id, $ohMap) ? (int) round($ohMap[$p->id]) : (int) ($p->stock ?? 0);
            return [
                'Product Name' => (string) $p->name,
                'Variant'      => '',
                'Brand'        => (string) ($p->brand ?? ''),
                'Category'     => (string) ($p->category ?? ''),
                'Keywords'     => (string) ($p->keywords ?? ''),
                'Price_UGX'    => (float) ($p->base_price ?? $p->price ?? 0),
                'Price'        => (float) ($p->price ?? 0),
                'Base Price'   => (float) ($p->base_price ?? $p->price ?? 0),
                'Cost'         => $p->cost_price === null ? '' : (float) $p->cost_price,
                'Active'       => (bool) ($p->active ?? true),
                'Stock'        => $onHand,
                'StockByBranch'=> null,
                'Barcode'      => (string) ($p->barcode ?? ''),
                'Item_Code'    => (string) ($p->sku ?? ''),
                'Image'        => (string) ($p->image_url ?? ''),
                'Gallery_1'    => (string) ($p->gallery_1 ?? ''),
                'Gallery_2'    => (string) ($p->gallery_2 ?? ''),
                'Gallery_3'    => (string) ($p->gallery_3 ?? ''),
                'Display_Order'=> (int) ($p->display_order ?? 0),
                'MOQ'          => $p->moq === null ? '' : (int) $p->moq,
                'PackSize'     => $p->pack_size === null ? '' : (int) $p->pack_size,
                'Unit'         => (string) ($p->unit_label ?? ''),
                '_row'         => (int) $p->id,
            ];
        });

        return response()->json(['products' => $rows, 'archived_count' => Product::whereNotNull('archived_at')->count()]);
    }

    public function riders()
    {
        return response()->json(['riders' => $this->ridersList()]);
    }

    /** Full rider list in the shape the panel expects (real cols + flattened profile). */
    protected function ridersList(): array
    {
        return Rider::orderBy('name')->get()->map(function (Rider $r) {
            $dob = $r->dob ? (is_object($r->dob) ? $r->dob->format('Y-m-d') : substr((string) $r->dob, 0, 10)) : '';
            $base = [
                'id'      => (int) $r->id,
                'name'    => (string) $r->name,
                'phone'   => (string) ($r->phone ?? ''),
                'active'  => (bool) ($r->active ?? true),
                'photo'   => (string) ($r->photo ?? ''),
                'city'    => (string) ($r->city ?? ''),
                'dob'     => $dob,
                'address' => (string) ($r->address ?? ''),
                'notes'   => (string) ($r->notes ?? ''),
            ];
            return array_merge($base, is_array($r->profile) ? $r->profile : []);
        })->values()->all();
    }

    public function returns()
    {
        $rows = ReturnRecord::orderByDesc('id')->limit(500)->get()->map(fn (ReturnRecord $x) => [
            'id'         => (int) $x->id,
            'orderRow'   => (int) ($x->order_id ?? 0),
            'date'       => optional($x->created_at)->format('Y-m-d') ?? '',
            'customer'   => (string) ($x->customer_name ?? ''),
            'phone'      => (string) ($x->customer_phone ?? ''),
            'items'      => (string) ($x->items_text ?? ''),
            'amount'     => (float) $x->amount,
            'resolution' => (string) $x->resolution,
            'reason'     => (string) ($x->reason ?? ''),
        ]);
        return response()->json(['returns' => $rows, 'credit' => (object) $this->creditMap()]);
    }

    /** Store credit per phone = credit issued minus credit redeemed. */
    protected function creditMap(): array
    {
        $map = [];
        foreach (ReturnRecord::all() as $x) {
            $p = preg_replace('/[^0-9]/', '', (string) $x->customer_phone);
            if ($p === '') continue;
            $map[$p] = $map[$p] ?? 0;
            if ($x->resolution === 'credit') $map[$p] += (float) $x->amount;
            elseif ($x->resolution === 'redeem') $map[$p] -= (float) $x->amount;
        }
        foreach ($map as $k => $v) {
            if ($v <= 0) unset($map[$k]);
        }
        return $map;
    }

    public function settings(Request $r)
    {
        $t = $r->user()->tenant;
        $s = $t->settings ?? [];
        $isMfr = \App\Support\Vertical::of($t) === \App\Support\Vertical::MANUFACTURER;
        $bd = \App\Support\BrandDefaults::text();
        return response()->json([
            'ok'            => true,
            'storeName'     => (string) ($t->name ?? 'Family Shopper'),
            'slug'          => (string) ($t->slug ?? ''),
            'customDomain'  => (string) ($t->custom_domain ?? ''),
            'onboarded'     => (bool) ($s['onboarded'] ?? false),
            'storePhone'    => (string) ($t->whatsapp_number ?? ''),
            'storeAddress'  => (string) ($s['address'] ?? 'Kampala, Uganda'),
            'storeEmail'    => (string) ($s['email'] ?? ''),
            'logo'          => (string) ($s['logo'] ?? ''),
            'heroImage'       => (string) ($s['hero_image'] ?? ''),
            'factoryImage'    => (string) ($s['factory_image'] ?? ''),
            'themeAccent'     => (string) ($s['theme_accent'] ?? ''),
            'themeAccentDark' => (string) ($s['theme_accent_dark'] ?? ''),
            'eyebrow'         => (string) ($s['eyebrow'] ?? ($isMfr ? $bd['eyebrow'] : '')),
            'trustLine'       => (string) ($s['trust_line'] ?? ($isMfr ? $bd['trustLine'] : '')),
            'website'         => (string) ($s['website'] ?? ''),
            'aiPersona'       => (string) ($s['ai_persona'] ?? ''),
            'brandKnowledge'  => (string) ($s['brand_knowledge'] ?? ''),
            'imageSources'    => array_values(array_filter(array_map('intval', is_array($s['image_sources'] ?? []) ? ($s['image_sources'] ?? []) : explode(',', (string) ($s['image_sources'] ?? ''))))),
            'imageSourceNames' => (function () use ($s) {
                $ids = array_values(array_filter(array_map('intval', is_array($s['image_sources'] ?? []) ? ($s['image_sources'] ?? []) : explode(',', (string) ($s['image_sources'] ?? '')))));
                return $ids ? \App\Models\Tenant::withoutGlobalScopes()->whereIn('id', $ids)->pluck('name')->all() : [];
            })(),
            'tagline'         => (string) ($s['tagline'] ?? ($isMfr ? $bd['tagline'] : '')),
            'heroTitle'       => (string) ($s['hero_title'] ?? ($isMfr ? $bd['heroTitle'] : '')),
            'heroText'        => (string) ($s['hero_text'] ?? ($isMfr ? $bd['heroText'] : '')),
            'metaDescription' => (string) ($s['meta_description'] ?? ''),
            'faq'             => array_values($s['faq'] ?? ($isMfr ? \App\Support\BrandDefaults::faq() : [])),
            'brands'          => array_values($s['brands'] ?? ($isMfr ? \App\Support\BrandDefaults::brands((string) ($s['theme_accent'] ?? '#103A8C')) : [])),
            'combos'          => array_values($s['combos'] ?? []),
            'reengage'        => (function () use ($s) {
                $c = (array) ($s['reengage_followup'] ?? []);
                return [
                    'enabled'      => (bool) ($c['enabled'] ?? false),
                    'delay_min'    => (int) ($c['delay_min'] ?? 10),
                    'max_min'      => (int) ($c['max_min'] ?? 180),
                    'require_lead' => (bool) ($c['require_lead'] ?? true),
                    'link'         => (string) ($c['link'] ?? ''),
                    'message'      => (string) ($c['message'] ?? ''),
                ];
            })(),
            'creditReminder'  => (function () use ($s) {
                $c = (array) ($s['credit_reminder'] ?? []);
                return [
                    'enabled' => (bool) ($c['enabled'] ?? false),
                    'days'    => (string) ($c['days'] ?? ''),
                    'message' => (string) ($c['message'] ?? ''),
                ];
            })(),
            'introCatalog'    => (bool) (((array) ($s['intro_catalog'] ?? []))['enabled'] ?? false),
            'categoryGroups'  => (object) ($s['category_groups'] ?? []),
            'menuFiles'       => array_values($s['menu_files'] ?? []),
            'catalogFiles'    => array_values($s['catalog_files'] ?? []),
            'base'          => (float) ($s['base'] ?? 2000),
            'perKm'         => (float) ($s['perKm'] ?? 700),
            'min'           => (float) ($s['min'] ?? 2000),
            'round'         => (float) ($s['round'] ?? 500),
            'freeOver'      => (float) ($s['freeOver'] ?? 0),
            'lat'           => (float) ($s['lat'] ?? 0.3428795),
            'lng'           => (float) ($s['lng'] ?? 32.5825996),
            'inventoryMode' => (string) ($s['inventoryMode'] ?? 'shared'),
            'usdUgx'        => (float) ($s['usdUgx'] ?? \App\Services\Money\Fx::forTenant($t)->ratePerUsd('UGX')), // E3.5 — default from the one FX source
            'usdSsp'        => (float) ($s['usdSsp'] ?? \App\Services\Money\Fx::forTenant($t)->ratePerUsd('SSP')),
            'ssMarkupPct'   => (float) ($s['ssMarkupPct'] ?? 0),
            'comboRecommendations' => (bool) ($s['combo_recommendations'] ?? true),
            'sendProductImages'    => (bool) ($s['send_product_images'] ?? true),
            'features'      => $this->tenantFeatures($t),
        ]);
    }

    /**
     * Per-tenant feature flags. A feature shows in a seller's panel only when enabled for
     * them, so seller-specific tools (e.g. Daily Thali for food shops) don't appear for
     * unrelated sellers (e.g. a packaging wholesaler). An explicit settings.feature_<key>
     * overrides; otherwise we derive a sensible default (thali: on only if it's configured).
     */
    protected function tenantFeatures(Tenant $t): array
    {
        $s        = $t->settings ?? [];
        $thaliCfg = $s['thali'] ?? [];
        $thaliConfigured = ! empty($thaliCfg['enabled']) || ! empty($thaliCfg['days']);
        $thali = array_key_exists('feature_thali', $s)
            ? (bool) $s['feature_thali']
            : $thaliConfigured;

        return [
            'thali'        => $thali,
            'image_search' => array_key_exists('feature_image_search', $s)
                ? (bool) $s['feature_image_search']
                : true,
            // Vertical-driven nav (safe: applyFeatureGates only hides on an explicit false).
            // grocery: riders/pos/dispatch on, kitchen off. restaurant: + kitchen on.
            // snacks: riders/pos/dispatch off (pickup/advance-booking), kitchen off.
            'kitchen'      => \App\Support\Vertical::shows($t, 'kitchen_board'),
            'riders'       => \App\Support\Vertical::shows($t, 'riders'),
            'pos'          => \App\Support\Vertical::shows($t, 'pos'),
            'dispatch'     => \App\Support\Vertical::shows($t, 'riders'),
            'vertical'     => \App\Support\Vertical::of($t),
        ];
    }

    /** Photo-search stats for the seller dashboard (last 30 days, from bot_events). */
    public function imageStats(Request $r)
    {
        $t     = $r->user()->tenant;
        $since = now()->subDays(30);

        $rows = \Illuminate\Support\Facades\DB::table('bot_events')
            ->where('tenant_id', $t->id)
            ->where('created_at', '>=', $since)
            ->whereIn('stage', ['photo_received', 'photo_search_hit', 'photo_search_miss', 'photo_unidentified', 'photo_selected', 'photo_known'])
            ->selectRaw('stage, count(*) as c')
            ->groupBy('stage')
            ->pluck('c', 'stage');

        $received = (int) ($rows['photo_received'] ?? 0);
        $matched  = (int) ($rows['photo_search_hit'] ?? 0);

        return response()->json([
            'ok'           => true,
            'days'         => 30,
            'received'     => $received,
            'matched'      => $matched,
            'selected'     => (int) ($rows['photo_selected'] ?? 0),
            'known'        => (int) ($rows['photo_known'] ?? 0),
            'miss'         => (int) ($rows['photo_search_miss'] ?? 0),
            'unidentified' => (int) ($rows['photo_unidentified'] ?? 0),
            'hit_rate'     => $received > 0 ? (int) round($matched / $received * 100) : 0,
        ]);
    }

    /** Small operations overview for the dashboard (today): leads, tickets, shopping. No new tables. */
    /**
     * Today — a prioritised task list, not a dashboard. Each task is one concrete entity
     * (an order, a quote, a product, a customer) with a reason and a one-tap action.
     * Pure read over existing data; no new tables. Confidence/basis is shown only where it is
     * genuinely derivable (e.g. a reorder gap from the customer's own history) — never fabricated.
     */
    public function today(Request $r)
    {
        $t        = $r->user()->tenant;
        $now      = now();
        $vertical = \App\Support\Vertical::of($t);
        $sla      = (array) ($t->setting('order_sla', []) ?: []);
        $LIMIT    = 6;

        // Fix now: active orders past their stage SLA.
        $fix = []; $fixMore = 0;
        \App\Models\Order::where('tenant_id', $t->id)
            ->whereIn('status', \App\Support\OrderSla::ACTIVE)
            ->orderBy('status_changed_at')
            ->get(['id', 'order_no', 'customer_name', 'status', 'status_changed_at', 'created_at'])
            ->each(function ($o) use (&$fix, &$fixMore, $vertical, $sla, $now, $LIMIT) {
                $since = \Illuminate\Support\Carbon::parse($o->status_changed_at ?: $o->created_at);
                $mins  = $since->diffInMinutes($now);
                if ($mins < \App\Support\OrderSla::staleMinutes($vertical, $o->status, $sla)) return;
                if (count($fix) >= $LIMIT) { $fixMore++; return; }
                $fix[] = [
                    'label'  => $o->order_no ?: ('Order #' . $o->id),
                    'detail' => trim(($o->customer_name ? $o->customer_name . ' · ' : '') . 'waiting ' . $this->humanMins($mins) . ' in ' . $o->status),
                    'reason' => 'Past the response time for "' . $o->status . '"',
                    'action' => ['label' => 'Open', 'href' => '/panel#orders'],
                ];
            });

        // Confirm delivery: delivered, customer hasn't confirmed.
        $confirm = [];
        $confirmQ = \App\Models\Order::where('tenant_id', $t->id)
            ->whereNotNull('delivered_at')->whereNull('customer_confirmed_at')
            ->whereNotIn('status', ['Cancelled', 'Rejected'])
            ->orderBy('delivered_at')->limit($LIMIT + 1)
            ->get(['id', 'order_no', 'customer_name', 'delivered_at']);
        $confirmMore = max(0, $confirmQ->count() - $LIMIT);
        foreach ($confirmQ->take($LIMIT) as $o) {
            $confirm[] = [
                'label'  => $o->order_no ?: ('Order #' . $o->id),
                'detail' => trim(($o->customer_name ? $o->customer_name . ' · ' : '') . 'delivered ' . $this->humanMins(\Illuminate\Support\Carbon::parse($o->delivered_at)->diffInMinutes($now)) . ' ago'),
                'reason' => "Customer hasn't confirmed receipt",
                'action' => ['label' => 'Open', 'href' => '/panel#orders'],
            ];
        }

        // Reply to customer: quotations sent, awaiting a reply.
        $quotes = [];
        $quoteHrs = (int) $t->setting('quote_followup_hours', 24);
        $qQ = \App\Models\Quotation::where('tenant_id', $t->id)->where('status', 'sent')
            ->orderBy('created_at')->limit($LIMIT + 1)
            ->get(['id', 'quote_no', 'customer_name', 'created_at']);
        $quotesMore = max(0, $qQ->count() - $LIMIT);
        foreach ($qQ->take($LIMIT) as $q) {
            $age = \Illuminate\Support\Carbon::parse($q->created_at)->diffInHours($now);
            $quotes[] = [
                'label'  => ($q->quote_no ?: ('Quote #' . $q->id)) . ($q->customer_name ? ' · ' . $q->customer_name : ''),
                'detail' => 'Sent ' . ($age < 1 ? 'under 1h' : $this->humanMins($age * 60)) . ' ago, no reply',
                'reason' => $age >= $quoteHrs ? ('Awaiting reply over ' . $quoteHrs . 'h') : 'Sent, not yet accepted or declined',
                'action' => ['label' => 'Resend', 'api' => 'quotation-resend', 'id' => $q->id],
            ];
        }

        // Count stock: stale / unknown inventory.
        $stock = [];
        $sQ = \App\Models\InventorySnapshot::where('tenant_id', $t->id)
            ->where(function ($q) use ($now) {
                $q->where('confidence', \App\Services\Inventory\InventoryState::UNKNOWN)
                  ->orWhere('observed_at', '<', $now->copy()->subHours(\App\Services\Inventory\InventoryState::ESTIMATED_HOURS));
            })
            ->orderBy('observed_at')->limit($LIMIT + 1)->get(['product_id', 'observed_at']);
        $stockMore = max(0, $sQ->count() - $LIMIT);
        $names = \App\Models\Product::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereIn('id', $sQ->pluck('product_id')->all())->pluck('name', 'id');
        foreach ($sQ->take($LIMIT) as $s) {
            $obs = $s->observed_at ? \Illuminate\Support\Carbon::parse($s->observed_at) : null;
            $stock[] = [
                'label'  => $names[$s->product_id] ?? ('Product #' . $s->product_id),
                'detail' => $obs ? ('Last counted ' . $obs->diffForHumans()) : 'Never counted',
                'reason' => 'Bot can\'t quote a number — it says "I\'ll confirm"',
                'action' => ['label' => 'Count', 'href' => '/panel/inventory-count'],
            ];
        }

        // Win back: likely-to-reorder customers (honest gap from their own history, no fake %).
        $reorder = []; $reorderMore = 0;
        $reorderDays = (int) $t->setting('reorder_days', 30);
        try {
            $cands = \App\Models\Order::where('tenant_id', $t->id)
                ->whereNotIn('status', ['Cancelled', 'Rejected'])
                ->whereNotNull('customer_phone')
                ->groupBy('customer_phone')
                ->havingRaw('COUNT(*) >= 2')
                ->havingRaw('MAX(created_at) < ?', [$now->copy()->subDays($reorderDays)])
                ->orderByRaw('MAX(created_at) asc')
                ->limit($LIMIT + 1)
                ->get([
                    \Illuminate\Support\Facades\DB::raw('customer_phone'),
                    \Illuminate\Support\Facades\DB::raw('MAX(customer_name) as customer_name'),
                    \Illuminate\Support\Facades\DB::raw('MAX(created_at) as last_at'),
                    \Illuminate\Support\Facades\DB::raw('COUNT(*) as n'),
                ]);
            $reorderMore = max(0, $cands->count() - $LIMIT);
            foreach ($cands->take($LIMIT) as $c) {
                $last = \Illuminate\Support\Carbon::parse($c->last_at);
                $reorder[] = [
                    'label'  => ($c->customer_name ?: $c->customer_phone),
                    'detail' => 'Last order ' . $last->diffForHumans() . ' · ' . $c->n . ' orders',
                    'reason' => 'Ordered before, nothing in ' . $reorderDays . '+ days',
                    'action' => ['label' => 'Review', 'href' => '/panel#customers'],
                ];
            }
        } catch (\Throwable $e) {
            $reorderMore = 0;
        }

        // Check failures: quote pipelines that failed today.
        $failed = [];
        $fQ = \Illuminate\Support\Facades\DB::table('bot_events')
            ->where('tenant_id', $t->id)->where('stage', 'quote_pipeline')
            ->where('detail', 'like', '%fail%')
            ->where('created_at', '>=', $now->copy()->startOfDay())
            ->orderByDesc('created_at')->limit($LIMIT + 1)->get(['phone', 'detail', 'created_at']);
        $failedMore = max(0, $fQ->count() - $LIMIT);
        foreach ($fQ->take($LIMIT) as $f) {
            $failed[] = [
                'label'  => $f->phone ?: 'Unknown number',
                'detail' => mb_substr((string) $f->detail, 0, 80),
                'reason' => 'A quotation failed to build or send',
                'action' => ['label' => 'Diagnose', 'href' => '/panel/diagnostics'],
            ];
        }

        $groups = [
            ['key'=>'fix','title'=>'Fix now','icon'=>'🔴','tone'=>'red','tasks'=>$fix,'more'=>$fixMore],
            ['key'=>'reply','title'=>'Reply to customer','icon'=>'📄','tone'=>'amber','tasks'=>$quotes,'more'=>$quotesMore],
            ['key'=>'confirm','title'=>'Confirm delivery','icon'=>'🟡','tone'=>'amber','tasks'=>$confirm,'more'=>$confirmMore],
            ['key'=>'stock','title'=>'Count stock','icon'=>'⚠️','tone'=>'amber','tasks'=>$stock,'more'=>$stockMore],
            ['key'=>'winback','title'=>'Win back','icon'=>'🔵','tone'=>'blue','tasks'=>$reorder,'more'=>$reorderMore],
            ['key'=>'failures','title'=>'Check failures','icon'=>'🛠️','tone'=>'red','tasks'=>$failed,'more'=>$failedMore],
        ];
        $groups = array_values(array_filter($groups, fn ($g) => count($g['tasks']) > 0));
        $total  = array_sum(array_map(fn ($g) => count($g['tasks']) + $g['more'], $groups));

        // Yesterday recap (honest, from orders).
        $yStart = $now->copy()->subDay()->startOfDay();
        $yEnd   = $now->copy()->startOfDay();
        $yOrders    = \App\Models\Order::where('tenant_id', $t->id)->whereBetween('created_at', [$yStart, $yEnd])->count();
        $yDelivered = \App\Models\Order::where('tenant_id', $t->id)->whereNotNull('delivered_at')->whereBetween('delivered_at', [$yStart, $yEnd])->count();
        $ySales     = (float) \App\Models\Order::where('tenant_id', $t->id)->whereBetween('created_at', [$yStart, $yEnd])->whereNotIn('status', ['Cancelled', 'Rejected'])->sum('total');

        $hour  = (int) $now->format('G');
        $greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $name  = trim((string) ($r->user()->name ?? ''));

        return response()->json([
            'ok'          => true,
            'greeting'    => $greet . ($name ? ', ' . $name : ''),
            'total_tasks' => $total,
            'groups'      => $groups,
            'yesterday'   => ['orders' => $yOrders, 'delivered' => $yDelivered, 'sales' => $ySales, 'currency' => $t->setting('currency', 'UGX')],
            'generated_at'=> $now->toIso8601String(),
        ]);
    }

    private function humanMins(int $m): string
    {
        if ($m < 60) return $m . ' min';
        $h = intdiv($m, 60); $mm = $m % 60;
        if ($h < 24) return $h . 'h' . ($mm ? ' ' . $mm . 'm' : '');
        return intdiv($h, 24) . 'd';
    }

    /** Products with their current stock confidence — for the count screen. */
    public function inventoryList(Request $r)
    {
        $t     = $r->user()->tenant;
        $q     = trim((string) $r->query('q', ''));
        $needs = in_array((string) $r->query('needs', ''), ['1', 'true', 'on', 'yes'], true);

        $query = \App\Models\Product::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->whereNull('archived_at')->where('active', true);
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%");
            });
        }
        $products = $query->orderBy('display_order')->orderBy('name')->limit(400)->get();

        $inv = new \App\Services\Inventory\InventoryState();
        $inv->prime($products);

        $rows = [];
        $need = 0;
        foreach ($products as $p) {
            $st = $inv->state($p);
            if ($st['state'] !== \App\Services\Inventory\InventoryState::EXACT) $need++;
            if ($needs && $st['state'] === \App\Services\Inventory\InventoryState::EXACT) continue;
            $rows[] = [
                'id'         => $p->id,
                'name'       => $p->name,
                'sku'        => (string) ($p->sku ?? ''),
                'unit'       => (string) ($p->unit_label ?? ''),
                'confidence' => $st['state'],
                'qty'        => $st['qty'],
                'last'       => $st['observed_at'] ? $st['observed_at']->diffForHumans() : null,
                'line'       => $st['line'],
            ];
        }
        return response()->json(['ok' => true, 'rows' => $rows, 'need_count' => $need, 'shown' => count($rows), 'limited' => $products->count() >= 400]);
    }

    /** Record a physical count for one product → confidence becomes "exact". */
    public function inventoryCount(Request $r)
    {
        $t  = $r->user()->tenant;
        $id = (int) $r->query('id');
        if ((string) $r->query('qty', '') === '') return response()->json(['ok' => false, 'error' => 'qty required'], 422);
        $p = \App\Models\Product::withoutGlobalScopes()->where('tenant_id', $t->id)->find($id);
        if (! $p) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $inv = new \App\Services\Inventory\InventoryState();
        $inv->recordCount($p, max(0, (int) $r->query('qty')), 'panel');
        $st = $inv->state($p);
        return response()->json(['ok' => true, 'id' => $p->id, 'qty' => $st['qty'], 'confidence' => $st['state'], 'line' => $st['line']]);
    }

    public function operations(Request $r)
    {
        $t    = $r->user()->tenant;
        $day  = now()->startOfDay();
        $open = ['new', 'assigned', 'contacted', 'qualified'];
        $L    = fn () => \Illuminate\Support\Facades\DB::table('leads')->where('tenant_id', $t->id);

        $sales = ['created' => 0, 'claimed' => 0, 'hot' => 0, 'won' => 0];
        $support = ['created' => 0, 'unassigned' => 0];
        try {
            $sales = [
                'created' => $L()->where('intent', 'lead')->where('created_at', '>=', $day)->count(),
                'claimed' => $L()->whereNotNull('claimed_at')->where('claimed_at', '>=', $day)->count(),
                'hot'     => $L()->where('intent', 'lead')->whereIn('status', $open)->where('lead_score', '>=', 70)->count(),
                'won'     => $L()->where('status', 'won')->where('updated_at', '>=', $day)->count(),
            ];
            $support = [
                'created'    => $L()->where('intent', 'ticket')->where('created_at', '>=', $day)->count(),
                'unassigned' => $L()->where('intent', 'ticket')->whereIn('status', $open)->whereNull('assigned_to')->count(),
            ];
        } catch (\Throwable $e) { /* leads table not migrated yet → zeros */ }

        $orders = 0;
        try {
            $orders = \Illuminate\Support\Facades\DB::table('orders')->where('tenant_id', $t->id)->where('created_at', '>=', $day)->count();
        } catch (\Throwable $e) { /* ignore */ }

        $ev = fn ($stage) => \Illuminate\Support\Facades\DB::table('bot_events')
            ->where('tenant_id', $t->id)->where('stage', $stage)->where('created_at', '>=', $day)->count();

        return response()->json([
            'ok'       => true,
            'sales'    => $sales,
            'support'  => $support,
            'shopping' => ['orders' => $orders, 'photos' => $ev('photo_received'), 'known' => $ev('photo_known')],
        ]);
    }

    /** System health for the diagnostics page: queue, redis, WhatsApp, OpenAI, last activity. */
    public function health(Request $r)
    {
        $t = $r->user()->tenant;

        $openai = (bool) (config('openai.api_key') ?: env('OPENAI_API_KEY'));

        $redis = false;
        try {
            \Illuminate\Support\Facades\Cache::put('health:ping', 1, 5);
            $redis = ((int) \Illuminate\Support\Facades\Cache::get('health:ping') === 1);
        } catch (\Throwable $e) { /* redis down */ }

        $failedJobs = null;
        try { $failedJobs = (int) \Illuminate\Support\Facades\DB::table('failed_jobs')->count(); } catch (\Throwable $e) {}

        // Peek at the most recent failures with their job + first error line, so the owner can see
        // WHY they failed (e.g. a transient deploy-window error) instead of just a scary count.
        $failedRecent = [];
        try {
            foreach (\Illuminate\Support\Facades\DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get() as $fj) {
                $pl   = json_decode((string) ($fj->payload ?? '{}'), true) ?: [];
                $job  = (string) ($pl['displayName'] ?? ($pl['job'] ?? 'job'));
                $line = trim(strtok((string) ($fj->exception ?? ''), "\n"));
                $failedRecent[] = [
                    'job'       => class_basename($job) ?: $job,
                    'failed_at' => $fj->failed_at ?? null,
                    'error'     => mb_substr($line, 0, 180),
                ];
            }
        } catch (\Throwable $e) {}

        $lastWebhook = $lastProcessed = null;
        try {
            $lastWebhook   = \Illuminate\Support\Facades\DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'started')->max('created_at');
            // The AI brain logs 'ai_replied'; the inbuilt bot logs 'replied'. Count both, else an
            // AI-mode shop always shows "never" even while it's replying perfectly.
            $lastProcessed = \Illuminate\Support\Facades\DB::table('bot_events')->where('tenant_id', $t->id)
                ->whereIn('stage', ['replied', 'ai_replied'])->max('created_at');
        } catch (\Throwable $e) {}

        $wa = ['instance' => (string) ($t->whatsapp_instance ?? ''), 'state' => 'unknown'];
        try {
            $g = app(\App\Services\WhatsApp\WhatsAppManager::class)->forTenant($t);
            if ($t->whatsapp_instance && method_exists($g, 'connectionState')) {
                $wa['state'] = $g->connectionState($t->whatsapp_instance) ?: 'unknown';
            }
        } catch (\Throwable $e) {}

        // Which number is connected + can it actually send?  Number/profile come from Evolution;
        // the send signal comes from our own messages.update capture (DELIVERY_ACK vs ERROR).
        if ($t->whatsapp_instance) {
            try {
                $info = app(\App\Services\WhatsApp\EvolutionAdmin::class)->instanceInfo($t->whatsapp_instance);
                $wa['number']       = $info['number'] ?? null;
                $wa['profile_name'] = $info['profile_name'] ?? null;
                if (($wa['state'] === 'unknown' || $wa['state'] === '') && ! empty($info['state'])) {
                    $wa['state'] = $info['state'];
                }
            } catch (\Throwable $e) {}

            $wa['send_ok_at']  = $t->setting('wa_send_ok_at');
            $wa['send_err_at'] = $t->setting('wa_send_err_at');
            try {
                $wa['recent_send_fail'] = (int) \Illuminate\Support\Facades\DB::table('bot_events')
                    ->where('tenant_id', $t->id)->where('stage', 'send_failed')
                    ->where('created_at', '>=', now()->subHours(6))->count();
            } catch (\Throwable $e) { $wa['recent_send_fail'] = null; }
        }

        // Every WhatsApp number on this shop with its live state — so a multi-number shop can see
        // each one (primary + branch/support/backup) and confirm which are actually connected.
        $channels = [];
        try {
            $evo = app(\App\Services\WhatsApp\EvolutionAdmin::class);
            foreach (\App\Models\WhatsappChannel::where('tenant_id', $t->id)->orderByDesc('is_primary')->orderBy('id')->get() as $c) {
                $st = 'n/a'; $num = $c->number;
                if (($c->driver ?? 'evolution') === 'evolution' && $evo->configured()) {
                    $info = $evo->instanceInfo($c->instance);
                    $st   = strtolower((string) ($info['state'] ?? '')) ?: $evo->state($c->instance);
                    if (! empty($info['number'])) $num = $info['number'];
                }
                $channels[] = [
                    'instance'   => $c->instance,
                    'number'     => $num,
                    'label'      => $c->label,
                    'is_primary' => (bool) $c->is_primary,
                    'state'      => $st ?: 'missing',
                ];
            }
        } catch (\Throwable $e) {}

        return response()->json([
            'ok'                => true,
            'now'               => now()->toIso8601String(),
            'openai_configured' => $openai,
            'redis'             => $redis,
            'queue'             => ['driver' => (string) config('queue.default'), 'failed_jobs' => $failedJobs, 'failed_recent' => $failedRecent],
            'whatsapp'          => $wa,
            'channels'          => $channels,
            'last_webhook_at'   => $lastWebhook,
            'last_processed_at' => $lastProcessed,
        ]);
    }

    /** CRM lead list (manual + WhatsApp), filterable. Tickets excluded — that's Build 87. */
    public function leadsList(Request $r)
    {
        $t = $r->user()->tenant;
        $q = \App\Models\Lead::query()->where('intent', 'lead');

        if ($s = $r->query('status'))   $q->where('status', $s);
        if ($src = $r->query('source')) $q->where('source', $src);
        if ($tag = $r->query('tag'))    $q->where('tag', $tag);
        $optin = (string) $r->query('optin', '');
        if ($optin === '1') $q->where('marketing_opt_in', true);
        elseif ($optin === '0') $q->where('marketing_opt_in', false);

        $open = ['new', 'assigned', 'contacted', 'qualified'];
        switch (strtolower((string) $r->query('view', ''))) {
            case 'unassigned':
                $q->whereNull('assigned_to')->whereIn('status', $open);
                break;
            case 'overdue':
                $q->whereNotNull('next_followup_at')->where('next_followup_at', '<', now())->whereNotIn('status', ['won', 'lost']);
                break;
            case 'hot':
                $q->whereIn('status', $open)->where('lead_score', '>=', 70);
                break;
        }
        if ($term = trim((string) $r->query('q', ''))) {
            $like = '%' . $term . '%';
            $q->where(function ($w) use ($like) {
                $w->where('customer_name', 'like', $like)
                  ->orWhere('customer_phone', 'like', $like)
                  ->orWhere('interest', 'like', $like)
                  ->orWhere('company', 'like', $like);
            });
        }

        $rows   = $q->latest('id')->limit(300)->get();
        $names  = collect($t->leadRecipients())->pluck('name', 'phone');
        $scorer = new \App\Services\Bot\LeadScorer();

        $leads = $rows->map(function ($l) use ($names, $scorer) {
            return [
                'id'        => $l->id,
                'created'   => optional($l->created_at)->toIso8601String(),
                'name'      => $l->customer_name,
                'phone'     => $l->customer_phone,
                'company'   => $l->company,
                'interest'  => $l->interest,
                'source'    => $l->source,
                'tag'       => $l->tag,
                'status'    => $l->status,
                'assigned'  => $l->assigned_to ? (($names[$l->assigned_to] ?? '') ?: $l->assigned_to) : '',
                'assigned_to' => $l->assigned_to,
                'score'     => (int) $l->lead_score,
                'band'      => $scorer->band((int) $l->lead_score),
                'notes'     => $l->notes,
                'conversation_id'   => $l->conversation_id,
                'next_followup_at'  => optional($l->next_followup_at)->toIso8601String(),
                'last_contacted_at' => optional($l->last_contacted_at)->toIso8601String(),
            ];
        });

        // Pipeline KPIs — always over the whole table, independent of the active filter.
        $L = fn () => \App\Models\Lead::query()->where('intent', 'lead');
        $stats = [
            'new'      => $L()->where('status', 'new')->count(),
            'assigned' => $L()->where('status', 'assigned')->count(),
            'hot'      => $L()->whereIn('status', $open)->where('lead_score', '>=', 70)->count(),
            'overdue'  => $L()->whereNotNull('next_followup_at')->where('next_followup_at', '<', now())->whereNotIn('status', ['won', 'lost'])->count(),
            'won'      => $L()->where('status', 'won')->where('updated_at', '>=', now()->subDays(30))->count(),
            'total'    => $L()->count(),
            'optin'    => $L()->where('marketing_opt_in', true)->count(),
            'tagged'   => $L()->whereNotNull('tag')->where('tag', '<>', '')->count(),
        ];

        // Distinct values for the source/tag filter dropdowns (full list, ignores active filter).
        $facets = [
            'sources' => $L()->whereNotNull('source')->where('source', '<>', '')->distinct()->orderBy('source')->pluck('source')->values(),
            'tags'    => $L()->whereNotNull('tag')->where('tag', '<>', '')->distinct()->orderBy('tag')->pluck('tag')->values(),
        ];

        return response()->json(['ok' => true, 'count' => $leads->count(), 'stats' => $stats, 'facets' => $facets, 'leads' => $leads]);
    }

    /** Dropdown data for the lead form. */
    public function leadOptions(Request $r)
    {
        $t = $r->user()->tenant;
        return response()->json([
            'ok'         => true,
            'recipients' => array_values($t->leadRecipients()),
            'sources'    => ['whatsapp', 'website', 'facebook', 'instagram', 'referral', 'walk_in', 'phone_call', 'email', 'manual'],
            'statuses'   => ['new', 'assigned', 'contacted', 'qualified', 'won', 'lost'],
        ]);
    }

    /** Create or update a lead from the panel (query-string write, matching the panel convention). */
    public function leadSave(Request $r)
    {
        $t       = $r->user()->tenant;
        $id      = (int) $r->query('id', 0);
        $name    = trim((string) $r->query('name', ''));
        $phone   = preg_replace('/[^0-9]/', '', (string) $r->query('phone', ''));
        if ($phone === '') return response()->json(['ok' => false, 'error' => 'Phone is required'], 422);

        $company  = trim((string) $r->query('company', ''));
        $interest = trim((string) $r->query('interest', ''));
        $source   = strtolower(trim((string) $r->query('source', 'manual'))) ?: 'manual';
        $assigned = preg_replace('/[^0-9]/', '', (string) $r->query('assigned_to', ''));
        $notes    = trim((string) $r->query('notes', ''));
        $status   = strtolower(trim((string) $r->query('status', '')));
        $scoreRaw = $r->query('lead_score', null);
        $hasScore = ($scoreRaw !== null && $scoreRaw !== '');

        if ($id) {
            $lead = \App\Models\Lead::find($id);
            if (! $lead) return response()->json(['ok' => false, 'error' => 'Lead not found'], 404);

            $prevAssigned = $lead->assigned_to;
            $lead->customer_name  = $name ?: $lead->customer_name;
            $lead->customer_phone = $phone;
            $lead->company        = $company ?: null;
            $lead->interest       = $interest ?: $lead->interest;
            $lead->source         = $source ?: $lead->source;
            if ($r->query('tag') !== null) $lead->tag = trim((string) $r->query('tag')) ?: null;
            $lead->notes          = $notes ?: null;
            if ($status)   $lead->status = $status;
            if ($hasScore) $lead->lead_score = max(0, min(100, (int) $scoreRaw));
            if ($r->query('next_followup') !== null) {
                $fu = trim((string) $r->query('next_followup'));
                $lead->next_followup_at = $fu !== '' ? $this->parseDate($fu) : null;
            }
            if ($lead->status === 'contacted' && ! $lead->last_contacted_at) $lead->last_contacted_at = now();
            if ($assigned !== '') {
                $lead->assigned_to = $assigned;
                if ($lead->status === 'new') $lead->status = 'assigned';
                if (! $lead->claimed_at)     $lead->claimed_at = now();
            }
            $lead->save();

            \App\Support\BotTrace::log($t->id, 'panel', $lead->customer_phone, 'lead_updated', '#' . $lead->id);
            if ($assigned !== '' && $assigned !== $prevAssigned) $this->notifyAssignee($t, $lead);

            return response()->json(['ok' => true, 'id' => $lead->id, 'updated' => true]);
        }

        $score = $hasScore
            ? max(0, min(100, (int) $scoreRaw))
            : (new \App\Services\Bot\LeadScorer())->score('lead', $interest ?: $name);

        $lead = \App\Models\Lead::create([
            'customer_phone' => $phone,
            'customer_name'  => $name ?: null,
            'company'        => $company ?: null,
            'intent'         => 'lead',
            'interest'       => $interest ?: '(manual lead)',
            'dedupe_key'     => \App\Services\Bot\LeadDedupe::key($phone, 'lead', $interest),
            'lead_score'     => $score,
            'source'         => $source ?: 'manual',
            'tag'            => (trim((string) $r->query('tag', '')) ?: null),
            'status'         => $status ?: ($assigned !== '' ? 'assigned' : 'new'),
            'assigned_to'    => $assigned ?: null,
            'claimed_at'     => $assigned !== '' ? now() : null,
            'notes'          => $notes ?: null,
            'message'        => $notes ?: null,
            'next_followup_at'  => $this->parseDate(trim((string) $r->query('next_followup', ''))),
            'last_contacted_at' => $status === 'contacted' ? now() : null,
        ]);

        \App\Support\BotTrace::log($t->id, 'panel', $phone, 'lead_created', 'manual #' . $lead->id);
        if ($assigned !== '') $this->notifyAssignee($t, $lead);

        return response()->json(['ok' => true, 'id' => $lead->id, 'created' => true]);
    }

    /** Quick row actions: won | lost | contacted | qualified | reopen | assign. */
    public function leadAction(Request $r)
    {
        $t    = $r->user()->tenant;
        $lead = \App\Models\Lead::find((int) $r->query('id', 0));
        if (! $lead) return response()->json(['ok' => false, 'error' => 'Lead not found'], 404);

        $a = strtolower((string) $r->query('action', ''));

        if ($a === 'assign') {
            $assigned = preg_replace('/[^0-9]/', '', (string) $r->query('assigned_to', ''));
            if ($assigned === '') {
                $lead->assigned_to = null;
            } else {
                $lead->assigned_to = $assigned;
                if ($lead->status === 'new') $lead->status = 'assigned';
                if (! $lead->claimed_at)     $lead->claimed_at = now();
            }
            $lead->save();
            if ($assigned !== '') $this->notifyAssignee($t, $lead);
            \App\Support\BotTrace::log($t->id, 'panel', $lead->customer_phone, 'lead_assigned', '#' . $lead->id);
            return response()->json(['ok' => true]);
        }

        $map = ['won' => 'won', 'lost' => 'lost', 'contacted' => 'contacted', 'qualified' => 'qualified', 'reopen' => 'new'];
        if (! isset($map[$a])) return response()->json(['ok' => false, 'error' => 'Unknown action'], 422);

        $lead->status = $map[$a];
        if ($a === 'contacted') $lead->last_contacted_at = now();
        $lead->save();
        \App\Support\BotTrace::log($t->id, 'panel', $lead->customer_phone, 'lead_' . $a, '#' . $lead->id);
        return response()->json(['ok' => true]);
    }

    /** Lenient date parse for the follow-up field; returns Carbon or null. */
    private function parseDate(?string $s)
    {
        $s = trim((string) $s);
        if ($s === '') return null;
        try { return \Illuminate\Support\Carbon::parse($s); } catch (\Throwable $e) { return null; }
    }

    /** Notify the assigned recipient over WhatsApp that a lead is theirs. */
    private function notifyAssignee($t, $lead): void
    {
        try {
            $msg = "🎯 Lead assigned to you\n"
                . ($lead->customer_name ?: 'Lead') . " · +" . $lead->customer_phone . "\n"
                . ($lead->interest ? $lead->interest . "\n" : '')
                . "Source: " . $lead->source;
            \App\Jobs\NotifyOwner::dispatch($t->id, $msg, $lead->assigned_to);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    /**
     * Bulk import leads from pasted text / CSV. Import only — never sends a message.
     * Body: { text, default_cc, source, tag, dedupe: skip|update|create, opt_in, dry_run }.
     */
    public function leadImport(Request $r)
    {
        $t       = $r->user()->tenant;
        $text    = (string) $r->input('text', '');
        $cc      = preg_replace('/\D+/', '', (string) $r->input('default_cc', '211')) ?: '211';
        $gSource = strtolower(trim((string) $r->input('source', 'import'))) ?: 'import';
        $gTag    = trim((string) $r->input('tag', ''));
        $dedupe  = strtolower((string) $r->input('dedupe', 'skip'));
        $optIn   = filter_var($r->input('opt_in', true), FILTER_VALIDATE_BOOLEAN);
        $dry     = filter_var($r->input('dry_run', false), FILTER_VALIDATE_BOOLEAN);
        if (! in_array($dedupe, ['skip', 'update', 'create'], true)) $dedupe = 'skip';

        if (trim($text) === '') return response()->json(['ok' => false, 'error' => 'Nothing to import'], 422);

        $rows = \App\Services\Bot\LeadImport::parseRows($text);
        if (count($rows) > 5000) return response()->json(['ok' => false, 'error' => 'Too many rows (max 5000 per import)'], 422);

        $created = $updated = $skipped = $invalid = 0;
        $sample  = [];
        $scorer  = new \App\Services\Bot\LeadScorer();

        foreach ($rows as $row) {
            $phone = \App\Services\Bot\LeadImport::normalizePhone((string) $row['phone'], $cc);
            if (! $phone) { $invalid++; continue; }

            $name   = trim((string) $row['name']);
            $source = strtolower(trim((string) $row['source'])) ?: $gSource;
            $tag    = trim((string) $row['tag']) ?: $gTag;
            if (count($sample) < 8) $sample[] = ['name' => $name, 'phone' => $phone, 'source' => $source, 'tag' => $tag];

            if ($dry) continue;

            $existing = ($dedupe === 'create') ? null : \App\Models\Lead::where('customer_phone', $phone)->first();

            if ($existing) {
                if ($dedupe === 'skip') { $skipped++; continue; }
                // update
                if ($name && ! $existing->customer_name) $existing->customer_name = $name;
                if ($tag)    $existing->tag = $tag;
                if ($source) $existing->source = $source;
                $existing->save();
                $updated++;
                continue;
            }

            $interest = $tag ?: '(imported)';
            \App\Models\Lead::create([
                'customer_phone'   => $phone,
                'customer_name'    => $name ?: null,
                'intent'           => 'lead',
                'interest'         => $interest,
                'dedupe_key'       => \App\Services\Bot\LeadDedupe::key($phone, 'lead', $interest),
                'lead_score'       => $scorer->score('lead', $interest),
                'source'           => $source,
                'tag'              => $tag ?: null,
                'marketing_opt_in' => $optIn,
                'status'           => 'new',
            ]);
            $created++;
        }

        if (! $dry) {
            \App\Support\BotTrace::log($t->id, 'panel', null, 'lead_imported', "c{$created} u{$updated} s{$skipped} x{$invalid}");
        }

        return response()->json([
            'ok'      => true,
            'dry_run' => $dry,
            'total'   => count($rows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'invalid' => $invalid,
            'sample'  => $sample,
        ]);
    }

    public function branches()
    {
        return response()->json(['branches' => $this->branchesList()]);
    }

    protected function branchesList(): array
    {
        return Branch::orderBy('name')->get()->map(fn (Branch $b) => [
            'id'      => (int) $b->id,
            'name'    => (string) $b->name,
            'phone'   => (string) ($b->phone ?? ''),
            'address' => (string) ($b->address ?? ''),
            'lat'     => $b->lat,
            'lng'     => $b->lng,
        ])->values()->all();
    }

    public function customers()
    {
        $map = [];
        foreach (CustomerProfile::all() as $c) {
            $map[$c->phone] = [
                'name'      => (string) ($c->name ?? ''),
                'alt_phone' => (string) ($c->alt_phone ?? ''),
                'email'     => (string) ($c->email ?? ''),
                'address'   => (string) ($c->address ?? ''),
                'notes'     => (string) ($c->notes ?? ''),
                'lang'      => (string) ($c->lang ?? ''),
                'greeting'  => (string) ($c->greeting ?? ''),
                'ss_markup_pct' => ($c->ss_markup_pct === null ? '' : (string) (float) $c->ss_markup_pct),
            ];
        }
        return response()->json(['ok' => true, 'customers' => (object) $map]);
    }

    public function botConfig(Request $r)
    {
        $t = $r->user()->tenant;
        return response()->json(['config' => [
            // E3.5 — default from the single FX source, not a hardcoded constant. When fx_v2 is on the
            // client overrides this with the canonical rate anyway (applyFxV2); an explicit usdUgx is
            // still honoured on the legacy path.
            'usdUgx'       => (float) $t->setting('usdUgx', \App\Services\Money\Fx::forTenant($t)->ratePerUsd('UGX')),
            'usdSsp'       => (float) $t->setting('usdSsp', \App\Services\Money\Fx::forTenant($t)->ratePerUsd('SSP')),
            'currency'     => (string) $t->setting('currency', 'auto'),
            'showSwitcher' => (bool) $t->setting('showSwitcher', false),
            'discountPct'  => (float) $t->setting('discountPct', 0),
            'discountAmt'  => (float) $t->setting('discountAmt', 0),
        ]]);
    }

    /* -------------------------------------------------- writes that persist */
    public function updateStatus(Request $r)
    {
        $o = Order::find((int) $r->query('row'));
        if (! $o) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $o->status = (string) $r->query('status', $o->status);
        if (! $o->customer_phone && $r->filled('phone')) $o->customer_phone = $r->query('phone');
        if (! $o->customer_name && $r->filled('name'))   $o->customer_name = $r->query('name');
        $o->save(); // OrderObserver fires the WhatsApp status notification

        return response()->json(['ok' => true]);
    }

    /**
     * Live kitchen board (restaurant KOT) for the seller panel. Mirrors the Filament
     * KitchenBoard exactly: tickets in the active flow, grouped by status, oldest first,
     * with modifiers un-folded off the stored name. Tenant-scoped by the global scope.
     */
    public function kitchen(Request $r)
    {
        $board = ['New', 'Accepted', 'Preparing', 'Ready', 'Dispatched']; // = KitchenBoard::BOARD

        $orders = Order::query()
            ->whereIn('status', $board)
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->orderBy('created_at')
            ->limit(300)
            ->get();

        $cols = array_fill_keys($board, []);
        foreach ($orders as $o) {
            $cols[$o->status][] = [
                'id'       => $o->id,
                'order_no' => (string) $o->order_no,
                'name'     => (string) $o->customer_name,
                'phone'    => (string) $o->customer_phone,
                'channel'  => (string) $o->channel,
                'mins'     => $o->created_at ? (int) $o->created_at->diffInMinutes(now()) : 0,
                'notes'    => trim((string) $o->notes),
                'next'     => $o->nextKitchenStatus(),
                'items'    => $this->kitchenLines($o),
            ];
        }

        return response()->json(['ok' => true, 'board' => $board, 'cols' => $cols]);
    }

    /**
     * Ticket line items. Prefers the order_items relation (WhatsApp/web orders); falls back
     * to items_json when there are no rows (POS orders store the cart in items_json only).
     * Modifiers are un-folded off the name in both paths so "Butter Chicken + Naan" shows
     * the base dish with a "↳ Naan" sub-line.
     */
    protected function kitchenLines(Order $o): array
    {
        $unfold = function (string $name, array $mods): string {
            if ($mods) {
                $suffix = ' + ' . implode(', ', $mods);
                if (str_ends_with($name, $suffix)) {
                    $name = substr($name, 0, -strlen($suffix));
                }
            }
            return $name;
        };
        $modNames = fn ($m) => is_array($m)
            ? array_values(array_filter(array_map(fn ($x) => trim((string) ($x['name'] ?? '')), $m)))
            : [];

        if ($o->items->isNotEmpty()) {
            return $o->items->map(function ($i) use ($unfold, $modNames) {
                $mods = $modNames($i->modifiers);
                return ['qty' => (int) $i->qty, 'name' => $unfold((string) $i->name, $mods), 'mods' => $mods, 'notes' => trim((string) $i->notes)];
            })->all();
        }

        $json = is_array($o->items_json) ? $o->items_json : [];
        return array_map(function ($it) use ($unfold, $modNames) {
            $mods = $modNames($it['modifiers'] ?? null);
            return [
                'qty'   => (int) ($it['qty'] ?? 1),
                'name'  => $unfold(trim((string) ($it['name'] ?? '')), $mods),
                'mods'  => $mods,
                'notes' => trim((string) ($it['notes'] ?? '')),
            ];
        }, $json);
    }

    /** Advance one ticket to the next kitchen stage. OrderObserver notifies the customer. */
    public function kitchenAdvance(Request $r)
    {
        $o = Order::find((int) $r->query('id'));
        if (! $o) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $next = $o->nextKitchenStatus();
        if (! $next) {
            return response()->json(['ok' => false, 'error' => 'final_stage']);
        }
        $o->status = $next;
        $o->save(); // OrderObserver stamps timing + fires the WhatsApp stage notification

        return response()->json(['ok' => true, 'status' => $next]);
    }

    /**
     * Return a 403 upgrade signal if the tenant's plan lacks a feature.
     * The panel reads {error:'upgrade_required'} and shows an upgrade prompt.
     */
    private function planDeny(Request $r, string $feature)
    {
        $t = $r->user()->tenant ?? null;
        if ($t && ! $t->can($feature)) {
            return response()->json([
                'ok'      => false,
                'error'   => 'upgrade_required',
                'feature' => $feature,
                'plan'    => $t->effectivePlan(),
            ], 403);
        }
        return null;
    }

    public function saveOrder(Request $r)
    {
        $rowId = (int) $r->query('row');
        $o = $rowId ? Order::find($rowId) : null;

        // Creating a NEW order from the panel (POS / new sale) is a paid feature.
        if (! $rowId) {
            if ($d = $this->planDeny($r, 'pos')) return $d;
        }

        // No row id -> this is a NEW order (POS / new sale). Create it.
        $isNew = false;
        if (! $o) {
            if ($rowId) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }
            $o = new Order();
            $o->status  = 'New';
            $o->channel = (string) ($r->query('channel', 'pos'));
            $isNew = true;
        }

        $items = json_decode((string) $r->query('items', '[]'), true);
        if (is_array($items)) {
            $clean = [];
            $textParts = [];
            foreach ($items as $it) {
                $name = trim((string) ($it['name'] ?? ''));
                if ($name === '') continue;
                $qty   = (int) ($it['qty'] ?? 1);
                $price = (float) ($it['price'] ?? 0);
                $clean[] = ['name' => $name, 'qty' => $qty, 'price' => $price];
                $textParts[] = $qty.'x '.$name;
            }
            $o->items_json = $clean;
            $o->items_text = implode(', ', $textParts);
        }

        if ($r->filled('total'))    $o->total          = (float) $r->query('total');
        if ($r->filled('status'))   $o->status         = (string) $r->query('status');
        if ($r->filled('name'))     $o->customer_name  = (string) $r->query('name');
        if ($r->filled('phone'))    $o->customer_phone = preg_replace('/[^0-9]/', '', (string) $r->query('phone'));
        if ($r->filled('payment')) {
            // E4.5 — this path carries a cash/MoMo method (never a "sell:" currency marker);
            // write the typed column and keep `payment` in lock-step for backward compatibility.
            $o->payment        = (string) $r->query('payment');
            $o->payment_method = (string) $r->query('payment');
        }
        if ($r->filled('location')) $o->location       = (string) $r->query('location');
        if ($r->filled('channel'))  $o->channel        = (string) $r->query('channel');

        $branch = $r->query('branch', '');
        if ($branch !== '' && is_numeric($branch)) {
            $o->branch_id = (int) $branch;
        }

        $o->save();   // OrderObserver assigns order_no + track_token on create

        return response()->json([
            'ok'       => true,
            'created'  => $isNew,
            'id'       => (int) $o->id,
            'order_no' => (string) $o->order_no,
        ]);
    }

    /**
     * Effective South Sudan markup percent for a customer: their per-client override
     * (customer_profiles.ss_markup_pct) when set, otherwise the tenant default
     * (settings.ssMarkupPct). Returns 0 when neither is set.
     */
    private function ssMarkupFor(Tenant $t, string $phone): float
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone !== '') {
            $c = \App\Models\CustomerProfile::where('tenant_id', $t->id)
                ->where('phone', $phone)->first();
            if ($c && $c->ss_markup_pct !== null) return (float) $c->ss_markup_pct;
        }
        return (float) $t->setting('ssMarkupPct', 0);
    }

    /**
     * Proactively build a PDF quotation (with product photos) from items entered in the
     * panel and send it to a customer's WhatsApp. Reuses the same OrderCalculator +
     * QuotationService the bot uses. Input: phone, name (optional), items (JSON [{name,qty}]).
     */
    public function quotationSend(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        app(\App\Support\TenantContext::class)->set($t->id);

        $phone = preg_replace('/[^0-9]/', '', (string) $r->input('phone', ''));
        if ($phone === '') return response()->json(['ok' => false, 'error' => 'no_phone', 'message' => 'Enter the customer\'s WhatsApp number.']);

        $name  = trim((string) $r->input('name', ''));
        $items = json_decode((string) $r->input('items', '[]'), true);
        $calc  = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                $nm = trim((string) ($it['name'] ?? $it['query'] ?? ''));
                if ($nm === '') continue;
                $calc[] = ['query' => $nm, 'qty' => max(1, (int) ($it['qty'] ?? 1)), 'price' => (float) ($it['price'] ?? 0)];
            }
        }
        if (! $calc) return response()->json(['ok' => false, 'error' => 'no_items', 'message' => 'Add at least one item to the cart.']);

        $svc = app(\App\Services\Bot\QuotationService::class);
        if (! $svc->available()) {
            return response()->json(['ok' => false, 'error' => 'pdf_unavailable', 'message' => 'PDF engine (dompdf) is not installed yet, so a PDF quotation can\'t be created. Install dompdf to enable this.']);
        }

        $quote = app(\App\Services\Bot\OrderCalculator::class)->quote($t, $calc);

        // South Sudan / Juba pricing now lives in one place (CurrencyPricer), shared with the bot so
        // the two can never drift. Same formula: base × (1 + markup%) ÷ usdUgx, nearest $0.50.
        $usdMeta = null;
        if (strtoupper(trim((string) $r->input('currency', ''))) === 'USD') {
            $res     = app(\App\Services\Bot\Pricing\CurrencyPricer::class)->toUsd($t, $phone, $quote);
            $quote   = $res['quote'];
            $usdMeta = $res['meta'];
        }

        $doc   = $svc->generate($t, $phone, $name, $quote);
        if (! $doc) return response()->json(['ok' => false, 'error' => 'generate_failed', 'message' => 'None of the items matched a priced product, so there was nothing to quote.']);

        $quoteRow = $svc->persist($t, $phone, $name, $quote, $doc, 'panel');
        if ($usdMeta && $quoteRow) {
            $quoteRow->meta = $usdMeta;
            $quoteRow->save();
        }

        $sent = false;
        $err  = null;
        try {
            $gateway = app(\App\Services\WhatsApp\WhatsAppManager::class)->forTenant($t);
            if (method_exists($gateway, 'sendDocument') && $t->whatsapp_instance) {
                $cur     = $doc['currency'];
                $totTxt  = $cur === 'USD' ? number_format($doc['total'], 2) : number_format($doc['total']);
                $caption = "📄 Quotation {$doc['no']} — Total {$cur} " . $totTxt
                         . '. Valid ' . (((int) $t->setting('quote_validity_days', 14)) ?: 14) . ' days. Reply to confirm and we\'ll arrange delivery.';
                $media   = $doc['b64'] !== '' ? $doc['b64'] : $doc['url'];
                $gateway->sendDocument($t->whatsapp_instance, $phone, $media, $doc['fileName'], $caption);
                $sent = true;
                \App\Models\MessageLog::record($t->id, $phone, $t->whatsapp_instance, 'out', 'panel', "[quotation {$doc['no']}] " . $caption, null, null, ['via' => 'panel', 'kind' => 'quotation', 'quote_no' => $doc['no']]);
            }
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            \Log::warning('panel quotation send failed: ' . $e->getMessage());
        }

        return response()->json([
            'ok'       => true,
            'quote_no' => $doc['no'],
            'total'    => $doc['total'],
            'currency' => $doc['currency'],
            'url'      => $doc['url'],
            'quote_id' => $quoteRow?->id,
            'sent'     => $sent,
            'error'    => $err,
        ]);
    }

    /** List quotations the bot or panel has sent (newest first) with a re-download link. */
    public function quotations(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        app(\App\Support\TenantContext::class)->set($t->id);

        $rows = \App\Models\Quotation::where('tenant_id', $t->id)
            ->when((int) $r->input('include_archived', 0) !== 1, fn ($qq) => $qq->where('status', '!=', 'archived'))
            ->orderByDesc('id')->limit(200)->get();

        $out = [];
        foreach ($rows as $q) {
            $url = $q->pdf_path ? ('/papi/quotation-pdf?id=' . $q->id) : '';
            $orderNo = '';
            if ($q->order_id) {
                $ord = \App\Models\Order::withoutGlobalScopes()->find($q->order_id);
                $orderNo = $ord ? (string) $ord->order_no : '';
            }
            $out[] = [
                'id'          => (int) $q->id,
                'quote_no'    => (string) $q->quote_no,
                'name'        => (string) ($q->customer_name ?? ''),
                'phone'       => (string) $q->customer_phone,
                'currency'    => (string) $q->currency,
                'total'       => (float) $q->total,
                'status'      => (string) $q->status,
                'source'      => (string) $q->source,
                'valid_until' => optional($q->valid_until)->toDateString(),
                'send_count'  => (int) $q->send_count,
                'order_no'    => $orderNo,
                'at'          => optional($q->created_at)->toIso8601String(),
                'url'         => $url,
            ];
        }

        return response()->json(['ok' => true, 'rows' => $out]);
    }

    /** Resend an existing quotation's PDF to the customer (or an override phone). */
    public function quotationResend(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        app(\App\Support\TenantContext::class)->set($t->id);

        $q = \App\Models\Quotation::where('tenant_id', $t->id)->find((int) $r->input('id'));
        if (! $q) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $phone = preg_replace('/[^0-9]/', '', (string) $r->input('phone', $q->customer_phone));
        if ($phone === '') return response()->json(['ok' => false, 'error' => 'no_phone', 'message' => 'No phone to send to.']);

        // Prefer the stored PDF; regenerate from stored items if the file is gone.
        $b64 = ''; $url = '';
        try {
            if ($q->pdf_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($q->pdf_path)) {
                $b64 = base64_encode(\Illuminate\Support\Facades\Storage::disk('public')->get($q->pdf_path));
                $url = '/papi/quotation-pdf?id=' . $q->id;
            }
        } catch (\Throwable $e) { /* fall through to regenerate */ }

        $fileName = 'Quotation-' . $q->quote_no . '.pdf';
        if ($b64 === '') {
            $svc = app(\App\Services\Bot\QuotationService::class);
            if (! $svc->available()) return response()->json(['ok' => false, 'error' => 'pdf_unavailable', 'message' => 'PDF engine not installed.']);
            $quote = $this->quoteArrayFromQuotation($q);
            $doc   = $svc->generate($t, $phone, (string) ($q->customer_name ?? ''), $quote);
            if (! $doc) return response()->json(['ok' => false, 'error' => 'regen_failed', 'message' => 'Could not rebuild the PDF.']);
            $b64 = $doc['b64']; $url = $doc['url']; $fileName = $doc['fileName'];
            $q->pdf_path = $doc['path'];
        }

        $sent = false; $err = null;
        try {
            $gateway = app(\App\Services\WhatsApp\WhatsAppManager::class)->forTenant($t);
            if (method_exists($gateway, 'sendDocument') && $t->whatsapp_instance) {
                $valid   = (int) $t->setting('quote_validity_days', 14); if ($valid <= 0) $valid = 14;
                $caption = "📄 Quotation {$q->quote_no} — Total {$q->currency} " . number_format((float) $q->total)
                         . ". Valid {$valid} days. Reply to confirm and we'll arrange delivery.";
                $gateway->sendDocument($t->whatsapp_instance, $phone, $b64 !== '' ? $b64 : $url, $fileName, $caption);
                $sent = true;
                \App\Models\MessageLog::record($t->id, $phone, $t->whatsapp_instance, 'out', 'panel', "[quotation {$q->quote_no} resent] " . $caption, null, null, ['via' => 'panel', 'kind' => 'quotation', 'quote_no' => $q->quote_no]);
            }
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            \Log::warning('quotation resend failed: ' . $e->getMessage());
        }

        $q->send_count   = (int) $q->send_count + 1;
        $q->last_sent_at = now();
        $q->save();

        return response()->json(['ok' => true, 'sent' => $sent, 'url' => $url, 'send_count' => (int) $q->send_count, 'error' => $err]);
    }

    /** Mark a quotation accepted / declined / expired / sent. */
    public function quotationStatus(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        app(\App\Support\TenantContext::class)->set($t->id);

        $q = \App\Models\Quotation::where('tenant_id', $t->id)->find((int) $r->input('id'));
        if (! $q) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $status  = (string) $r->input('status', '');
        $allowed = ['sent', 'accepted', 'declined', 'expired'];
        if (! in_array($status, $allowed, true)) return response()->json(['ok' => false, 'error' => 'bad_status'], 422);
        if ($q->status === 'converted') return response()->json(['ok' => false, 'error' => 'already_converted', 'message' => 'This quotation is already an order.']);

        $q->status = $status;
        $q->save();
        return response()->json(['ok' => true, 'status' => $q->status]);
    }

    /** Convert a quotation into a real order, reusing the order pipeline. */
    public function quotationConvert(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        app(\App\Support\TenantContext::class)->set($t->id);

        $q = \App\Models\Quotation::with('items')->where('tenant_id', $t->id)->find((int) $r->input('id'));
        if (! $q) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        if ($q->order_id) {
            $ord = \App\Models\Order::withoutGlobalScopes()->find($q->order_id);
            return response()->json(['ok' => true, 'already' => true, 'order_id' => (int) $q->order_id, 'order_no' => $ord ? (string) $ord->order_no : '']);
        }

        // Currency handling on convert:
        //  • CONVERTED basis (catalogue priced in UGX, USD is only a display layer): the USD
        //    quotation is stored back as marked-up UGX so orders + Reports share one base currency.
        //    Convert at the rate captured on the quote.
        //  • NATIVE basis (the shop's prices ARE in USD, e.g. South Sudan): the stored USD values
        //    are the real prices. Multiplying them by the UGX rate would inflate every order (and
        //    the earlier divide-to-USD is what floored small prices to 0.00) — so keep them as-is.
        //    The quote records native=true in its meta at creation time (see CurrencyPricer::toUsd).
        $usd     = strtoupper((string) $q->currency) === 'USD';
        $native  = (bool) data_get($q->meta, 'native')
                   || app(\App\Services\Bot\Pricing\CurrencyPricer::class)->isNativeBasis($t);
        $convert = $usd && ! $native;   // only convert when USD is a display layer over a UGX base
        // E3.5 — the historical rate captured on the quote (meta.usd_rate) is preserved; the fallback
        // defaults from the single FX source rather than a hardcoded constant.
        $rate = $convert ? (float) (data_get($q->meta, 'usd_rate') ?: $t->setting('usdUgx', \App\Services\Money\Fx::forTenant($t)->ratePerUsd('UGX'))) : 1.0;
        if ($convert && $rate <= 0) $rate = (float) $t->setting('usdUgx', \App\Services\Money\Fx::forTenant($t)->ratePerUsd('UGX'));

        $items = []; $textParts = [];
        foreach ($q->items as $it) {
            if (! $it->matched) continue;
            $unit        = $convert ? round(((float) $it->unit_price) * $rate) : (float) $it->unit_price;
            $items[]     = ['name' => (string) $it->name, 'qty' => (int) $it->qty, 'price' => $unit];
            $textParts[] = ((int) $it->qty) . 'x ' . $it->name;
        }
        if (! $items) return response()->json(['ok' => false, 'error' => 'no_items', 'message' => 'This quotation has no priced items to convert.']);

        $o = new \App\Models\Order();
        $o->tenant_id      = $t->id;
        $o->status         = 'New';
        $o->channel        = 'quote';
        $o->customer_name  = (string) ($q->customer_name ?? '');
        $o->customer_phone = (string) $q->customer_phone;
        $o->items_json     = $items;
        $o->items_text     = implode(', ', $textParts);
        $o->total          = $convert ? round(((float) $q->total) * $rate) : (float) $q->total;
        $o->save();        // OrderObserver assigns order_no + track_token

        $q->order_id = (int) $o->id;
        $q->status   = 'converted';
        $q->save();

        return response()->json(['ok' => true, 'order_id' => (int) $o->id, 'order_no' => (string) $o->order_no]);
    }

    /** Rebuild an OrderCalculator-shaped quote array from a stored quotation (for PDF regen). */
    /**
     * Stream a quotation PDF through the app (no public/storage symlink needed, always the right host).
     * Tenant-scoped. Regenerates from the stored line items if the file was wiped (e.g. a redeploy).
     */
    public function quotationPdf(Request $r)
    {
        $t  = $r->user()->tenant;
        $id = (int) $r->input('id');
        $q  = \App\Models\Quotation::where('tenant_id', $t->id)->find($id);
        if (! $q) abort(404);

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $have = $q->pdf_path && $disk->exists($q->pdf_path);

        if (! $have) {
            try {
                $svc   = app(\App\Services\Bot\QuotationService::class);
                $quote = $this->quoteArrayFromQuotation($q);
                $doc   = $svc->generate($t, (string) ($q->customer_phone ?? ''), (string) ($q->customer_name ?? ''), $quote, $q->quote_no);
                if ($doc && ! empty($doc['path'])) {
                    $q->pdf_path = $doc['path'];
                    $q->save();
                    $have = $disk->exists($q->pdf_path);
                }
            } catch (\Throwable $e) {
                \Log::warning('quotationPdf regenerate failed: ' . $e->getMessage());
            }
        }

        if (! $have) abort(404, 'PDF unavailable');

        return $disk->response($q->pdf_path, 'Quotation-' . ($q->quote_no ?: $q->id) . '.pdf',
            ['Content-Type' => 'application/pdf'], 'inline');
    }

    private function quoteArrayFromQuotation(\App\Models\Quotation $q): array
    {
        $lines = [];
        foreach ($q->items as $it) {
            $lines[] = [
                'name'    => (string) $it->name,
                'qty'     => (int) $it->qty,
                'price'   => (float) $it->unit_price,
                'sum'     => (float) $it->line_total,
                'unit'    => (string) ($it->unit_label ?? ''),
                'moq'     => null,
                'image'   => (string) ($it->image_url ?? ''),
                'matched' => (bool) $it->matched,
            ];
        }
        return ['lines' => $lines, 'total' => (float) $q->total, 'currency' => (string) $q->currency];
    }

    /** Read one quotation WITH its line items — so the panel can show what's inside it. */
    public function quotationShow(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        app(\App\Support\TenantContext::class)->set($t->id);

        $q = \App\Models\Quotation::with('items')->where('tenant_id', $t->id)->find((int) $r->input('id'));
        if (! $q) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $url = $q->pdf_path ? ('/papi/quotation-pdf?id=' . $q->id) : '';

        $items = [];
        foreach ($q->items as $it) {
            $items[] = ['id' => (int) $it->id, 'name' => (string) $it->name, 'qty' => (int) $it->qty,
                'unit_price' => (float) $it->unit_price, 'line_total' => (float) $it->line_total,
                'unit_label' => (string) ($it->unit_label ?? '')];
        }

        return response()->json(['ok' => true, 'quote' => [
            'id' => (int) $q->id, 'quote_no' => (string) $q->quote_no, 'name' => (string) ($q->customer_name ?? ''),
            'phone' => (string) $q->customer_phone, 'currency' => (string) $q->currency, 'total' => (float) $q->total,
            'status' => (string) $q->status, 'source' => (string) $q->source,
            'valid_until' => optional($q->valid_until)->toDateString(),
            'order_id' => $q->order_id ? (int) $q->order_id : null, 'url' => $url, 'items' => $items,
        ]]);
    }

    /** Edit a quotation's items / customer, recompute the total, and rebuild the PDF (same number). */
    public function quotationUpdate(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        app(\App\Support\TenantContext::class)->set($t->id);

        $q = \App\Models\Quotation::with('items')->where('tenant_id', $t->id)->find((int) $r->input('id'));
        if (! $q) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        if (in_array($q->status, ['converted', 'accepted'], true)) return response()->json(['ok' => false, 'error' => 'locked', 'message' => 'An accepted or converted quotation is read-only. Duplicate it to make changes.'], 422);

        $items = json_decode((string) $r->input('items', '[]'), true);
        if (! is_array($items) || ! $items) return response()->json(['ok' => false, 'error' => 'no_items', 'message' => 'Add at least one line.'], 422);

        $currency = strtoupper(trim((string) $r->input('currency', $q->currency))) ?: 'UGX';
        $lines = []; $total = 0.0;
        foreach ($items as $it) {
            $name = trim((string) ($it['name'] ?? ''));
            if ($name === '') continue;
            $qty  = max(1, (int) ($it['qty'] ?? 1));
            $unit = max(0, (float) ($it['unit_price'] ?? $it['price'] ?? 0));
            $sum  = $unit * $qty;
            $total += $sum;
            $lines[] = ['name' => $name, 'qty' => $qty, 'price' => $unit, 'sum' => $sum,
                'unit' => (string) ($it['unit_label'] ?? ''), 'moq' => null, 'image' => '', 'matched' => true];
        }
        if (! $lines) return response()->json(['ok' => false, 'error' => 'no_items'], 422);

        $name  = trim((string) $r->input('name', (string) ($q->customer_name ?? '')));
        $phone = preg_replace('/[^0-9]/', '', (string) $r->input('phone', (string) $q->customer_phone));
        $quote = ['lines' => $lines, 'total' => round($total, $currency === 'USD' ? 2 : 0), 'currency' => $currency];

        $url = '';
        $svc = app(\App\Services\Bot\QuotationService::class);
        if ($svc->available()) {
            $doc = $svc->generate($t, $phone, $name, $quote, $q->quote_no);   // keep the same quote number
            if ($doc) {
                $q->pdf_path = (string) ($doc['path'] ?? $q->pdf_path);
                $url = (string) ($doc['url'] ?? '');
                \App\Support\QuotationPipeline::stage($t->id, $phone, 'pdf_generate', true, (string) $q->quote_no, 'edited in panel');
            } else {
                \App\Support\QuotationPipeline::stage($t->id, $phone, 'pdf_generate', false, (string) $q->quote_no, 'panel edit regenerate failed');
            }
        } else {
            \App\Support\QuotationPipeline::stage($t->id, $phone, 'pdf_unavailable', false, (string) $q->quote_no);
        }

        \App\Models\QuotationItem::where('quotation_id', $q->id)->delete();
        foreach ($lines as $l) {
            \App\Models\QuotationItem::create(['quotation_id' => $q->id, 'tenant_id' => $t->id,
                'name' => $l['name'], 'qty' => $l['qty'], 'unit_price' => $l['price'],
                'line_total' => $l['sum'], 'unit_label' => $l['unit'] ?: null, 'matched' => true]);
        }
        $q->customer_name  = $name !== '' ? $name : null;
        $q->customer_phone = $phone;
        $q->currency       = $currency;
        $q->total          = $quote['total'];
        $q->save();

        return response()->json(['ok' => true, 'total' => (float) $q->total, 'currency' => $currency, 'url' => $url]);
    }

    /**
     * Archive a quotation (default) — preserves reporting, like most CRMs. Hard delete only with
     * force=1. Converted quotations are never removed (they're orders now).
     */
    public function quotationDelete(Request $r)
    {
        $t = $r->user()->tenant;
        if (! $t) return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        app(\App\Support\TenantContext::class)->set($t->id);

        $q = \App\Models\Quotation::where('tenant_id', $t->id)->find((int) $r->input('id'));
        if (! $q) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        if ($q->status === 'converted') return response()->json(['ok' => false, 'error' => 'already_converted', 'message' => 'This quotation became an order; archive/delete the order instead.'], 422);

        // Default: archive (keep the record + PDF for reporting). Hard delete is opt-in and rare.
        if ((int) $r->input('force', 0) !== 1) {
            $q->status = 'archived';
            $q->save();
            return response()->json(['ok' => true, 'archived' => true]);
        }

        try {
            if ($q->pdf_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($q->pdf_path)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($q->pdf_path);
            }
        } catch (\Throwable $e) { /* best-effort */ }

        \App\Models\QuotationItem::where('quotation_id', $q->id)->delete();
        $q->delete();

        return response()->json(['ok' => true, 'deleted' => true]);
    }

    public function updateProduct(Request $r)
    {
        $p = Product::find((int) $r->query('row'));
        if (! $p) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        // Category-scoped staff: may only touch products in their categories, and may not move a
        // product into a category they don't have.
        $scope = $r->user()->categoryScope();
        if (is_array($scope)) {
            $cur = (string) ($p->category ?? '');
            if (! in_array($cur, $scope, true)) {
                return response()->json(['ok' => false, 'error' => 'forbidden_category'], 403);
            }
            if ($r->filled('category') && ! in_array(trim((string) $r->query('category', '')), $scope, true)) {
                return response()->json(['ok' => false, 'error' => 'forbidden_category'], 403);
            }
        }
        if ($r->has('cost')) {
            $cv = trim((string) $r->query('cost', ''));
            $p->cost_price = ($cv === '' ? null : (float) $cv);
        }
        if ($r->has('price')) {
            $price = (float) $r->query('price', 0);
            $p->base_price = $price;
            $p->price      = $price;
        }
        if ($r->has('stock')) $p->stock = (int) $r->query('stock', $p->stock);
        if ($r->has('image')) $p->image_url = trim((string) $r->query('image', ''));
        foreach (['gallery_1', 'gallery_2', 'gallery_3'] as $g) {
            if ($r->has($g)) $p->{$g} = (trim((string) $r->query($g, '')) ?: null);
        }
        if ($r->filled('name')) $p->name = trim((string) $r->query('name'));
        if ($r->filled('category')) $p->category = trim((string) $r->query('category', ''));
        if ($r->has('moq'))        $p->moq        = $r->query('moq') !== '' ? max(1, (int) $r->query('moq')) : null;
        if ($r->has('pack_size'))  $p->pack_size  = $r->query('pack_size') !== '' ? max(1, (int) $r->query('pack_size')) : null;
        if ($r->has('unit_label')) $p->unit_label = trim((string) $r->query('unit_label', '')) ?: null;
        $p->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Bulk-set selling unit / pack size / MOQ across many products at once (for manufacturer-style
     * tenants filling in carton/pack/MOQ so the generated price list reads cleanly). Tenant-scoped
     * by id list. Only the fields supplied are written; blank fields are left untouched.
     */
    public function productBulkMeta(Request $r)
    {
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);

        $updates = [];
        if ($r->filled('unit_label')) $updates['unit_label'] = trim((string) $r->query('unit_label'));
        if ($r->filled('pack_size'))  $updates['pack_size']  = max(1, (int) $r->query('pack_size'));
        if ($r->filled('moq'))        $updates['moq']        = max(1, (int) $r->query('moq'));
        if (! $updates) return response()->json(['ok' => false, 'error' => 'nothing_to_set'], 422);

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $r->query('ids', '')))));
        if (! $ids) return response()->json(['ok' => false, 'error' => 'no_ids'], 422);

        $n = Product::where('tenant_id', $t->id)->whereIn('id', $ids)->update($updates);

        return response()->json(['ok' => true, 'updated' => $n, 'set' => array_keys($updates)]);
    }

    /** Normalise a product name for fuzzy matching (uppercase, strip punctuation, collapse spaces). */
    private function normName(string $s): string
    {
        $s = strtoupper($s);
        $s = preg_replace('/[^A-Z0-9 ]/', ' ', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /**
     * POST /papi/stock/reconcile-cache — E6.5. Derive the legacy products.stock column from the stock
     * ledger projection so the column becomes a cache of the ledger truth ("stock becomes derived").
     * Owner/manager only, and only when the tenant reads on-hand from the ledger (stock_v2 +
     * stock_ledger). Never zeros a product missing from the ledger (see StockLedger::syncProductStockCache).
     */
    public function stockReconcileCache(Request $r)
    {
        $t = $r->user()->tenant;
        $u = $r->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! ($u->isOwnerLike() || in_array((string) $u->role, ['manager'], true)))
            return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        if (! \App\Services\Inventory\StockLedger::readsFromLedger($t))
            return response()->json(['ok' => false, 'error' => 'stock_v2_off',
                'message' => 'Enable stock_v2 (and stock_ledger) before deriving stock from the ledger.'], 422);

        $count = \App\Services\Inventory\StockLedger::forTenant($t)->syncProductStockCache();
        return response()->json(['ok' => true, 'reconciled' => $count]);
    }

    /**
     * Sync stock from a supermarket report CSV (columns: name, code, stock). Matches each row to an
     * existing product by sku → barcode → normalised name, then sets stock and active=(stock>0) so
     * out-of-stock items stop showing. NEVER touches images, price, name or category. Backfills the
     * POS item code into an empty sku so future syncs match exactly by code. Rows with blank stock
     * are skipped (left unchanged). dry-run unless apply=1. Returns a full validation summary.
     */
    public function stockSync(Request $r)
    {
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);

        $csv = trim((string) $r->input('csv', ''));
        if ($csv === '') return response()->json(['ok' => false, 'error' => 'empty_csv'], 422);

        $parsed = $this->parseStockRows($csv);
        if (isset($parsed['error'])) return response()->json(['ok' => false, 'error' => $parsed['error']], 422);
        $rows = $parsed['rows'];

        $apply = (int) $r->input('apply', 0) === 1;

        $products = \App\Models\Product::where('tenant_id', $t->id)->get(['id', 'name', 'sku', 'barcode', 'stock', 'active']);
        $byName = [];
        $bySku  = [];
        $byBar  = [];
        foreach ($products as $p) {
            $byName[$this->normName((string) $p->name)] = $p;
            if (trim((string) $p->sku) !== '')     $bySku[trim((string) $p->sku)] = $p;
            if (trim((string) $p->barcode) !== '') $byBar[trim((string) $p->barcode)] = $p;
        }

        $matched = $updated = $hidden = $neg = $skuFilled = 0;
        $notFound = [];
        $ups = [];
        $stockDeltas = [];   // E6.4 (prereq) — id => [old,new,sku] for the ledger mirror

        foreach ($rows as $row) {
            $name  = (string) $row['name'];
            $code  = (string) $row['code'];
            $stock = (int) $row['stock'];
            if ($name === '' && $code === '') continue;

            $p = ($code !== '' && isset($bySku[$code])) ? $bySku[$code]
               : (($code !== '' && isset($byBar[$code])) ? $byBar[$code]
               : ($byName[$this->normName($name)] ?? null));

            if (! $p) { if (count($notFound) < 25) $notFound[] = ($name !== '' ? $name : $code); continue; }

            $matched++;
            $active = $stock > 0;
            if ($stock <= 0) $hidden++;
            if ($stock < 0)  $neg++;

            $u = ['stock' => $stock, 'active' => $active];
            if (trim((string) $p->sku) === '' && $code !== '') { $u['sku'] = $code; $skuFilled++; }
            $ups[$p->id] = $u;
            // E6.4 (prereq) — this raw bulk update bypasses Eloquent events, so remember the stock
            // delta so we can mirror it into the stock ledger explicitly after applying.
            $stockDeltas[$p->id] = ['old' => (float) $p->stock, 'new' => (float) $stock, 'sku' => (string) $p->sku];
            $updated++;
        }

        if ($apply && $ups) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($ups) {
                foreach ($ups as $id => $u) {
                    \Illuminate\Support\Facades\DB::table('products')->where('id', $id)->update($u);
                }
            });

            // E6.4 (prereq) — mirror the applied stock changes into the ONE stock ledger as ADJUST
            // movements (gated on stock_ledger; best-effort). Keeps the projection correct for the
            // CSV sync path, which the Product observer can't see (raw update = no model events).
            if ((bool) $t->setting('stock_ledger', false)) {
                $sl = \App\Services\Inventory\StockLedger::forTenant($t);
                foreach ($stockDeltas as $id => $d) {
                    $delta = round($d['new'] - $d['old'], 3);
                    if (abs($delta) < 0.0001) continue;
                    try {
                        $sl->adjust([
                            'product_id' => $id, 'sku' => $d['sku'] ?: null,
                            'location' => \App\Services\Inventory\StockLedger::LOC_KAMPALA,
                            'qty' => $delta, 'ref_type' => 'stock_sync', 'ref_id' => $id,
                            'note' => 'CSV stock sync', 'created_by' => (string) ($r->user()->name ?? ''),
                        ]);
                    } catch (\Throwable $e) { /* never break the sync */ }
                }
            }
        }

        return response()->json([
            'ok'                => true,
            'apply'             => $apply,
            'rows'              => count($rows),
            'matched'           => $matched,
            'updated'           => $updated,
            'hidden_out_of_stock' => $hidden,
            'negative_stock'    => $neg,
            'sku_backfilled'    => $skuFilled,
            'not_found'         => count($notFound),
            'not_found_samples' => array_slice($notFound, 0, 15),
        ]);
    }

    /** Find a column index by exact then partial header name. */
    private function colIndex(array $cols, array $names): ?int
    {
        foreach ($names as $n) { $i = array_search($n, $cols, true); if ($i !== false) return $i; }
        foreach ($cols as $i => $c) { foreach ($names as $n) { if ($c !== '' && str_contains($c, $n)) return $i; } }
        return null;
    }

    /**
     * Parse an uploaded stock CSV into normalised rows [['name','code','stock'], ...].
     * Accepts BOTH formats automatically:
     *   (a) Clean:   header has "name" + "stock" (+ optional "code")  → one row per product.
     *   (b) Raw POS: an "Item Wise Stock Summary" export with title rows above a header containing
     *       "Item Name"/"Item Code"/"Unit"/"Quantity", where each item repeats per unit. We find the
     *       header, aggregate per item code (PIECES preferred; else sum of its rows), and emit one
     *       stock figure per item — so the owner can upload exactly what the POS gives them.
     */
    private function parseStockRows(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (! $lines) return ['error' => 'empty_csv'];
        if (count($lines) > 60000) return ['error' => 'too_many_rows'];

        // Locate the header row (scan the first 15 lines) and the format.
        $headerIdx = -1; $cols = []; $mode = '';
        foreach (array_slice($lines, 0, 15) as $idx => $ln) {
            $cells = array_map(fn ($c) => strtolower(trim($c)), str_getcsv($ln));
            if (in_array('name', $cells, true) && in_array('stock', $cells, true)) { $headerIdx = $idx; $cols = $cells; $mode = 'clean'; break; }
            $joined = implode('|', $cells);
            if (str_contains($joined, 'item name') || (str_contains($joined, 'item code') && (str_contains($joined, 'quantity') || str_contains($joined, 'qty')))) {
                $headerIdx = $idx; $cols = $cells; $mode = 'raw'; break;
            }
        }
        if ($headerIdx < 0) return ['error' => 'header_not_found'];

        $data = array_slice($lines, $headerIdx + 1);

        if ($mode === 'clean') {
            $iName = array_search('name', $cols, true);
            $iCode = array_search('code', $cols, true);
            $iStock = array_search('stock', $cols, true);
            $out = [];
            foreach ($data as $ln) {
                if (trim($ln) === '') continue;
                $c = str_getcsv($ln);
                $st = trim((string) ($c[$iStock] ?? ''));
                if ($st === '' || ! is_numeric(str_replace(',', '', $st))) continue;
                $out[] = [
                    'name'  => trim((string) ($c[$iName] ?? '')),
                    'code'  => $iCode !== false ? trim((string) ($c[$iCode] ?? '')) : '',
                    'stock' => (int) round((float) str_replace(',', '', $st)),
                ];
            }
            return ['rows' => $out];
        }

        // raw POS
        $iCode = $this->colIndex($cols, ['item code', 'code']);
        $iName = $this->colIndex($cols, ['item name', 'particulars', 'name']);
        $iUnit = $this->colIndex($cols, ['unit', 'uom']);
        $iQty  = $this->colIndex($cols, ['quantity', 'qty', 'closing', 'balance', 'stock']);
        if ($iName === null || $iQty === null) return ['error' => 'raw_missing_columns'];

        $pieceUnits = ['PIECES', 'PIECE', 'PCS', 'PC', 'NOS', 'NO', 'EACH', 'UNIT', 'UNITS'];
        $agg = [];
        foreach ($data as $ln) {
            if (trim($ln) === '') continue;
            $c = str_getcsv($ln);
            $name = trim((string) ($c[$iName] ?? ''));
            if ($name === '') continue;
            $code = $iCode !== null ? trim((string) ($c[$iCode] ?? '')) : '';
            $unit = $iUnit !== null ? strtoupper(trim((string) ($c[$iUnit] ?? ''))) : '';
            $qRaw = str_replace(',', '', trim((string) ($c[$iQty] ?? '')));
            if ($qRaw === '' || ! is_numeric($qRaw)) continue;
            $q = (float) $qRaw;

            $key = $code !== '' ? 'C:' . $code : 'N:' . $this->normName($name);
            if (! isset($agg[$key])) $agg[$key] = ['name' => $name, 'code' => $code, 'pieces' => 0.0, 'hasPieces' => false, 'all' => 0.0];
            $agg[$key]['all'] += $q;
            if (in_array($unit, $pieceUnits, true)) { $agg[$key]['pieces'] += $q; $agg[$key]['hasPieces'] = true; }
        }

        $out = [];
        foreach ($agg as $a) {
            $stock = $a['hasPieces'] ? $a['pieces'] : $a['all'];
            $out[] = ['name' => $a['name'], 'code' => $a['code'], 'stock' => (int) round($stock)];
        }
        return ['rows' => $out];
    }

    /**
     * Self-service: fill THIS store's missing product images by borrowing from the source store(s)
     * the platform operator designated in settings.image_sources. A tenant can ONLY borrow from its
     * own configured sources (enforced here) — so third-party tenants stay isolated. Matches
     * barcode → sku → name, fills empty images only (references the same public URL, no file copy),
     * never overwrites. dry-run unless apply=1.
     */
    public function imagesBorrow(Request $r)
    {
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);

        $raw = $t->setting('image_sources', []);
        $sources = is_array($raw) ? $raw : explode(',', (string) $raw);
        $sources = array_values(array_filter(array_map('intval', $sources), fn ($id) => $id > 0 && $id !== (int) $t->id));
        if (! $sources) return response()->json(['ok' => false, 'error' => 'no_source_configured'], 422);

        $apply   = (int) $r->input('apply', 0) === 1;
        $gallery = (bool) $r->input('gallery', false);

        $byBar = [];
        $bySku = [];
        $byName = [];
        $src = \App\Models\Product::whereIn('tenant_id', $sources)
            ->whereNotNull('image_url')->where('image_url', '!=', '')
            ->get(['name', 'sku', 'barcode', 'image_url', 'gallery_1', 'gallery_2', 'gallery_3']);
        foreach ($src as $sp) {
            $rec = ['image_url' => $sp->image_url, 'g1' => $sp->gallery_1, 'g2' => $sp->gallery_2, 'g3' => $sp->gallery_3];
            $bc = trim((string) $sp->barcode);
            $sk = trim((string) $sp->sku);
            $nm = $this->normName((string) $sp->name);
            if ($bc !== '' && ! isset($byBar[$bc])) $byBar[$bc] = $rec;
            if ($sk !== '' && ! isset($bySku[$sk])) $bySku[$sk] = $rec;
            if ($nm !== '' && ! isset($byName[$nm])) $byName[$nm] = $rec;
        }

        $targets = \App\Models\Product::where('tenant_id', $t->id)
            ->where(function ($w) { $w->whereNull('image_url')->orWhere('image_url', ''); })
            ->get(['id', 'name', 'sku', 'barcode']);

        $filled = $byB = $byS = $byN = $noMatch = 0;
        $ups = [];
        foreach ($targets as $tp) {
            $bc = trim((string) $tp->barcode);
            $sk = trim((string) $tp->sku);
            $rec = null; $how = '';
            if ($bc !== '' && isset($byBar[$bc]))      { $rec = $byBar[$bc]; $how = 'b'; }
            elseif ($sk !== '' && isset($bySku[$sk]))  { $rec = $bySku[$sk]; $how = 's'; }
            elseif (isset($byName[$this->normName((string) $tp->name)])) { $rec = $byName[$this->normName((string) $tp->name)]; $how = 'n'; }
            if (! $rec) { $noMatch++; continue; }

            $u = ['image_url' => $rec['image_url']];
            if ($gallery) {
                if (! empty($rec['g1'])) $u['gallery_1'] = $rec['g1'];
                if (! empty($rec['g2'])) $u['gallery_2'] = $rec['g2'];
                if (! empty($rec['g3'])) $u['gallery_3'] = $rec['g3'];
            }
            $ups[$tp->id] = $u;
            $filled++;
            if ($how === 'b') $byB++; elseif ($how === 's') $byS++; else $byN++;
        }

        if ($apply && $ups) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($ups) {
                foreach ($ups as $id => $u) {
                    \Illuminate\Support\Facades\DB::table('products')->where('id', $id)->update($u);
                }
            });
        }

        return response()->json([
            'ok'         => true,
            'apply'      => $apply,
            'imageless'  => count($targets),
            'filled'     => $filled,
            'by_barcode' => $byB,
            'by_sku'     => $byS,
            'by_name'    => $byN,
            'no_match'   => $noMatch,
        ]);
    }

    /**
     * One-click catalogue import. Upload a ZIP containing a CSV (Product Name, Brand, Category,
     * Price UGX, Stock, Keywords, Image) plus the image files. Creates products with their photos,
     * skips duplicates (same name), respects category scope. Products with no price are created
     * INACTIVE (hidden) until the owner sets a price. dry-run unless apply=1.
     */
    public function productsImport(Request $r)
    {
        if (! $r->user()->canMenu('products')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        if (! class_exists('ZipArchive')) return response()->json(['ok' => false, 'error' => 'zip_unsupported'], 500);

        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);

        $b64 = (string) $r->input('zip', '');
        if (str_contains($b64, ',')) $b64 = substr($b64, strpos($b64, ',') + 1);
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') return response()->json(['ok' => false, 'error' => 'bad_zip'], 422);

        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $bin);
        $za = new \ZipArchive();
        if ($za->open($tmp) !== true) {
            @unlink($tmp);
            // Not a ZIP — accept a plain CSV upload as a CSV-only import (images only via a URL column).
            if (stripos((string) substr($bin, 0, 4000), 'name') !== false && (str_contains($bin, ',') || str_contains($bin, ';'))) {
                return $this->productsImportCsvOnly($r, $t, (string) $bin);
            }

            return response()->json(['ok' => false, 'error' => 'bad_zip'], 422);
        }

        $csv = ''; $imgIndex = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i);
            if ($entry === false) continue;
            $bn = basename($entry);
            if ($bn === '' || str_starts_with($bn, '.')) continue;
            $ext = strtolower(pathinfo($bn, PATHINFO_EXTENSION));
            if ($ext === 'csv' && $csv === '') $csv = (string) $za->getFromIndex($i);
            elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $imgIndex[strtolower($bn)] = $i;
        }
        if ($csv === '') { $za->close(); @unlink($tmp); return response()->json(['ok' => false, 'error' => 'no_csv'], 422); }

        $apply = (int) $r->input('apply', 0) === 1;
        $replace = (int) $r->input('replace', 0) === 1;
        if ($replace) {
            if (! $r->user()->isOwnerLike()) { $za->close(); @unlink($tmp); return response()->json(['ok' => false, 'error' => 'replace_forbidden'], 403); }
            if ((string) $r->input('confirm', '') !== 'RESTART') { $za->close(); @unlink($tmp); return response()->json(['ok' => false, 'error' => 'confirm_required'], 422); }
        }
        $existing = Product::where('tenant_id', $t->id)->whereNull('archived_at')->count();
        $archived = 0;
        if ($apply && $replace) {
            $archived = Product::where('tenant_id', $t->id)->whereNull('archived_at')->update(['active' => false, 'archived_at' => now()]);
        }
        $scope = $r->user()->categoryScope();

        $lines  = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv((string) array_shift($lines)));
        $col = function ($names) use ($header) { foreach ($names as $n) { $i = array_search($n, $header, true); if ($i !== false) return $i; } return null; };
        $iName = $col(['product name', 'name']); $iCat = $col(['category']); $iPrice = $col(['price ugx', 'price usd', 'price (usd)', 'price']);
        $iStock = $col(['stock']); $iKw = $col(['keywords']); $iImg = $col(['image', 'photo']);
        // These columns are written by the export but were previously ignored on import, so a
        // round-trip (export → re-import) silently lost Cost, SKU, Unit, Pack Size and MOQ.
        $iSku = $col(['sku']); $iCost = $col(['cost', 'cost price']);
        $iUnit = $col(['unit', 'unit label']); $iPack = $col(['pack size', 'pack_size']); $iMoq = $col(['moq']);
        $iBrand = $col(['brand']);
        if ($iName === null) { $za->close(); @unlink($tmp); return response()->json(['ok' => false, 'error' => 'csv_no_name'], 422); }

        $created = $dup = $noImg = $forbidden = 0; $samples = []; $catsSeen = [];
        foreach ($lines as $ln) {
            if (trim($ln) === '') continue;
            $c = str_getcsv($ln);
            $name = trim((string) ($c[$iName] ?? '')); if ($name === '') continue;
            $cat  = $iCat !== null ? trim((string) ($c[$iCat] ?? '')) : '';
            if (is_array($scope) && ! in_array($cat, $scope, true)) { $forbidden++; continue; }
            if (! $replace && Product::where('tenant_id', $t->id)->whereNull('archived_at')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) { $dup++; continue; }

            $price = $iPrice !== null ? (float) preg_replace('/[^0-9.]/', '', (string) ($c[$iPrice] ?? '')) : 0;
            $stock = $iStock !== null ? (int) preg_replace('/[^0-9-]/', '', (string) ($c[$iStock] ?? '')) : 0;
            $kw    = $iKw !== null ? trim((string) ($c[$iKw] ?? '')) : '';
            $sku   = $iSku !== null ? trim((string) ($c[$iSku] ?? '')) : '';
            $brand = $iBrand !== null ? trim((string) ($c[$iBrand] ?? '')) : '';
            $cost  = $iCost !== null ? (float) preg_replace('/[^0-9.]/', '', (string) ($c[$iCost] ?? '')) : 0;
            $unit  = $iUnit !== null ? trim((string) ($c[$iUnit] ?? '')) : '';
            $pack  = $iPack !== null ? (int) preg_replace('/[^0-9]/', '', (string) ($c[$iPack] ?? '')) : 0;
            $moq   = $iMoq !== null ? (int) preg_replace('/[^0-9]/', '', (string) ($c[$iMoq] ?? '')) : 0;
            $imgRaw  = $iImg !== null ? trim((string) ($c[$iImg] ?? '')) : '';
            $imgName = basename(strtolower($imgRaw));
            $isUrl   = (bool) preg_match('#^https?://#i', $imgRaw);

            $url = '';
            if ($isUrl) {
                if ($apply) {
                    try {
                        $resp = \Illuminate\Support\Facades\Http::timeout(25)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($imgRaw);
                        if ($resp->successful()) {
                            $ct = strtolower((string) $resp->header('Content-Type'));
                            $body = $resp->body();
                            if (str_contains($ct, 'html')) {
                                // It's a product page — pull the og:image and fetch that.
                                if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $m)
                                    || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $body, $m)) {
                                    $r2  = \Illuminate\Support\Facades\Http::timeout(25)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get(html_entity_decode($m[1]));
                                    $ct2 = strtolower((string) $r2->header('Content-Type'));
                                    if ($r2->successful() && str_contains($ct2, 'image')) { $ct = $ct2; $body = $r2->body(); } else { $body = ''; }
                                } else { $body = ''; }
                            }
                            if ($body !== '' && str_contains($ct, 'image')) {
                                $ext = str_contains($ct, 'png') ? 'png' : (str_contains($ct, 'webp') ? 'webp' : 'jpg');
                                $path = 'products/' . $t->id . '/' . uniqid('imp_', true) . '.' . $ext;
                                Storage::disk('public')->put($path, $body);
                                $url = url(Storage::url($path));
                            }
                        }
                    } catch (\Throwable $e) {}
                    if ($url === '') $noImg++;
                }
            } elseif ($imgName !== '' && isset($imgIndex[$imgName])) {
                if ($apply) {
                    $bytes = $za->getFromIndex($imgIndex[$imgName]);
                    if ($bytes !== false) {
                        $ext  = strtolower(pathinfo($imgName, PATHINFO_EXTENSION)) ?: 'jpg';
                        $path = 'products/' . $t->id . '/' . uniqid('imp_', true) . '.' . $ext;
                        Storage::disk('public')->put($path, $bytes);
                        $url = url(Storage::url($path));
                    }
                }
            } else { $noImg++; }

            if ($apply) {
                Product::create([
                    'tenant_id'  => $t->id, 'name' => $name, 'category' => $cat, 'brand' => ($brand ?: null), 'keywords' => $kw,
                    'sku'        => ($sku ?: null), 'cost_price' => ($cost > 0 ? $cost : null),
                    'unit_label' => ($unit ?: null),
                    'pack_size'  => ($pack > 0 ? $pack : null), 'moq' => ($moq > 0 ? $moq : null),
                    'base_price' => $price, 'price' => $price, 'stock' => $stock, 'image_url' => $url,
                    'active'     => $price > 0,
                ]);
            }
            $created++;
            if ($cat !== '') $catsSeen[$cat] = 1;
            if (count($samples) < 8) $samples[] = $name;
        }
        $za->close(); @unlink($tmp);

        if ($apply && $catsSeen) {
            $sort = (int) (\App\Models\Category::where('tenant_id', $t->id)->max('sort')) + 1;
            foreach (array_keys($catsSeen) as $cn) {
                try { \App\Models\Category::firstOrCreate(['tenant_id' => $t->id, 'name' => $cn], ['active' => true, 'sort' => $sort++]); } catch (\Throwable $e) {}
            }
        }

        return response()->json([
            'ok' => true, 'apply' => $apply, 'created' => $created, 'duplicates' => $dup,
            'missing_image' => $noImg, 'forbidden_category' => $forbidden, 'samples' => $samples,
            'replace' => $replace, 'existing' => $existing,
            'archived' => ($apply && $replace) ? $archived : ($replace ? $existing : 0),
            'categories' => count($catsSeen),
            'message' => ($apply ? 'Imported ' : 'Preview: ') . $created . ' product(s)'
                . ($dup ? (', ' . $dup . ' duplicate(s) skipped') : '')
                . ($noImg ? (', ' . $noImg . ' without a photo') : '') . '.',
        ]);
    }

    /**
     * CSV-only catalogue import (no ZIP). Same columns as the ZIP import (Product Name, Category, Price,
     * Stock, Keywords, Image) plus optional SKU and Cost. Images only via a URL in the Image column
     * (local files need the ZIP import). dry-run unless apply=1. Reached from productsImport when the
     * uploaded file is a CSV rather than a ZIP.
     */
    private function productsImportCsvOnly(Request $r, $t, string $csv)
    {
        $apply = (int) $r->input('apply', 0) === 1;
        $replace = (int) $r->input('replace', 0) === 1;
        if ($replace) {
            if (! $r->user()->isOwnerLike()) return response()->json(['ok' => false, 'error' => 'replace_forbidden'], 403);
            if ((string) $r->input('confirm', '') !== 'RESTART') return response()->json(['ok' => false, 'error' => 'confirm_required'], 422);
        }
        $existing = Product::where('tenant_id', $t->id)->whereNull('archived_at')->count();
        $archived = 0;
        if ($apply && $replace) {
            $archived = Product::where('tenant_id', $t->id)->whereNull('archived_at')->update(['active' => false, 'archived_at' => now()]);
        }
        $scope = $r->user()->categoryScope();

        $lines  = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv((string) array_shift($lines)));
        $col = function ($names) use ($header) { foreach ($names as $n) { $i = array_search($n, $header, true); if ($i !== false) return $i; } return null; };
        $iName = $col(['product name', 'name']); $iCat = $col(['category']); $iPrice = $col(['price ugx', 'price usd', 'price (usd)', 'price']);
        $iStock = $col(['stock']); $iKw = $col(['keywords']); $iImg = $col(['image', 'photo']);
        $iSku = $col(['sku']); $iCost = $col(['cost', 'cost price']);
        $iUnit = $col(['unit', 'unit label']); $iPack = $col(['pack size', 'pack_size']); $iMoq = $col(['moq']);
        $iBrand = $col(['brand']);
        if ($iName === null) return response()->json(['ok' => false, 'error' => 'csv_no_name'], 422);

        $created = $dup = $noImg = $forbidden = 0; $samples = []; $catsSeen = [];
        foreach ($lines as $ln) {
            if (trim($ln) === '') continue;
            $c = str_getcsv($ln);
            $name = trim((string) ($c[$iName] ?? '')); if ($name === '') continue;
            $cat  = $iCat !== null ? trim((string) ($c[$iCat] ?? '')) : '';
            if (is_array($scope) && ! in_array($cat, $scope, true)) { $forbidden++; continue; }
            if (! $replace && Product::where('tenant_id', $t->id)->whereNull('archived_at')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) { $dup++; continue; }

            $price = $iPrice !== null ? (float) preg_replace('/[^0-9.]/', '', (string) ($c[$iPrice] ?? '')) : 0;
            $cost  = $iCost !== null ? (float) preg_replace('/[^0-9.]/', '', (string) ($c[$iCost] ?? '')) : 0;
            $stock = $iStock !== null ? (int) preg_replace('/[^0-9-]/', '', (string) ($c[$iStock] ?? '')) : 0;
            $kw    = $iKw !== null ? trim((string) ($c[$iKw] ?? '')) : '';
            $sku   = $iSku !== null ? trim((string) ($c[$iSku] ?? '')) : '';
            $brand = $iBrand !== null ? trim((string) ($c[$iBrand] ?? '')) : '';
            $unit  = $iUnit !== null ? trim((string) ($c[$iUnit] ?? '')) : '';
            $pack  = $iPack !== null ? (int) preg_replace('/[^0-9]/', '', (string) ($c[$iPack] ?? '')) : 0;
            $moq   = $iMoq !== null ? (int) preg_replace('/[^0-9]/', '', (string) ($c[$iMoq] ?? '')) : 0;
            $imgRaw = $iImg !== null ? trim((string) ($c[$iImg] ?? '')) : '';
            $isUrl  = (bool) preg_match('#^https?://#i', $imgRaw);

            $url = '';
            if ($isUrl && $apply) {
                try {
                    $resp = \Illuminate\Support\Facades\Http::timeout(25)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($imgRaw);
                    if ($resp->successful()) {
                        $ct = strtolower((string) $resp->header('Content-Type')); $body = $resp->body();
                        if ($body !== '' && str_contains($ct, 'image')) {
                            $ext = str_contains($ct, 'png') ? 'png' : (str_contains($ct, 'webp') ? 'webp' : 'jpg');
                            $path = 'products/' . $t->id . '/' . uniqid('imp_', true) . '.' . $ext;
                            Storage::disk('public')->put($path, $body); $url = url(Storage::url($path));
                        }
                    }
                } catch (\Throwable $e) {}
                if ($url === '') $noImg++;
            } elseif ($imgRaw !== '') { $noImg++; }

            if ($apply) {
                Product::create([
                    'tenant_id' => $t->id, 'name' => $name, 'category' => $cat, 'brand' => ($brand ?: null), 'keywords' => $kw, 'sku' => ($sku ?: null),
                    'unit_label' => ($unit ?: null), 'pack_size' => ($pack > 0 ? $pack : null), 'moq' => ($moq > 0 ? $moq : null),
                    'base_price' => $price, 'price' => $price, 'cost_price' => ($cost > 0 ? $cost : null),
                    'stock' => $stock, 'image_url' => $url, 'active' => $price > 0,
                ]);
            }
            $created++;
            if ($cat !== '') $catsSeen[$cat] = 1;
            if (count($samples) < 8) $samples[] = $name;
        }

        if ($apply && $catsSeen) {
            $sort = (int) (\App\Models\Category::where('tenant_id', $t->id)->max('sort')) + 1;
            foreach (array_keys($catsSeen) as $cn) {
                try { \App\Models\Category::firstOrCreate(['tenant_id' => $t->id, 'name' => $cn], ['active' => true, 'sort' => $sort++]); } catch (\Throwable $e) {}
            }
        }

        return response()->json([
            'ok' => true, 'apply' => $apply, 'created' => $created, 'duplicates' => $dup,
            'missing_image' => $noImg, 'forbidden_category' => $forbidden, 'samples' => $samples,
            'replace' => $replace, 'existing' => $existing,
            'archived' => ($apply && $replace) ? $archived : ($replace ? $existing : 0),
            'categories' => count($catsSeen), 'mode' => 'csv',
            'message' => ($apply ? 'Imported ' : 'Preview: ') . $created . ' product(s) from CSV'
                . ($dup ? (', ' . $dup . ' duplicate(s) skipped') : '') . '.',
        ]);
    }

    /** Bring back products hidden by a Restart. Owner-only. Clears archived_at and re-activates them. */
    public function productsRestore(Request $r)
    {
        if (! $r->user()->isOwnerLike()) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);
        $restored = Product::where('tenant_id', $t->id)->whereNotNull('archived_at')->update(['archived_at' => null, 'active' => true]);
        return response()->json(['ok' => true, 'restored' => $restored]);
    }

    /**
     * Bulk update photos for EXISTING products. Upload a ZIP of images (multipart 'zip'); each image
     * is matched to a product by SKU → barcode → normalised name taken from the FILENAME. Replaces the
     * product's image. By default only products WITHOUT a photo are filled; pass override=1 to replace
     * existing photos too. Respects category scope. dry-run unless apply=1.
     */
    public function photosImport(Request $r)
    {
        if (! $r->user()->canMenu('products')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        if (! class_exists('ZipArchive')) return response()->json(['ok' => false, 'error' => 'zip_unsupported'], 500);

        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);

        $file = $r->file('zip');
        if (! $file || ! $file->isValid()) return response()->json(['ok' => false, 'error' => 'no_file'], 422);
        $za = new \ZipArchive();
        if ($za->open($file->getRealPath()) !== true) return response()->json(['ok' => false, 'error' => 'bad_zip'], 422);

        $apply    = (int) $r->input('apply', 0) === 1;
        $override = (int) $r->input('override', 0) === 1;
        $scope    = $r->user()->categoryScope();

        $q = Product::query()->where('tenant_id', $t->id);
        if (is_array($scope)) $q->whereIn('category', $scope);
        $bySku = []; $byBar = []; $byName = [];
        foreach ($q->get(['id', 'name', 'sku', 'barcode', 'image_url']) as $p) {
            $byName[$this->normName((string) $p->name)] = $p;
            if (trim((string) $p->sku) !== '')     $bySku[trim((string) $p->sku)] = $p;
            if (trim((string) $p->barcode) !== '') $byBar[trim((string) $p->barcode)] = $p;
        }

        $images = $matched = $withPhoto = $withoutPhoto = $updated = $unmatched = 0;
        $unmatchedSamples = []; $seen = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i); if ($entry === false) continue;
            $bn = basename($entry); if ($bn === '' || str_starts_with($bn, '.')) continue;
            $ext = strtolower(pathinfo($bn, PATHINFO_EXTENSION));
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
            $images++;

            $stem = preg_replace('/\.(jpg|jpeg|png|webp)$/i', '', $bn);
            $stem = preg_replace('/^imgi_\d+_/', '', $stem);
            $stem = preg_replace('/-\d+x\d+$/', '', $stem);
            $raw  = trim($stem);
            $p = $bySku[$raw] ?? $byBar[$raw] ?? ($byName[$this->normName($stem)] ?? null);
            if (! $p) { $unmatched++; if (count($unmatchedSamples) < 12) $unmatchedSamples[] = $bn; continue; }
            if (isset($seen[$p->id])) continue;
            $seen[$p->id] = 1; $matched++;

            $hasImg = trim((string) $p->image_url) !== '';
            $hasImg ? $withPhoto++ : $withoutPhoto++;
            $willUpdate = ! $hasImg || $override;
            if (! $willUpdate) continue;

            if ($apply) {
                $bytes = $za->getFromIndex($i);
                if ($bytes !== false) {
                    $path = 'products/' . $t->id . '/' . uniqid('ph_', true) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
                    Storage::disk('public')->put($path, $bytes);
                    Product::where('id', $p->id)->update(['image_url' => url(Storage::url($path))]);
                    $updated++;
                }
            } else { $updated++; }
        }
        $za->close();

        return response()->json([
            'ok' => true, 'apply' => $apply, 'override' => $override,
            'images_in_zip' => $images, 'matched' => $matched,
            'with_photo' => $withPhoto, 'without_photo' => $withoutPhoto,
            'updated' => $updated, 'unmatched' => $unmatched, 'unmatched_samples' => $unmatchedSamples,
        ]);
    }

    public function deleteProduct(Request $r)
    {
        $p = Product::find((int) $r->query('row'));
        if (! $p) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        $p->delete();
        return response()->json(['ok' => true]);
    }

    public function addProduct(Request $r)
    {
        if (! $r->user()->canMenu('products')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $name = trim((string) $r->query('name', ''));
        $price = (float) $r->query('price', 0);
        if ($name === '' || $price <= 0) {
            return response()->json(['ok' => false, 'error' => 'name_and_price_required'], 422);
        }
        $category = (string) $r->query('category', '');
        $scope = $r->user()->categoryScope();
        if (is_array($scope) && ! in_array(trim($category), $scope, true)) {
            return response()->json(['ok' => false, 'error' => 'forbidden_category', 'detail' => 'Pick one of your assigned categories.'], 403);
        }
        $variant = trim((string) $r->query('variant', ''));
        $full = $variant !== '' ? $name.' '.$variant : $name;

        Product::create([
            'name'       => $full,
            'category'   => $category,
            'keywords'   => (string) $r->query('keywords', ''),
            'base_price' => $price,
            'price'      => $price,
            'cost_price' => $r->filled('cost') ? (float) $r->query('cost') : null,
            'stock'      => (int) $r->query('stock', 0),
            'image_url'  => trim((string) $r->query('image', '')),
            'moq'        => $r->filled('moq') ? max(1, (int) $r->query('moq')) : null,
            'pack_size'  => $r->filled('pack_size') ? max(1, (int) $r->query('pack_size')) : null,
            'unit_label' => trim((string) $r->query('unit_label', '')) ?: null,
            'active'     => true,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * AI product recogniser. Given a product photo (uploaded URL or base64 data-URI), asks the
     * vision model to draft the catalogue fields so staff just confirm. Reuses the same OpenAI key
     * the bot uses. Returns drafted name/brand/category/pack/keywords/description.
     */
    public function aiIdentify(Request $r)
    {
        if (! $r->user()->canMenu('products')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);

        $src = trim((string) $r->input('image', '')) ?: (string) $r->input('data', '');
        if ($src === '') return response()->json(['ok' => false, 'error' => 'no_image'], 422);

        $key = (string) (config('openai.api_key') ?: env('OPENAI_API_KEY'));
        if ($key === '') return response()->json(['ok' => false, 'error' => 'ai_unavailable'], 503);
        $model = (string) env('OPENAI_VISION_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini'));

        $prompt = 'You are cataloguing stock for an East-African grocery shop. Identify the product in the image. '
            . 'Return ONLY a JSON object, no markdown, with keys: name (concise sellable name incl. size, e.g. "Coca-Cola 500ml"), '
            . 'brand, category (one of: Beverages & Drinks, Snacks, Home & Cleaning, Baby Care, Personal Care, Groceries, Dairy, Bakery, Stationery, Other), '
            . 'pack (e.g. "500ml PET bottle"), keywords (comma-separated search terms shoppers might type), description (one short sentence). Best guess if unsure.';

        try {
            $resp = \Illuminate\Support\Facades\Http::withToken($key)->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model'       => $model,
                'temperature' => 0,
                'max_tokens'  => 400,
                'messages'    => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $src]],
                    ],
                ]],
            ]);
            if (! $resp->successful()) return response()->json(['ok' => false, 'error' => 'ai_failed'], 502);

            $out = trim((string) $resp->json('choices.0.message.content'));
            $out = trim(preg_replace('/```json|```/', '', $out));
            $obj = json_decode($out, true);
            if (! is_array($obj)) return response()->json(['ok' => false, 'error' => 'parse_failed'], 502);

            $kw = $obj['keywords'] ?? '';
            return response()->json([
                'ok'          => true,
                'name'        => (string) ($obj['name'] ?? ''),
                'brand'       => (string) ($obj['brand'] ?? ''),
                'category'    => (string) ($obj['category'] ?? ''),
                'pack'        => (string) ($obj['pack'] ?? ''),
                'keywords'    => is_array($kw) ? implode(', ', $kw) : (string) $kw,
                'description' => (string) ($obj['description'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('aiIdentify failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'ai_error'], 500);
        }
    }

    public function uploadImage(Request $r)
    {
        $data = (string) $r->input('data', '');
        $name = (string) $r->input('name', 'upload');
        if ($data === '') {
            return response()->json(['ok' => false, 'error' => 'no_data'], 422);
        }
        // strip optional data-URI prefix
        if (str_contains($data, ',')) {
            $data = substr($data, strpos($data, ',') + 1);
        }
        $bin = base64_decode($data, true);
        if ($bin === false) {
            return response()->json(['ok' => false, 'error' => 'bad_base64'], 422);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'jpg';
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
        $tenant = $r->user()->tenant_id ?: 0;
        $file = 'products/'.$tenant.'/'.uniqid('p_', true).'.'.$ext;
        Storage::disk('public')->put($file, $bin);

        // Absolute URL (WhatsApp/Evolution requires a full https URL, not a /storage path).
        return response()->json(['ok' => true, 'url' => url(Storage::url($file))]);
    }

    /**
     * Upload a document (catalogue / price-list) the bot can send — PDF or image. Mirrors
     * uploadImage but allows .pdf and stores under catalog/. Returns a public URL the WhatsApp
     * gateway can fetch. 15 MB cap (WhatsApp document limit is well above this; keep it sane).
     */
    public function uploadDoc(Request $r)
    {
        $data = (string) $r->input('data', '');
        $name = (string) $r->input('name', 'catalogue');
        if ($data === '') {
            return response()->json(['ok' => false, 'error' => 'no_data'], 422);
        }
        if (str_contains($data, ',')) {
            $data = substr($data, strpos($data, ',') + 1);
        }
        $bin = base64_decode($data, true);
        if ($bin === false) {
            return response()->json(['ok' => false, 'error' => 'bad_base64'], 422);
        }
        if (strlen($bin) > 15 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'too_large'], 422);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'pdf';
        if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            return response()->json(['ok' => false, 'error' => 'bad_type'], 422);
        }
        $tenant = $r->user()->tenant_id ?: 0;
        $file = 'catalog/'.$tenant.'/'.uniqid('cat_', true).'.'.$ext;
        Storage::disk('public')->put($file, $bin);

        // Absolute URL (WhatsApp/Evolution requires a full https URL, not a /storage path).
        return response()->json(['ok' => true, 'url' => url(Storage::url($file))]);
    }

    /** Category tile photos: a { "Category Name": "image url" } map in tenant settings. */
    public function categoryImages(Request $r)
    {
        $t   = $r->user()->tenant;
        $map = $t->setting('category_images', []);
        if (! is_array($map)) $map = [];
        return response()->json([
            'ok'     => true,
            'images' => (object) $map,
            'extra'  => $this->catExtra($t),   // empty categories (no products yet)
            'order'  => $this->catOrder($t),   // saved display order
        ]);
    }

    public function categoryImageSave(Request $r)
    {
        $t   = $r->user()->tenant;
        $cat = trim((string) $r->input('category', ''));
        if ($cat === '') return response()->json(['ok' => false, 'error' => 'no_category'], 422);

        $url = trim((string) $r->input('url', ''));
        $map = $t->setting('category_images', []);
        if (! is_array($map)) $map = [];
        if ($url === '') unset($map[$cat]); else $map[$cat] = $url;
        $t->putSetting('category_images', $map);

        return response()->json(['ok' => true]);
    }

    /** Read the Business-Type banners config (Shop by Business merchandising). */
    public function bannersGet(Request $r)
    {
        $t = $r->user()->tenant;
        $cfg = $t->setting('business_banners', []);
        if (! is_array($cfg)) $cfg = [];
        return response()->json(['ok' => true, 'config' => (object) $cfg]);
    }

    /**
     * Save the Business-Type banners config. Whitelists & size-caps every field so the storefront
     * only ever reads a well-formed structure. Photos are already-uploaded URLs (via /papi/upload-image)
     * — this endpoint stores the URL only, it does not handle file bytes.
     */
    public function bannersSave(Request $r)
    {
        $t   = $r->user()->tenant;
        $in  = $r->input('config', []);
        if (is_string($in)) { $in = json_decode($in, true); }
        if (! is_array($in)) $in = [];

        $str  = fn ($v, $max = 200) => mb_substr(trim((string) ($v ?? '')), 0, $max);
        $arrS = function ($v, $maxItems, $maxLen) use ($str) {
            if (! is_array($v)) return [];
            return array_values(array_filter(array_map(fn ($x) => $str($x, $maxLen), array_slice($v, 0, $maxItems)), fn ($x) => $x !== ''));
        };

        $businesses = [];
        foreach (array_slice((array) ($in['businesses'] ?? []), 0, 12) as $b) {
            if (! is_array($b)) continue;
            $slides = [];
            foreach (array_slice((array) ($b['slides'] ?? []), 0, 5) as $s) {
                if (! is_array($s)) continue;
                $slides[] = [
                    'kick'      => $str($s['kick'] ?? '', 40),
                    'title'     => $str($s['title'] ?? '', 160),
                    'points'    => $arrS($s['points'] ?? [], 5, 80),
                    'cta_label' => $str($s['cta_label'] ?? '', 40),
                    'cta'       => $str($s['cta'] ?? '', 120),
                    'gold'      => (bool) ($s['gold'] ?? false),
                    'image_url' => $str($s['image_url'] ?? '', 500),
                    'theme'     => $str($s['theme'] ?? '', 80),
                ];
            }
            $collections = [];
            foreach (array_slice((array) ($b['collections'] ?? []), 0, 10) as $c) {
                if (! is_array($c)) continue;
                $collections[] = [
                    'label'     => $str($c['label'] ?? '', 60),
                    'cat'       => $str($c['cat'] ?? '', 80),
                    'keywords'  => $str($c['keywords'] ?? '', 160),
                    'icon'      => $str($c['icon'] ?? '', 20),
                    'image_url' => $str($c['image_url'] ?? '', 500),
                ];
            }
            $best = $b['bestsellers'] ?? 'auto';
            $best = is_array($best) ? $arrS($best, 12, 120) : $str($best, 20);

            $businesses[] = [
                'id'          => $str($b['id'] ?? '', 40) ?: \Illuminate\Support\Str::slug($str($b['label'] ?? 'biz', 40)),
                'label'       => $str($b['label'] ?? '', 40),
                'sub'         => $str($b['sub'] ?? '', 80),
                'icon'        => $str($b['icon'] ?? '', 20),
                'active'      => (bool) ($b['active'] ?? true),
                'card_image'  => $str($b['card_image'] ?? '', 500),
                'match'       => [
                    'categories' => $arrS(($b['match']['categories'] ?? []), 40, 80),
                    'keywords'   => $arrS(($b['match']['keywords'] ?? []), 60, 60),
                    'bulk'       => (bool) ($b['match']['bulk'] ?? false),
                ],
                'slides'      => $slides,
                'collections' => $collections,
                'bestsellers' => $best,
            ];
        }

        $cfg = [
            'enabled'    => (bool) ($in['enabled'] ?? false),
            'title'      => $str($in['title'] ?? '', 60),
            'autoscroll' => max(0, min(30, (int) ($in['autoscroll'] ?? 5))),
            'default'    => $str($in['default'] ?? '', 40),
            // Curated "Current promotions" homepage rail — product names matched on the storefront.
            'promotions' => $arrS(($in['promotions'] ?? []), 40, 200),
            'businesses' => $businesses,
        ];

        $t->putSetting('business_banners', $cfg);
        $this->catFlush($t);
        return response()->json(['ok' => true, 'config' => (object) $cfg]);
    }

    /** Read the Ready-made Kits config (curated bundles → one-tap quotation). */
    public function kitsGet(Request $r)
    {
        $t = $r->user()->tenant;
        $kits = $t->setting('kits', []);
        if (! is_array($kits)) $kits = [];
        return response()->json(['ok' => true, 'kits' => array_values($kits)]);
    }

    /** Save Kits. Whitelists & size-caps every field. Line products are matched by name on the storefront. */
    public function kitsSave(Request $r)
    {
        $t  = $r->user()->tenant;
        $in = $r->input('kits', []);
        if (is_string($in)) { $in = json_decode($in, true); }
        if (! is_array($in)) $in = [];

        $str = fn ($v, $max = 200) => mb_substr(trim((string) ($v ?? '')), 0, $max);
        $kits = [];
        foreach (array_slice($in, 0, 40) as $k) {
            if (! is_array($k)) continue;
            $lines = [];
            foreach (array_slice((array) ($k['lines'] ?? []), 0, 60) as $ln) {
                if (! is_array($ln)) continue;
                $name = $str($ln['name'] ?? '', 200);
                if ($name === '') continue;
                $lines[] = ['name' => $name, 'qty' => max(1, min(100000, (int) ($ln['qty'] ?? 1)))];
            }
            $kits[] = [
                'id'         => $str($k['id'] ?? '', 40) ?: \Illuminate\Support\Str::slug($str($k['label'] ?? 'kit', 40)) . substr(md5($str($k['label'] ?? '') . count($kits)), 0, 4),
                'label'      => $str($k['label'] ?? '', 80),
                'desc'       => $str($k['desc'] ?? '', 200),
                'image_url'  => $str($k['image_url'] ?? '', 500),
                'active'     => (bool) ($k['active'] ?? true),
                'collection' => $str($k['collection'] ?? '', 40),
                'cta_label'  => $str($k['cta_label'] ?? '', 40),
                'lines'      => $lines,
            ];
        }
        $t->putSetting('kits', $kits);
        $this->catFlush($t);
        return response()->json(['ok' => true, 'kits' => $kits]);
    }

    /**
     * Set the sales currency basis (owner only).
     *   native    = the base currency (e.g. USD) IS the operating currency — prices & payments are USD.
     *   converted = base is only for reporting; the local currency (UGX) is the operating one.
     * Fixes the "payment booked in UGX on a USD business" problem: with native basis every untagged
     * order + payment defaults to the base currency, so existing orders like ORD-47 resolve to USD too.
     */
    public function currencyBasisSave(Request $r)
    {
        if (! $r->user()->isOwnerLike()) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t     = $r->user()->tenant;
        $basis = strtolower(trim((string) $r->input('basis', '')));
        if (! in_array($basis, ['native', 'converted'], true)) {
            return response()->json(['ok' => false, 'error' => 'bad_basis', 'detail' => "basis must be 'native' or 'converted'"], 422);
        }
        $t->putSetting('currency_basis', $basis);
        $base = \App\Services\Money\SalesBaseCurrency::baseCurrency($t);
        $local = \App\Services\Money\SalesBaseCurrency::nativeCurrency($t);
        return response()->json(['ok' => true, 'currency_basis' => $basis, 'base_currency' => $base, 'operating_currency' => $local]);
    }

    /** Read the current currency configuration (for confirming the fix). */
    public function currencyBasisGet(Request $r)
    {
        $t = $r->user()->tenant;
        return response()->json([
            'ok' => true,
            'currency_basis'     => (string) ($t->setting('currency_basis', '') ?: '(default: converted)'),
            'base_currency'      => \App\Services\Money\SalesBaseCurrency::baseCurrency($t),
            'operating_currency' => \App\Services\Money\SalesBaseCurrency::nativeCurrency($t),
        ]);
    }

    /** Read the delivery config (branches + per-city free-delivery rules) for the v3 editor. */
    public function deliveryGet(Request $r)
    {
        $t = $r->user()->tenant;
        $d = $t->setting('delivery', []);
        if (! is_array($d)) $d = [];
        $rules = array_values(array_filter((array) ($d['free_rules'] ?? []), 'is_array'));
        $branches = array_values(array_map(
            fn ($b) => (string) ($b['name'] ?? ''),
            array_filter((array) $t->setting('branches', []), 'is_array')
        ));
        return response()->json([
            'ok'         => true,
            'free_rules' => $rules,
            'currency'   => (string) ($t->setting('currency', 'USD') ?: 'USD'),
            'branches'   => $branches,
        ]);
    }

    /** Save per-city free-delivery rules into settings.delivery.free_rules (other delivery fields preserved). */
    public function deliverySave(Request $r)
    {
        $t  = $r->user()->tenant;
        $in = $r->input('free_rules', []);
        if (is_string($in)) { $in = json_decode($in, true); }
        if (! is_array($in)) $in = [];

        $rules = [];
        foreach (array_slice($in, 0, 30) as $row) {
            if (! is_array($row)) continue;
            $city = mb_substr(trim((string) ($row['city'] ?? '')), 0, 80);
            $over = round((float) ($row['free_over'] ?? 0), 2);
            if ($city === '' || $over <= 0) continue;
            $rules[] = ['city' => $city, 'free_over' => $over];
        }

        $d = $t->setting('delivery', []);
        if (! is_array($d)) $d = [];
        $d['free_rules'] = $rules;             // merge — preserves near/mid/far/out & km bands
        $t->putSetting('delivery', $d);
        $this->catFlush($t);
        return response()->json(['ok' => true, 'free_rules' => $rules]);
    }

    /** Create an (initially empty) category so it shows as a tile before any product uses it. */
    public function categoryCreate(Request $r)
    {
        $t    = $r->user()->tenant;
        $name = trim((string) $r->input('name', ''));
        if ($name === '')                          return response()->json(['ok' => false, 'error' => 'no_name'], 422);
        if (mb_strlen($name) > 60)                 return response()->json(['ok' => false, 'error' => 'too_long'], 422);
        if (strcasecmp($name, 'Uncategorised') === 0) return response()->json(['ok' => false, 'error' => 'reserved'], 422);

        if ($this->catExists($t, $name)) return response()->json(['ok' => false, 'error' => 'exists'], 409);

        $extra   = $this->catExtra($t);
        $extra[] = $name;
        $t->putSetting('category_extra', array_values($extra));
        $this->catFlush($t);
        return response()->json(['ok' => true]);
    }

    /** Rename a category everywhere: products + Category row + photo key + extra + order. */
    public function categoryRename(Request $r)
    {
        $t   = $r->user()->tenant;
        $old = trim((string) $r->input('old', ''));
        $new = trim((string) $r->input('new', ''));
        if ($old === '' || $new === '')  return response()->json(['ok' => false, 'error' => 'missing'], 422);
        if (mb_strlen($new) > 60)        return response()->json(['ok' => false, 'error' => 'too_long'], 422);
        if (strcasecmp($new, 'Uncategorised') === 0) return response()->json(['ok' => false, 'error' => 'reserved'], 422);
        if (strcasecmp($old, $new) === 0) return response()->json(['ok' => true, 'moved' => 0]);

        // new name must not collide with a different existing category
        if ($this->catExists($t, $new)) return response()->json(['ok' => false, 'error' => 'exists'], 409);

        $moved = Product::where('category', $old)->update(['category' => $new]);

        try { \App\Models\Category::where('name', $old)->update(['name' => $new]); } catch (\Throwable $e) {}

        $img = $t->setting('category_images', []); if (! is_array($img)) $img = [];
        if (isset($img[$old])) { $img[$new] = $img[$old]; unset($img[$old]); $t->putSetting('category_images', $img); }

        $extra = array_map(fn ($e) => strcasecmp($e, $old) === 0 ? $new : $e, $this->catExtra($t));
        $t->putSetting('category_extra', array_values(array_unique($extra)));

        $order = array_map(fn ($e) => strcasecmp($e, $old) === 0 ? $new : $e, $this->catOrder($t));
        $t->putSetting('category_order', array_values($order));

        $this->catFlush($t);
        return response()->json(['ok' => true, 'moved' => (int) $moved]);
    }

    /** Delete a category — only when empty. Products must be moved away first. */
    public function categoryDelete(Request $r)
    {
        $t    = $r->user()->tenant;
        $name = trim((string) $r->input('name', ''));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'no_name'], 422);

        $count = Product::where('category', $name)->count();
        if ($count > 0) return response()->json(['ok' => false, 'error' => 'has_products', 'count' => (int) $count], 409);

        $t->putSetting('category_extra', array_values(array_filter(
            $this->catExtra($t), fn ($e) => strcasecmp($e, $name) !== 0
        )));

        $img = $t->setting('category_images', []); if (! is_array($img)) $img = [];
        if (isset($img[$name])) { unset($img[$name]); $t->putSetting('category_images', $img); }

        $t->putSetting('category_order', array_values(array_filter(
            $this->catOrder($t), fn ($e) => strcasecmp($e, $name) !== 0
        )));

        // Remove the metadata row too (only reached when the category has no products) so it does
        // not linger in the storefront's category order.
        try { \App\Models\Category::where('tenant_id', $t->id)->where('name', $name)->delete(); } catch (\Throwable $e) {}

        $this->catFlush($t);
        return response()->json(['ok' => true]);
    }

    /** Persist the display order of categories. */
    public function categoryReorder(Request $r)
    {
        $t     = $r->user()->tenant;
        $order = $r->input('order', []);
        if (! is_array($order)) $order = [];
        $order = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $order), fn ($x) => $x !== ''));
        $t->putSetting('category_order', $order);
        // Mirror the order into categories.sort — the column the storefront actually reads. Without
        // this the arrow reorder saved only to a setting the storefront ignores.
        $this->writeCategorySort($t, $order);
        $this->catFlush($t);
        return response()->json(['ok' => true]);
    }

    /**
     * Write an ordered list of category names into categories.sort (0-based). Listed categories take
     * their position; any category not named keeps its relative order but is pushed below the listed
     * ones. Missing rows are created so empty categories can still be ordered. Tenant-scoped, fail-soft.
     */
    private function writeCategorySort($t, array $order): void
    {
        try {
            $pos = [];
            foreach (array_values($order) as $i => $name) {
                $key = mb_strtolower(trim((string) $name));
                if ($key === '' || isset($pos[$key])) continue;
                $pos[$key] = count($pos);
            }

            $existing = [];
            foreach (\App\Models\Category::where('tenant_id', $t->id)->get() as $c) {
                $existing[mb_strtolower(trim((string) $c->name))] = true;
            }
            foreach ($order as $name) {
                $name = trim((string) $name);
                $key  = mb_strtolower($name);
                if ($key !== '' && ! isset($existing[$key])) {
                    \App\Models\Category::firstOrCreate(
                        ['tenant_id' => $t->id, 'name' => $name],
                        ['active' => true, 'sort' => 0]
                    );
                    $existing[$key] = true;
                }
            }

            $next = count($pos);
            foreach (\App\Models\Category::where('tenant_id', $t->id)->get() as $c) {
                $key  = mb_strtolower(trim((string) $c->name));
                $sort = $pos[$key] ?? $next++;
                if ((int) $c->sort !== $sort) {
                    $c->sort = $sort;
                    $c->save();
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** Set display order for one or more products (pin to top / push to bottom / reset). */
    public function productOrder(Request $r)
    {
        $items = $r->input('items', []);
        if (! is_array($items) || ! $items) return response()->json(['ok' => false, 'error' => 'no_items'], 422);

        $n = 0;
        foreach ($items as $it) {
            if (! is_array($it)) continue;
            $id = (int) ($it['row'] ?? $it['id'] ?? 0);
            if ($id <= 0) continue;
            $p = Product::find($id);              // tenant-scoped by the global scope
            if (! $p) continue;
            $p->display_order = (int) ($it['order'] ?? 0);
            $p->save();
            $n++;
        }

        $this->catFlush($r->user()->tenant);
        return response()->json(['ok' => true, 'updated' => $n]);
    }

    /** Merchant analytics dashboard payload (cards, charts, tables). */
    public function analytics(Request $r)
    {
        $tid = (int) ($r->user()->tenant->id ?? 0);
        $data = app(\App\Services\Analytics\DashboardAnalytics::class)->payload($tid, $r->boolean('refresh'));
        return response()->json(['ok' => true, 'data' => $data]);
    }

    /** CSV export for one dashboard table (?report=most_ordered|most_viewed|combos|...). */
    public function analyticsCsv(Request $r)
    {
        $tid    = (int) ($r->user()->tenant->id ?? 0);
        $report = preg_replace('/[^a-z_]/', '', (string) $r->query('report', 'cards'));
        $rows   = app(\App\Services\Analytics\DashboardAnalytics::class)->csvRows($tid, $report);

        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) fputcsv($fh, $row);
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $report . '.csv"',
        ]);
    }

    /** True if a category name is already in use (by a product or as an empty extra). */
    private function catExists($t, string $name): bool
    {
        if (Product::whereRaw('LOWER(TRIM(category)) = ?', [mb_strtolower(trim($name))])->exists()) return true;
        foreach ($this->catExtra($t) as $e) if (strcasecmp($e, $name) === 0) return true;
        return false;
    }

    private function catExtra($t): array { $x = $t->setting('category_extra', []); return is_array($x) ? array_values($x) : []; }
    private function catOrder($t): array { $x = $t->setting('category_order', []); return is_array($x) ? array_values($x) : []; }
    private function catFlush($t): void { try { \Illuminate\Support\Facades\Cache::forget('catalogue:' . $t->id); } catch (\Throwable $e) {} }

    /** Daily set-meal (thali) config for the editor. */
    /** Resolve a stored image path to a usable URL (mirrors StorefrontController). */
    private function imageUrl(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') return '';
        // Already absolute (http/https) or root-relative (/storage/..) — use as-is.
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
            return $value;
        }
        return Storage::disk('public')->url($value);
    }

    public function thaliGet(Request $r)
    {
        $cfg = $r->user()->tenant->setting('thali', []);
        if (! is_array($cfg)) $cfg = [];
        $days = is_array($cfg['days'] ?? null) ? $cfg['days'] : [];
        $imgs = is_array($cfg['images'] ?? null) ? $cfg['images'] : [];
        $ndays = is_array($cfg['night_days'] ?? null) ? $cfg['night_days'] : [];
        $out  = [];
        $outImg = [];
        $outNight = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $items  = is_array($days[$d] ?? null) ? $days[$d] : [];
            $out[$d] = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $items), fn ($x) => $x !== ''));
            $outImg[$d] = $this->imageUrl((string) ($imgs[$d] ?? ''));
            $nitems = is_array($ndays[$d] ?? null) ? $ndays[$d] : [];
            $outNight[$d] = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $nitems), fn ($x) => $x !== ''));
        }
        return response()->json(['ok' => true, 'thali' => [
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'price'   => (int) ($cfg['price'] ?? 0),
            'note'    => (string) ($cfg['note'] ?? ''),
            'days'    => (object) $out,
            'images'  => (object) $outImg,
            'night_enabled' => (bool) ($cfg['night_enabled'] ?? false),
            'night_price'   => (int) ($cfg['night_price'] ?? 0),
            'night_note'    => (string) ($cfg['night_note'] ?? ''),
            'night_days'    => (object) $outNight,
            'switch_hour'   => (int) ($cfg['switch_hour'] ?? 16),
            'nextday_enabled' => (bool) ($cfg['nextday_enabled'] ?? false),
            'nextday_hour'    => (int) ($cfg['nextday_hour'] ?? 21),
        ]]);
    }

    public function thaliSave(Request $r)
    {
        $t    = $r->user()->tenant;
        $days = (array) $r->input('days', []);
        $imgsIn = (array) $r->input('images', []);
        $ndaysIn = (array) $r->input('night_days', []);
        $clean = [];
        $cleanImg = [];
        $cleanNight = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $items = $days[$d] ?? [];
            if (is_string($items)) $items = preg_split('/\r\n|\r|\n/', $items);
            $items = array_map(fn ($x) => trim((string) $x), (array) $items);
            $clean[$d] = array_values(array_filter($items, fn ($x) => $x !== ''));
            $cleanImg[$d] = trim((string) ($imgsIn[$d] ?? ''));

            $nitems = $ndaysIn[$d] ?? [];
            if (is_string($nitems)) $nitems = preg_split('/\r\n|\r|\n/', $nitems);
            $nitems = array_map(fn ($x) => trim((string) $x), (array) $nitems);
            $cleanNight[$d] = array_values(array_filter($nitems, fn ($x) => $x !== ''));
        }
        $sw = (int) $r->input('switch_hour', 16);
        if ($sw < 0 || $sw > 23) $sw = 16;
        $ndh = (int) $r->input('nextday_hour', 21);
        if ($ndh < 0 || $ndh > 23) $ndh = 21;
        $t->putSetting('thali', [
            'enabled' => $r->boolean('enabled'),
            'price'   => max(0, (int) $r->input('price', 0)),
            'note'    => trim((string) $r->input('note', '')),
            'days'    => $clean,
            'images'  => $cleanImg,
            'night_enabled' => $r->boolean('night_enabled'),
            'night_price'   => max(0, (int) $r->input('night_price', 0)),
            'night_note'    => trim((string) $r->input('night_note', '')),
            'night_days'    => $cleanNight,
            'switch_hour'   => $sw,
            'nextday_enabled' => $r->boolean('nextday_enabled'),
            'nextday_hour'    => $ndh,
        ]);
        return response()->json(['ok' => true]);
    }

    /* -------------------------------------------------- live chats (4b) */
    public function chats(Request $r)
    {
        $convos = Conversation::orderByDesc('last_message_at')->limit(100)->get();
        $phones = $convos->pluck('customer_phone')->filter()->unique()->values()->all();

        $lasts = collect();
        $names = collect();
        if ($phones) {
            $lastIds = Message::whereIn('customer_phone', $phones)
                ->selectRaw('max(id) as id')->groupBy('customer_phone')->pluck('id')->all();
            $lasts = Message::whereIn('id', $lastIds)->get()->keyBy('customer_phone');
            $names = Order::whereIn('customer_phone', $phones)->orderByDesc('id')
                ->get(['customer_phone', 'customer_name'])
                ->groupBy('customer_phone')->map(fn ($g) => $g->first()->customer_name);
        }

        $mutedSet = array_flip($this->botMutedList($r->user()->tenant));
        $list = $convos->map(function (Conversation $c) use ($lasts, $names, $mutedSet) {
            $m = $lasts->get($c->customer_phone);
            return [
                'phone'        => (string) $c->customer_phone,
                'name'         => (string) ($names->get($c->customer_phone) ?? ''),
                'last'         => $m ? (string) $m->body : '',
                'last_sender'  => $m ? (string) $m->sender : '',
                'last_at'      => optional($c->last_message_at)->format('Y-m-d H:i:s') ?? '',
                'unread'       => (int) $c->unread,
                'agent_active' => (bool) $c->agent_active,
                'muted'        => isset($mutedSet[preg_replace('/[^0-9]/', '', (string) $c->customer_phone)]),
            ];
        })->values();

        return response()->json([
            'chats'    => $list,
            'bot_mode' => (string) $r->user()->tenant->setting('bot_mode', 'auto'),
        ]);
    }

    public function chatThread(Request $r)
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $r->query('phone', ''));
        if ($phone === '') return response()->json(['messages' => []]);
        $after = (int) $r->query('after', 0);

        $q = Message::where('customer_phone', $phone)->orderBy('id');
        if ($after > 0) $q->where('id', '>', $after);
        $msgs = $q->limit(500)->get()->map(fn (Message $m) => [
            'id'        => (int) $m->id,
            'wa_id'     => (string) ($m->wa_message_id ?? ''),
            'direction' => (string) $m->direction,
            'sender'    => (string) $m->sender,
            'body'      => (string) $m->body,
            'at'        => optional($m->created_at)->format('Y-m-d H:i:s') ?? '',
        ]);

        $c = Conversation::where('customer_phone', $phone)->first();
        $agent = false;
        if ($c) {
            if ($after === 0 && $c->unread) { $c->unread = 0; $c->save(); } // viewing clears the badge
            $agent = (bool) $c->agent_active;
        }

        $muted = in_array($phone, $this->botMutedList($r->user()->tenant), true);
        return response()->json(['messages' => $msgs, 'agent_active' => $agent, 'muted' => $muted]);
    }

    public function chatSend(Request $r, WhatsAppManager $wa)
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $r->input('phone', ''));
        $body  = trim((string) $r->input('body', ''));
        if ($phone === '' || $body === '') {
            return response()->json(['ok' => false, 'error' => 'empty'], 422);
        }
        $t = $r->user()->tenant;

        // Sending by hand = take over this chat so the bot stops auto-replying.
        $c = Conversation::firstOrCreate(
            ['customer_phone' => $phone, 'instance' => $t->whatsapp_instance],
            ['tenant_id' => $t->id, 'state' => [], 'cart' => []]
        );
        $c->agent_active = true;
        $c->save();

        try {
            $quoted = null;
            $qid = trim((string) $r->input('quoted_id', ''));
            if ($qid !== '') {
                $quoted = ['key' => ['id' => $qid]];
            }
            $wa->forTenant($t)->sendText($t->whatsapp_instance, $phone, $body, $quoted);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'send_failed', 'detail' => $e->getMessage()], 502);
        }


        MessageLog::record($t->id, $phone, $t->whatsapp_instance, 'out', 'agent', $body);
        return response()->json(['ok' => true]);
    }

    /** Pull the connected WhatsApp instance's contacts and add the new ones as customers. */
    public function importContacts(Request $r, WhatsAppManager $wa)
    {
        $t = $r->user()->tenant;
        $instance = (string) ($t->whatsapp_instance ?? '');
        if ($instance === '') {
            return response()->json(['ok' => false, 'error' => 'whatsapp_not_connected'], 422);
        }
        try {
            $contacts = $wa->forTenant($t)->fetchContacts($instance);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'fetch_failed', 'detail' => $e->getMessage()], 502);
        }

        $added = 0; $skipped = 0;
        foreach ($contacts as $c) {
            $phone = preg_replace('/[^0-9]/', '', (string) ($c['phone'] ?? ''));
            if ($phone === '') continue;
            if (CustomerProfile::where('phone', $phone)->exists()) { $skipped++; continue; }
            $cp = new CustomerProfile();
            $cp->phone = $phone;
            if (! empty($c['name'])) $cp->name = $c['name'];
            $cp->notes = 'Imported from WhatsApp';
            $cp->save(); // tenant_id stamped by the global scope
            $added++;
        }
        return response()->json(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'total' => count($contacts)]);
    }

    public function chatTakeover(Request $r)
    {
        $phone  = preg_replace('/[^0-9]/', '', (string) $r->input('phone', ''));
        $active = (bool) ((int) $r->input('active', 1));
        $c = Conversation::where('customer_phone', $phone)->first();
        if ($c) {
            $c->agent_active = $active;
            if (! $active) {
                // Handing back to the bot — clear the loop guard so it doesn't instantly re-pause.
                $s = is_array($c->state) ? $c->state : [];
                unset($s['lg_out_times'], $s['lg_last_out'], $s['lg_last_out_at'], $s['lg_paused'], $s['lg_alerted']);
                $c->state = $s;
            }
            $c->save();
        }
        return response()->json(['ok' => true, 'agent_active' => $active]);
    }

    /** Numbers (digits) the bot must never auto-reply to — persistent per-tenant list. */
    private function botMutedList($tenant): array
    {
        $list = $tenant->setting('bot_muted', []);
        if (! is_array($list)) $list = [];
        return array_values(array_unique(array_filter(array_map(
            fn ($p) => preg_replace('/[^0-9]/', '', (string) $p), $list
        ))));
    }

    /** Mute / unmute the bot for one number (persists across chats & restarts). */
    public function chatMute(Request $r)
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $r->input('phone', ''));
        $muted = (bool) ((int) $r->input('muted', 1));
        if ($phone === '') return response()->json(['ok' => false, 'error' => 'phone_required'], 422);

        $t = $r->user()->tenant;
        $list = $this->botMutedList($t);
        if ($muted) {
            if (! in_array($phone, $list, true)) $list[] = $phone;
        } else {
            $list = array_values(array_filter($list, fn ($p) => $p !== $phone));
        }
        $t->putSetting('bot_muted', $list);
        return response()->json(['ok' => true, 'muted' => $muted, 'list' => $list]);
    }

    public function chatBotMode(Request $r)
    {
        $mode = (string) $r->input('mode', 'auto');
        if (! in_array($mode, ['auto', 'off'], true)) $mode = 'auto';
        $t = $r->user()->tenant;
        // Preserve an admin-configured smart bot: the owner's on/off switch flips brain↔off,
        // it must not silently downgrade the tenant to the inbuilt cart bot.
        $cur = (string) $t->setting('bot_mode', 'auto');
        if (in_array($cur, ['n8n', 'ai'], true) && $mode === 'auto') $mode = $cur;
        $s = $t->settings ?? [];
        $s['bot_mode'] = $mode;
        $t->settings = $s;
        $t->save();
        return response()->json(['ok' => true, 'bot_mode' => $mode]);
    }

    /**
     * Diagnostic — open in the browser while logged in to see exactly where the
     * chat-history chain is breaking. Shows what's in our DB and what Evolution
     * returns (status, totals, top-level keys, sample record keys).
     */
    public function chatSyncDebug(Request $r, EvolutionAdmin $evo)
    {
        $t = $r->user()->tenant;
        $instance = (string) ($t->whatsapp_instance ?? '');

        $out = [
            'evolution_configured' => $evo->configured(),
            'tenant_id'            => $t->id,
            'instance'             => $instance,
            'our_db_messages'      => Message::count(),
            'our_db_conversations' => Conversation::count(),
        ];

        if ($evo->configured() && $instance !== '') {
            $raw  = $evo->findMessagesRaw($instance, 1, 5);
            $body = $raw['body'];
            $recs = data_get($body, 'messages.records');
            $out['evolution_http_status']    = $raw['status'];
            $out['evolution_top_level_keys'] = is_array($body) ? array_keys($body) : [];
            $out['evolution_messages_total'] = data_get($body, 'messages.total');
            $out['evolution_records_on_page'] = is_array($recs) ? count($recs) : null;
            $out['evolution_sample_record_keys'] = (is_array($recs) && isset($recs[0]) && is_array($recs[0]))
                ? array_keys($recs[0]) : [];
            // a tiny redacted sample so we can see the field layout without leaking much
            if (is_array($recs) && isset($recs[0])) {
                $out['evolution_sample'] = [
                    'fromMe'    => data_get($recs[0], 'key.fromMe'),
                    'remoteJid' => data_get($recs[0], 'key.remoteJid'),
                    'hasText'   => (bool) (data_get($recs[0], 'message.conversation') ?? data_get($recs[0], 'message.extendedTextMessage.text')),
                    'timestamp' => data_get($recs[0], 'messageTimestamp'),
                ];
            }
            $out['evolution_chats_count'] = count($evo->findChats($instance));
            $wh = $evo->getWebhook($instance);
            $out['webhook_url']      = data_get($wh, 'url') ?? data_get($wh, 'webhook.url');
            $out['webhook_enabled']  = data_get($wh, 'enabled') ?? data_get($wh, 'webhook.enabled');
            $out['webhook_expected'] = url('/api/webhook/whatsapp/evolution');
        } else {
            $out['note'] = $instance === '' ? 'Tenant has no whatsapp_instance set' : 'EVOLUTION_BASE_URL / EVOLUTION_API_KEY not set';
        }

        return response()->json($out);
    }

    /**
     * Re-point this tenant's Evolution instance webhook at our app so incoming
     * customer messages start logging again (MESSAGES_UPSERT). Then verify by
     * reading the webhook config back. The panel auto-runs Sync afterwards to
     * backfill anything already sitting in Evolution's store.
     */
    public function chatRelinkWebhook(Request $r, EvolutionAdmin $evo)
    {
        if (! $evo->configured()) {
            return response()->json(['ok' => false, 'error' => 'evolution_not_configured'], 400);
        }
        $t = $r->user()->tenant;
        $instance = (string) ($t->whatsapp_instance ?? '');
        if ($instance === '') {
            return response()->json(['ok' => false, 'error' => 'no_instance'], 400);
        }

        $expected = url('/api/webhook/whatsapp/evolution');
        $evo->setWebhook($instance, $expected);

        // Read it back so we can tell the operator whether it actually stuck.
        $wh      = $evo->getWebhook($instance);
        $current = (string) (data_get($wh, 'url') ?? data_get($wh, 'webhook.url') ?? '');
        $enabled = (bool)   (data_get($wh, 'enabled') ?? data_get($wh, 'webhook.enabled') ?? false);
        $linked  = $current !== '' && rtrim($current, '/') === rtrim($expected, '/');

        return response()->json([
            'ok'       => true,
            'linked'   => $linked,
            'enabled'  => $enabled,
            'current'  => $current,
            'expected' => $expected,
        ]);
    }

    /**
     * Pull human-readable text out of any Baileys/Evolution message shape:
     * plain text, captions, button/list replies, and media (as a labelled
     * placeholder so the bubble still shows). Returns '' only when truly nothing.
     */
    private function waMessageText(array $m): string
    {
        $msg = data_get($m, 'message', []);
        if (! is_array($msg)) return '';

        // unwrap ephemeral / view-once / caption wrappers
        foreach (['ephemeralMessage.message', 'viewOnceMessage.message', 'viewOnceMessageV2.message', 'documentWithCaptionMessage.message'] as $w) {
            $inner = data_get($msg, $w);
            if (is_array($inner)) $msg = $inner;
        }

        $t = data_get($msg, 'conversation')
            ?? data_get($msg, 'extendedTextMessage.text')
            ?? data_get($msg, 'imageMessage.caption')
            ?? data_get($msg, 'videoMessage.caption')
            ?? data_get($msg, 'documentMessage.caption')
            ?? data_get($msg, 'buttonsResponseMessage.selectedDisplayText')
            ?? data_get($msg, 'listResponseMessage.title')
            ?? data_get($msg, 'templateButtonReplyMessage.selectedDisplayText')
            ?? data_get($msg, 'reactionMessage.text');
        if (is_string($t) && trim($t) !== '') return $t;

        // media with no caption -> labelled placeholder (still shows in thread)
        if (data_get($msg, 'imageMessage'))    return '📷 Photo';
        if (data_get($msg, 'videoMessage'))    return '🎬 Video';
        if (data_get($msg, 'audioMessage'))    return '🎤 Voice message';
        if (data_get($msg, 'stickerMessage'))  return '🌟 Sticker';
        if (data_get($msg, 'documentMessage')) return '📄 '.((string) (data_get($msg, 'documentMessage.fileName') ?: 'Document'));
        if (data_get($msg, 'locationMessage')) return '📍 Location';
        if (data_get($msg, 'contactMessage'))  return '👤 Contact';
        return '';
    }

    /**
     * One-time (re-runnable) backfill: pull existing WhatsApp messages out of
     * Evolution's store into our transcript so past chats appear in the inbox.
     * De-duplicates on wa_message_id, so running it again is safe.
     */
    public function chatSync(Request $r, EvolutionAdmin $evo)
    {
        if (! $evo->configured()) {
            return response()->json(['ok' => false, 'error' => 'evolution_not_configured'], 400);
        }
        $t = $r->user()->tenant;
        $instance = (string) ($t->whatsapp_instance ?? '');
        if ($instance === '') {
            return response()->json(['ok' => false, 'error' => 'no_instance'], 400);
        }

        $existing  = Message::whereNotNull('wa_message_id')->pluck('wa_message_id')->flip();
        $imported  = 0;
        $scanned   = 0;
        $convoLast = [];
        $offset    = 200;
        $maxPages  = 25; // cap ~5000 messages per run; re-run for more (dedup handles it)

        for ($page = 1; $page <= $maxPages; $page++) {
            $records = $evo->findMessages($instance, $page, $offset);
            if (! $records) break;

            $rows = [];
            foreach ($records as $m) {
                $scanned++;
                $remote = (string) data_get($m, 'key.remoteJid', '');
                if ($remote === '' || str_contains($remote, '@g.us') || str_contains($remote, 'broadcast')) continue;

                $waId = (string) data_get($m, 'key.id', '');
                if ($waId !== '' && $existing->has($waId)) continue;

                $text = $this->waMessageText($m);
                if (trim($text) === '') continue; // truly nothing (no text, no media)

                $fromMe = (bool) data_get($m, 'key.fromMe', false);
                $phone  = preg_replace('/[^0-9]/', '', explode('@', $remote)[0]);
                $ts     = (int) data_get($m, 'messageTimestamp', 0);
                $when   = $ts > 0 ? date('Y-m-d H:i:s', $ts) : now()->toDateTimeString();

                $rows[] = [
                    'tenant_id'     => $t->id,
                    'customer_phone' => $phone,
                    'instance'      => $instance,
                    'direction'     => $fromMe ? 'out' : 'in',
                    'sender'        => $fromMe ? 'bot' : 'customer',
                    'body'          => $text,
                    'wa_message_id' => $waId ?: null,
                    'status'        => null,
                    'meta'          => null,
                    'created_at'    => $when,
                    'updated_at'    => $when,
                ];
                if ($waId !== '') $existing[$waId] = true;
                $imported++;
                if (! isset($convoLast[$phone]) || $convoLast[$phone] < $when) $convoLast[$phone] = $when;
            }
            if ($rows) Message::insert($rows);
            if (count($records) < $offset) break; // reached the last page
        }

        foreach ($convoLast as $phone => $when) {
            $c = Conversation::firstOrCreate(
                ['customer_phone' => $phone, 'instance' => $instance],
                ['tenant_id' => $t->id, 'state' => [], 'cart' => []]
            );
            if (! $c->last_message_at || $c->last_message_at->lt($when)) {
                $c->last_message_at = $when;
                $c->save();
            }
        }

        // Also pull the CONTACT/CHAT list so every number shows in the inbox even
        // if its message bodies aren't in Evolution's store. Names come along too.
        $contacts = 0;
        foreach ($evo->findChats($instance) as $ch) {
            $remote = (string) (data_get($ch, 'remoteJid') ?? data_get($ch, 'id') ?? '');
            if ($remote === '' || str_contains($remote, '@g.us') || str_contains($remote, 'broadcast')) continue;
            $phone = preg_replace('/[^0-9]/', '', explode('@', $remote)[0]);
            if ($phone === '') continue;

            $c = Conversation::firstOrCreate(
                ['customer_phone' => $phone, 'instance' => $instance],
                ['tenant_id' => $t->id, 'state' => [], 'cart' => []]
            );
            if (! $c->last_message_at) {
                $c->last_message_at = now();
                $c->save();
            }
            $contacts++;

            $name = trim((string) (data_get($ch, 'pushName') ?? data_get($ch, 'name') ?? ''));
            if ($name !== '') {
                $cp = CustomerProfile::firstOrNew(['phone' => $phone]);
                if (! $cp->name) { $cp->name = $name; $cp->save(); }
            }
        }

        return response()->json(['ok' => true, 'imported' => $imported, 'scanned' => $scanned, 'contacts' => $contacts]);
    }

    /* -------------------------------------------------- self-serve onboarding */
    // WhatsApp connect — no Evolution dashboard needed: create instance, show QR, poll state.
    public function waStatus(Request $r, EvolutionAdmin $evo)
    {
        if (! $evo->configured()) {
            return response()->json(['ok' => false, 'configured' => false, 'state' => 'missing']);
        }
        $instance = (string) ($r->user()->tenant->whatsapp_instance ?? '');
        return response()->json([
            'ok'         => true,
            'configured' => true,
            'instance'   => $instance,
            'state'      => $instance ? $evo->state($instance) : 'missing',
        ]);
    }

    public function waConnect(Request $r, EvolutionAdmin $evo)
    {
        if (! $evo->configured()) {
            return response()->json(['ok' => false, 'error' => 'evolution_not_configured'], 400);
        }
        $t = $r->user()->tenant;
        $instance = $t->whatsapp_instance ?: ('shopbot_t' . $t->id);
        if ($t->whatsapp_instance !== $instance || ! $t->whatsapp_driver) {
            $t->whatsapp_instance = $instance;
            $t->whatsapp_driver   = $t->whatsapp_driver ?: 'evolution';
            $t->save();
        }
        $evo->createIfMissing($instance);
        $evo->setWebhook($instance, url('/api/webhook/whatsapp/evolution'));
        $t->syncPrimaryChannel();   // mirror the primary number into whatsapp_channels

        // Fresh connect attempt: clear any manual/alert flags so a future real drop alerts.
        $t->putSetting('wa_conn_state', 'connecting');
        $t->putSetting('wa_down_alerted', false);

        return response()->json([
            'ok'       => true,
            'instance' => $instance,
            'state'    => $evo->state($instance),
            'qr'       => $evo->qr($instance),
        ]);
    }

    public function waQr(Request $r, EvolutionAdmin $evo)
    {
        $instance = (string) ($r->user()->tenant->whatsapp_instance ?? '');
        if ($instance === '') return response()->json(['ok' => false], 400);
        return response()->json(['ok' => true, 'state' => $evo->state($instance), 'qr' => $evo->qr($instance)]);
    }

    public function waDisconnect(Request $r, EvolutionAdmin $evo)
    {
        $t = $r->user()->tenant;
        $instance = (string) ($t->whatsapp_instance ?? '');
        if ($instance !== '') $evo->disconnect($instance);
        // Deliberate disconnect — suppress the "went offline" alert.
        $t->putSetting('wa_conn_state', 'manual_off');
        $t->putSetting('wa_down_alerted', false);
        return response()->json(['ok' => true]);
    }

    /**
     * Multiple WhatsApp numbers per shop. A shop keeps ONE catalogue/orders backend while attaching
     * several numbers (branches, a support line, or a ban-safe backup). The primary is the QR-linked
     * number above; these are additional Evolution instances that all route into the same tenant.
     */
    public function waChannels(Request $r, EvolutionAdmin $evo)
    {
        $t = $r->user()->tenant;
        $t->syncPrimaryChannel();
        $rows = \App\Models\WhatsappChannel::where('tenant_id', $t->id)
            ->orderByDesc('is_primary')->orderBy('id')->get();

        $out = $rows->map(function ($c) use ($evo, $t) {
            $number = $c->number;
            $state  = 'n/a';
            if ($c->driver === 'evolution' && $evo->configured()) {
                // Read the LIVE linked device. If the number changed (e.g. you swapped a South Sudan
                // number for a Uganda one), self-heal the stored value so the panel matches reality.
                $info = $evo->instanceInfo($c->instance);
                $live = trim((string) ($info['number'] ?? ''));
                $state = strtolower((string) ($info['state'] ?? '')) ?: $evo->state($c->instance);
                if ($live !== '') {
                    $number = $live;
                    if ($live !== (string) $c->number) { $c->number = $live; $c->save(); }
                    if ($c->is_primary && $live !== (string) $t->whatsapp_number) {
                        $t->whatsapp_number = $live; $t->save();
                    }
                }
            }
            return [
                'id'         => $c->id,
                'instance'   => $c->instance,
                'number'     => $number,
                'label'      => $c->label,
                'is_primary' => $c->is_primary,
                'active'     => $c->active,
                'state'      => $state ?: 'missing',   // open | connecting | close | missing | n/a
            ];
        })->values();

        return response()->json(['ok' => true, 'channels' => $out]);
    }

    /** Add another number: create a fresh Evolution instance for this shop and return its QR. */
    public function waChannelAdd(Request $r, EvolutionAdmin $evo)
    {
        if (! $evo->configured()) {
            return response()->json(['ok' => false, 'error' => 'evolution_not_configured'], 400);
        }
        $u = $r->user();
        if (! method_exists($u, 'isOwnerLike') || ! $u->isOwnerLike()) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $u->tenant;

        // Plan gate: additional numbers are a paid capability (feature flag 'multi_number').
        if (method_exists($t, 'hasFeature') && ! $t->hasFeature('multi_number') && ! $t->onTrial()) {
            return response()->json(['ok' => false, 'error' => 'upgrade',
                'detail' => 'Multiple WhatsApp numbers are available on the Pro plan.'], 402);
        }

        $label = trim((string) $r->input('label', ''));
        $label = $label !== '' ? mb_substr($label, 0, 40) : 'Number ' . (\App\Models\WhatsappChannel::where('tenant_id', $t->id)->count() + 1);

        // Unique instance name: shopbot_t{id}_2, _3, …
        $n = 2;
        do {
            $instance = 'shopbot_t' . $t->id . '_' . $n;
            $n++;
        } while (\App\Models\WhatsappChannel::where('instance', $instance)->exists());

        $evo->createIfMissing($instance);
        $evo->setWebhook($instance, url('/api/webhook/whatsapp/evolution'));

        \App\Models\WhatsappChannel::create([
            'tenant_id'  => $t->id,
            'instance'   => $instance,
            'driver'     => 'evolution',
            'label'      => $label,
            'is_primary' => false,
            'active'     => true,
        ]);

        return response()->json([
            'ok'       => true,
            'instance' => $instance,
            'label'    => $label,
            'state'    => $evo->state($instance),
            'qr'       => $evo->qr($instance),
        ]);
    }

    /** Get a fresh QR for an additional channel (re-pair). */
    public function waChannelQr(Request $r, EvolutionAdmin $evo)
    {
        $t = $r->user()->tenant;
        $instance = (string) $r->input('instance', '');
        $c = \App\Models\WhatsappChannel::where('tenant_id', $t->id)->where('instance', $instance)->first();
        if (! $c) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        return response()->json(['ok' => true, 'state' => $evo->state($instance), 'qr' => $evo->qr($instance)]);
    }

    /** Remove an additional number (the primary is removed via the main Disconnect button). */
    public function waChannelRemove(Request $r, EvolutionAdmin $evo)
    {
        $u = $r->user();
        if (! method_exists($u, 'isOwnerLike') || ! $u->isOwnerLike()) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $u->tenant;
        $instance = (string) $r->input('instance', '');
        $c = \App\Models\WhatsappChannel::where('tenant_id', $t->id)->where('instance', $instance)->first();
        if (! $c) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        if ($c->is_primary) {
            return response()->json(['ok' => false, 'error' => 'is_primary',
                'detail' => 'This is your main number — use Disconnect above to remove it.'], 409);
        }
        try { $evo->disconnect($instance); } catch (\Throwable $e) { /* best-effort */ }
        $c->delete();
        return response()->json(['ok' => true]);
    }

    // ---- Official WhatsApp Cloud API (Bring-Your-Own, Pro plan) ----

    /** Current Cloud-API state + the exact values the owner pastes into Meta. */
    public function waCloudInfo(Request $r)
    {
        $t = $r->user()->tenant;
        return response()->json([
            'ok'            => true,
            'plan_ok'       => $t->effectivePlan() === 'pro',
            'driver'        => (string) ($t->whatsapp_driver ?: 'evolution'),
            'connected'     => $t->whatsapp_driver === 'cloud' && (bool) $t->setting('cloud_token'),
            'phone_id'      => $t->whatsapp_driver === 'cloud' ? (string) $t->whatsapp_instance : '',
            'waba_id'       => (string) $t->setting('cloud_waba', ''),
            'display_number'=> (string) $t->setting('cloud_display', ''),
            'token_set'     => (bool) $t->setting('cloud_token'),
            // values to paste into Meta -> WhatsApp -> Configuration -> Webhook
            'webhook_url'   => url('/api/webhook/whatsapp/cloud'),
            'verify_token'  => (string) config('whatsapp.cloud_verify_token'),
        ]);
    }

    /** Save the tenant's own Cloud-API credentials and switch them to the cloud driver. */
    public function waCloudSave(Request $r)
    {
        $t = $r->user()->tenant;
        if ($t->effectivePlan() !== 'pro') {
            return response()->json(['ok' => false, 'error' => 'upgrade_required', 'feature' => 'cloud_api'], 403);
        }

        $phoneId = preg_replace('/[^0-9]/', '', (string) $r->input('phone_id', ''));
        $token   = trim((string) $r->input('token', ''));
        $waba    = preg_replace('/[^0-9]/', '', (string) $r->input('waba_id', ''));
        $display = preg_replace('/[^0-9+ ]/', '', (string) $r->input('display_number', ''));

        if ($phoneId === '' || $token === '') {
            return response()->json(['ok' => false, 'error' => 'missing_fields',
                'detail' => 'Phone number ID and a permanent access token are required.'], 422);
        }

        $s = $t->settings ?? [];
        $s['cloud_token']   = $token;
        $s['cloud_waba']    = $waba;
        $s['cloud_display'] = $display;
        $t->settings          = $s;
        $t->whatsapp_driver   = 'cloud';
        $t->whatsapp_instance = $phoneId;     // Cloud API addresses the number by phone_number_id
        $t->save();

        return response()->json([
            'ok'           => true,
            'driver'       => 'cloud',
            'phone_id'     => $phoneId,
            'webhook_url'  => url('/api/webhook/whatsapp/cloud'),
            'verify_token' => (string) config('whatsapp.cloud_verify_token'),
        ]);
    }

    /** Switch a tenant back to the Evolution (QR) driver. Cloud creds are kept. */
    public function waUseEvolution(Request $r)
    {
        $t = $r->user()->tenant;
        $t->whatsapp_driver   = 'evolution';
        $t->whatsapp_instance = 'shopbot_t' . $t->id;   // next "Connect WhatsApp" re-pairs via QR
        $t->save();
        return response()->json(['ok' => true, 'driver' => 'evolution']);
    }

    // ---- Cashbook (money in/out) + order payments ----

    /** Cashbook view: balance, period totals, recent entries, and orders still owing. */
    public function cashbook(Request $r)
    {
        $t      = $r->user()->tenant;
        $period = (string) $r->query('period', '30');   // today | 7 | 30 | all
        $since  = match ($period) {
            'today' => now()->startOfDay(),
            '7'     => now()->subDays(7),
            'all'   => null,
            default => now()->subDays(30),
        };

        // All-time cash position.
        $allIn  = (float) LedgerEntry::where('type', 'in')->sum('amount');
        $allOut = (float) LedgerEntry::where('type', 'out')->sum('amount');

        // Period totals.
        $pq = LedgerEntry::query();
        if ($since) $pq->where('created_at', '>=', $since);
        $periodIn  = (float) (clone $pq)->where('type', 'in')->sum('amount');
        $periodOut = (float) (clone $pq)->where('type', 'out')->sum('amount');

        $eq = LedgerEntry::orderByDesc('id');
        if ($since) $eq->where('created_at', '>=', $since);
        $entries = $eq->limit(200)->get()->map(function (LedgerEntry $e) {
            $orderNo = '';
            if ($e->order_id) {
                $o = Order::find($e->order_id);
                $orderNo = $o ? (string) $o->order_no : '';
            }
            return [
                'id'       => (int) $e->id,
                'type'     => (string) $e->type,
                'category' => (string) $e->category,
                'order_no' => $orderNo,
                'amount'   => (float) $e->amount,
                'currency' => (string) $e->currency,
                'method'   => (string) ($e->method ?? ''),
                'by'       => (string) ($e->received_by ?? ''),
                'note'     => (string) ($e->note ?? ''),
                'at'       => optional($e->created_at)->format('Y-m-d H:i'),
            ];
        });

        $owing = Order::whereColumn('amount_paid', '<', 'total')
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (Order $o) => [
                'id'       => (int) $o->id,
                'order_no' => (string) $o->order_no,
                'customer' => (string) ($o->customer_name ?: $o->customer_phone),
                'total'    => (float) $o->total,
                'paid'     => (float) $o->amount_paid,
                'balance'  => $o->balanceDue(),
            ])->values();

        return response()->json([
            'ok'          => true,
            'balance'     => round($allIn - $allOut, 2),
            'period'      => $period,
            'period_in'   => round($periodIn, 2),
            'period_out'  => round($periodOut, 2),
            'period_net'  => round($periodIn - $periodOut, 2),
            'currency'    => 'UGX',
            'entries'     => $entries,
            'owing'       => $owing,
        ]);
    }

    /** Add a manual cashbook entry — money in (other income) or out (expense/supplier/draw). */
    public function cashbookAdd(Request $r)
    {
        $t      = $r->user()->tenant;
        $type   = $r->input('type') === 'out' ? 'out' : 'in';
        $amount = round((float) $r->input('amount', 0), 2);
        $cat    = trim((string) $r->input('category', 'other')) ?: 'other';
        $method = trim((string) $r->input('method', '')) ?: null;
        $note   = trim((string) $r->input('note', '')) ?: null;

        if ($amount <= 0) {
            return response()->json(['ok' => false, 'error' => 'bad_amount'], 422);
        }

        LedgerEntry::create([
            'type'        => $type,
            'category'    => $cat,
            'amount'      => $amount,
            'currency'    => 'UGX',
            'method'      => $method,
            'received_by' => (string) ($r->user()->name ?? ''),
            'note'        => $note,
        ]);

        $balance = (float) LedgerEntry::where('type', 'in')->sum('amount')
                 - (float) LedgerEntry::where('type', 'out')->sum('amount');

        return response()->json(['ok' => true, 'balance' => round($balance, 2)]);
    }

    /** Register a payment against an order, then WhatsApp the customer a receipt. */
    public function recordPayment(Request $r, WhatsAppManager $wa)
    {
        $t      = $r->user()->tenant;
        $ref    = trim((string) $r->input('order', ''));   // order_no or numeric id
        $amount = round((float) $r->input('amount', 0), 2);
        $method = trim((string) $r->input('method', 'cash')) ?: 'cash';
        $note   = trim((string) $r->input('note', '')) ?: null;
        $notify = $r->input('notify', '1') !== '0';

        // WHO received the cash. Optional `received_by` (or `holder`) names the person who actually took
        // the money — so it lands in THEIR cashbook, not the logged-in operator's. Defaults to the
        // operator when not supplied, so existing callers are unchanged. We resolve the matching user id
        // for the cash-by-person ledger; created_by always stays the operator (who keyed it in).
        $actorName    = (string) ($r->user()->name ?? '');
        $receivedName = trim((string) $r->input('received_by', (string) $r->input('holder', '')));
        $holderName   = $receivedName !== '' ? $receivedName : $actorName;
        $holderUserId = $r->user()->id;
        if ($receivedName !== '' && strcasecmp($receivedName, $actorName) !== 0) {
            $hu = \App\Models\User::where('tenant_id', $t->id)->whereRaw('lower(name) = ?', [mb_strtolower($receivedName)])->first();
            $holderUserId = $hu ? $hu->id : null;
        }

        if ($ref === '' || $amount <= 0) {
            return response()->json(['ok' => false, 'error' => 'bad_input'], 422);
        }

        $order = ctype_digit($ref)
            ? Order::find((int) $ref)
            : Order::where('order_no', $ref)->first();
        if (! $order) {
            return response()->json(['ok' => false, 'error' => 'order_not_found'], 404);
        }

        // Currency of THIS payment. The panel appends ?cur=base for USD-denominated shops (and
        // may send cur=native / an explicit code otherwise). Previously this was IGNORED and every
        // payment was booked as UGX — so a $90 payment was stored as 90 UGX (~$0.02), leaving the
        // invoice permanently a few cents short and stuck on Part_paid. Resolve the real currency
        // and rate so the payment ledger matches what the operator actually typed.
        $baseCur   = \App\Services\Money\SalesBaseCurrency::baseCurrency($t);   // e.g. USD
        $nativeCur = \App\Services\Money\SalesBaseCurrency::nativeCurrency($t); // e.g. UGX
        $orderCur  = \App\Services\Money\SalesBaseCurrency::txnCurrency($order, $t); // the order's OWN currency
        $curIn     = strtolower(trim((string) ($r->query('cur', '') ?: $r->input('cur', ''))));
        // Which currency is the typed amount in? cur=base → USD (the v3 payment modal); cur=native →
        // the tenant local currency; an explicit code → that code; DEFAULT (no cur) → the ORDER's own
        // currency, because the order "Record payment" action already converts the amount into the
        // order's native currency before posting. Defaulting to USD here would record 548,250 UGX as
        // $548,250 on a local-currency order.
        $payCur    = $curIn === 'base' ? $baseCur
                   : ($curIn === 'native' ? $nativeCur
                   : (in_array(strtoupper($curIn), ['USD', 'UGX', 'SSP'], true) ? strtoupper($curIn) : $orderCur));
        $payRate   = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($payCur);
        if ($payRate <= 0) $payRate = 1;
        // Express the payment in the order's own currency before adding it to amount_paid.
        $amountInOrderCur = \App\Services\Money\Fx::forTenant($t)->convert($amount, $payCur, $orderCur);

        $payUnify = (bool) $t->setting('pay_unify', false);

        // E4.6 — legacy order_payment write retired under pay_unify. When the flag is ON the payment is
        // recorded ONCE, in the unified CustomerPayment ledger (below), and stays visible in the trade
        // cashbook/Payments/statements — so this duplicate ledger_entries copy is no longer written.
        // When the flag is OFF the legacy write is preserved verbatim (zero-diff default). Reversible:
        // flipping pay_unify off restores the legacy cashbook write.
        if (! $payUnify) {
            LedgerEntry::create([
                'type'        => 'in',
                'category'    => 'order_payment',
                'order_id'    => $order->id,
                'amount'      => $amount,
                'currency'    => $payCur,
                'method'      => $method,
                'received_by' => $holderName,
                'note'        => $note,
            ]);
        }

        $order->amount_paid = round((float) $order->amount_paid + $amountInOrderCur, 2);
        $order->save();

        // E4.4 — dual-write into the ONE payment ledger (CustomerPayment) so this cashbook payment is
        // visible in Payments, the trade cashbook and the invoice/statement — not only the legacy
        // ledger_entries table. Gated on pay_unify. Best-effort: never break the cashbook payment path.
        if ($payUnify) {
            try {
                app(\App\Services\Money\PaymentService::class)->record($t, [
                    'customer'       => (string) ($order->customer_name ?? 'Customer'),
                    'phone'          => $order->customer_phone,
                    'order_no'       => (string) $order->order_no,
                    'invoice_no'     => null,
                    'kind'           => 'settlement',
                    'currency'       => $payCur,
                    'amount'         => $amount,
                    'rate'           => $payRate,
                    'usd'            => \App\Models\OrderCost::toUsd($amount, $payRate),
                    'method'         => $method,
                    'reference'      => $note,
                    'receipt_path'   => null,
                    'holder'         => $holderName,
                    'holder_user_id' => $holderUserId,
                    'created_by'     => $actorName,
                    'cost_note'      => 'Payment from ' . (string) ($order->customer_name ?? '') . ' (' . (string) $order->order_no . ')',
                ]);
            } catch (\Throwable $e) { /* keep the legacy cashbook payment path working regardless */ }
        }

        $balance = $order->balanceDue();
        $state   = $order->paymentState();

        // Receipt to the customer.
        $sent = false;
        if ($notify && $order->customer_phone) {
            $amt     = number_format($amount, $payCur === 'USD' ? 2 : 0);
            $balBase = \App\Services\Money\Fx::forTenant($t)->convert($balance, $orderCur, $baseCur);
            $txt = $state === 'paid'
                ? "✅ Payment received: {$payCur} {$amt} for order *{$order->order_no}*. Paid in full — thank you! 🛍"
                : "✅ Payment received: {$payCur} {$amt} for order *{$order->order_no}*. Balance left: *{$baseCur} " . number_format($balBase, 2) . "*.";
            try {
                $wa->forTenant($t)->sendText($t->whatsapp_instance, $order->customer_phone, $txt);
                MessageLog::record($t->id, $order->customer_phone, $t->whatsapp_instance, 'out', 'system', $txt);
                $sent = true;
            } catch (\Throwable $e) {
                $sent = false;
            }
        }

        return response()->json([
            'ok'          => true,
            'order_no'    => (string) $order->order_no,
            'amount_paid' => (float) $order->amount_paid,
            'balance'     => $balance,
            'state'       => $state,
            'notified'    => $sent,
        ]);
    }

    // ---- Staff logins (seat-capped by plan) ----

    /** List this shop's staff logins + seat usage. */
    private function normStaffRole(string $role): string
    {
        $role = trim($role) ?: 'staff';
        return in_array($role, ['owner', 'manager', 'product_staff', 'staff', 'purchaser', 'field', 'sales', 'transport'], true) ? $role : 'staff';
    }

    /** Parse per-user menu override. Returns null (use role default) or an array of valid keys. */
    private function staffMenus(Request $r): ?array
    {
        if (! $r->has('allowed_menus')) return null;
        $raw = $r->input('allowed_menus');
        if ($raw === '' || $raw === null || $raw === 'default') return null;   // null = role default
        if (is_string($raw)) $raw = array_filter(array_map('trim', explode(',', $raw)));
        if (! is_array($raw)) return null;
        $valid = \App\Models\User::allMenuKeys();
        $out = array_values(array_intersect($valid, $raw));
        if (! in_array('dashboard', $out, true)) $out[] = 'dashboard';        // always keep dashboard
        return $out;
    }

    /** Parse the category list for a product_staff user (null for any other role). */
    private function staffCats(Request $r, string $role): ?array
    {
        if ($role !== 'product_staff') return null;
        $cats = $r->input('categories', null);
        if (is_string($cats)) $cats = explode(',', $cats);
        if (! is_array($cats)) return null;
        $cats = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $cats), fn ($c) => $c !== ''));
        return $cats ?: null;
    }

    public function staffList(Request $r)
    {
        if (\App\Support\Access::blocks($r->user(), 'staff.manage')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t   = $r->user()->tenant;
        $me  = (int) $r->user()->id;
        $cap = $t->userCap();

        $staff = User::where('tenant_id', $t->id)->orderBy('id')->get()
            ->map(fn (User $u) => [
                'id'    => (int) $u->id,
                'name'  => (string) $u->name,
                'email' => (string) $u->email,
                'phone' => (string) ($u->phone ?? ''),
                'role'  => (string) ($u->role ?: 'staff'),
                'allowed_menus' => is_array($u->allowed_menus) ? $u->allowed_menus : null,
                'categories' => is_array($u->allowed_categories) ? array_values($u->allowed_categories) : [],
                'self'  => (int) $u->id === $me,
            ])->values();

        $categories = Product::where('tenant_id', $t->id)
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category')->all();

        return response()->json([
            'ok'        => true,
            'staff'     => $staff,
            'categories' => $categories,
            'used'      => $staff->count(),
            'cap'       => $cap,                       // null = unlimited
            'unlimited' => $cap === null,
            'at_limit'  => $t->atUserLimit(),
            'plan'      => $t->planLabel(),
        ]);
    }

    /** Add a staff login for this shop (blocked at the plan's seat limit). */
    public function staffAdd(Request $r)
    {
        if (! $r->user()->isOwnerLike()) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t     = $r->user()->tenant;
        $name  = trim((string) $r->input('name', ''));
        $email = strtolower(trim((string) $r->input('email', '')));
        $phone = preg_replace('/\D+/', '', (string) $r->input('phone', ''));
        $pass  = (string) $r->input('password', '');
        $role  = $this->normStaffRole((string) $r->input('role', 'staff'));

        if ($t->atUserLimit()) {
            return response()->json(['ok' => false, 'error' => 'upgrade_required', 'feature' => 'multi_user', 'cap' => $t->userCap()], 403);
        }
        // Need name + email, and a login method: a WhatsApp number (OTP) OR a 6+ char password.
        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ($phone === '' && strlen($pass) < 6)) {
            return response()->json(['ok' => false, 'error' => 'bad_input',
                'detail' => 'Name, a valid email, and either a WhatsApp number or a password of at least 6 characters are required.'], 422);
        }
        if (User::where('email', $email)->exists()) {
            return response()->json(['ok' => false, 'error' => 'email_taken'], 409);
        }
        if ($phone !== '' && User::where('phone', $phone)->exists()) {
            return response()->json(['ok' => false, 'error' => 'phone_taken'], 409);
        }

        // If they'll sign in by WhatsApp and no password was set, store a random one
        // so the column is satisfied; their real login is the OTP.
        $password = strlen($pass) >= 6 ? $pass : \Illuminate\Support\Str::random(24);

        User::create([
            'tenant_id' => $t->id,
            'name'      => $name,
            'email'     => $email,
            'phone'     => $phone !== '' ? $phone : null,
            'password'  => $password,        // 'hashed' cast hashes on save
            'role'      => $role,
            'allowed_categories' => $this->staffCats($r, $role),
            'allowed_menus' => $this->staffMenus($r),
        ]);

        return response()->json(['ok' => true]);
    }

    /** Remove a staff login (cannot remove yourself or the last login). */
    public function staffDelete(Request $r)
    {
        if (! $r->user()->isOwnerLike()) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t  = $r->user()->tenant;
        $id = (int) $r->input('id', 0);
        $me = (int) $r->user()->id;

        if ($id === $me) {
            return response()->json(['ok' => false, 'error' => 'cannot_delete_self'], 422);
        }
        if (User::where('tenant_id', $t->id)->count() <= 1) {
            return response()->json(['ok' => false, 'error' => 'last_user'], 422);
        }

        $u = User::where('tenant_id', $t->id)->where('id', $id)->first();
        if (! $u) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $u->delete();

        return response()->json(['ok' => true]);
    }

    /** Update an existing staff login (name/email/phone/role, optional new password). */
    public function staffUpdate(Request $r)
    {
        if (! $r->user()->isOwnerLike()) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t  = $r->user()->tenant;
        $id = (int) $r->input('id', 0);
        $u  = User::where('tenant_id', $t->id)->where('id', $id)->first();
        if (! $u) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $name  = trim((string) $r->input('name', (string) $u->name));
        $email = strtolower(trim((string) $r->input('email', (string) $u->email)));
        $phone = preg_replace('/\D+/', '', (string) $r->input('phone', (string) $u->phone));
        $role  = $this->normStaffRole((string) $r->input('role', (string) ($u->role ?: 'staff')));
        $pass  = (string) $r->input('password', '');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'bad_input',
                'detail' => 'Name and a valid email are required.'], 422);
        }
        // A working sign-in method must remain: a WhatsApp number, a new password,
        // or an existing password already on the account.
        if ($phone === '' && strlen($pass) < 6 && ! $u->password) {
            return response()->json(['ok' => false, 'error' => 'bad_input',
                'detail' => 'Set a WhatsApp number or a password (6+ characters) so they can sign in.'], 422);
        }
        if (User::where('email', $email)->where('id', '!=', $u->id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'email_taken'], 409);
        }
        if ($phone !== '' && User::where('phone', $phone)->where('id', '!=', $u->id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'phone_taken'], 409);
        }

        $u->name  = $name;
        $u->email = $email;
        $u->phone = $phone !== '' ? $phone : null;
        $u->role  = $role;
        $u->allowed_categories = $this->staffCats($r, $role);
        if ($r->has('allowed_menus')) { $u->allowed_menus = $this->staffMenus($r); }
        if (strlen($pass) >= 6) {
            $u->password = $pass;   // 'hashed' cast re-hashes on save
        }
        $u->save();

        return response()->json(['ok' => true]);
    }

    // ---- Scheduled deliveries ----

    /** Set or clear a delivery schedule on an order. */
    public function scheduleOrder(Request $r)
    {
        $o = Order::find((int) $r->input('order_id', 0));
        if (! $o) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        if ($r->input('mode') === 'now') {
            $o->scheduled_for = null;
            $o->sched_stage = null;
            $o->sched_reminders = null;
            $o->save();
            return response()->json(['ok' => true, 'scheduled' => false]);
        }

        try {
            $dt = \Carbon\Carbon::parse((string) $r->input('when'));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'bad_date'], 422);
        }
        if ($dt->lessThan(now()->subMinute())) {
            return response()->json(['ok' => false, 'error' => 'past'], 422);
        }

        $o->scheduled_for = $dt;
        $o->sched_stage = 'Scheduled';
        $o->sched_reminders = [];
        $o->save();

        return response()->json(['ok' => true, 'scheduled' => true, 'when' => $dt->toIso8601String()]);
    }

    /** Scheduled queue + recent orders that can still be scheduled. */
    public function scheduledList(Request $r)
    {
        $queue = Order::whereNotNull('scheduled_for')->whereNull('delivered_at')
            ->orderBy('scheduled_for')->limit(200)->get()
            ->map(fn (Order $o) => [
                'id'       => (int) $o->id,
                'order_no' => (string) $o->order_no,
                'customer' => (string) $o->customer_name,
                'location' => (string) $o->location,
                'total'    => (float) $o->total,
                'when'     => optional($o->scheduled_for)->toIso8601String(),
                'stage'    => $o->sched_stage ?: 'Scheduled',
            ])->values();

        $candidates = Order::whereNull('scheduled_for')->whereNull('delivered_at')
            ->orderByDesc('id')->limit(40)->get()
            ->map(fn (Order $o) => [
                'id'       => (int) $o->id,
                'order_no' => (string) $o->order_no,
                'customer' => (string) $o->customer_name,
                'total'    => (float) $o->total,
            ])->values();

        return response()->json(['ok' => true, 'queue' => $queue, 'candidates' => $candidates]);
    }

    // ---- Marketing campaigns ----

    public function campaignList(Request $r)
    {
        $campaigns = Campaign::orderByDesc('id')->limit(50)->get()
            ->map(fn (Campaign $c) => [
                'id'         => (int) $c->id,
                'name'       => $c->name ?: $c->typeLabel(),
                'type'       => $c->type,
                'type_label' => $c->typeLabel(),
                'audience'   => $c->audience,
                'status'     => $c->status,
                'when'       => optional($c->scheduled_for)->toIso8601String(),
                'stats'      => is_array($c->stats) ? $c->stats : [],
            ])->values();

        $products = Product::where('active', true)->orderBy('name')->limit(300)
            ->get(['id', 'name', 'price', 'category']);
        $categories = $products->pluck('category')->filter()->unique()->values();

        return response()->json([
            'ok'         => true,
            'campaigns'  => $campaigns,
            'products'   => $products,
            'categories' => $categories,
            'official'   => $r->user()->tenant->whatsapp_driver === 'cloud',
        ]);
    }

    public function campaignSave(Request $r)
    {
        $id   = (int) $r->input('id', 0);
        $data = [
            'name'        => trim((string) $r->input('name', '')) ?: null,
            'type'        => $r->input('type', 'promotion'),
            'audience'    => $r->input('audience', 'all'),
            'category'    => $r->input('category') ?: null,
            'message'     => trim((string) $r->input('message', '')),
            'image_url'   => trim((string) $r->input('image_url', '')) ?: null,
            'product_ids' => array_values(array_filter(array_map('intval', (array) $r->input('product_ids', [])))),
            'cta'         => trim((string) $r->input('cta', '')) ?: 'Reply BUY to order instantly.',
        ];
        if ($data['message'] === '' && ! $data['product_ids']) {
            return response()->json(['ok' => false, 'error' => 'empty'], 422);
        }

        $when = $r->input('scheduled_for');
        if ($when) {
            try {
                $data['scheduled_for'] = \Carbon\Carbon::parse((string) $when);
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'error' => 'bad_date'], 422);
            }
            $data['status'] = 'scheduled';
        } else {
            $data['scheduled_for'] = null;
            $data['status'] = 'draft';
        }

        $c = $id ? Campaign::find($id) : new Campaign();
        if ($id && ! $c) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $c->fill($data)->save();

        return response()->json(['ok' => true, 'id' => $c->id, 'status' => $c->status]);
    }

    /** Send a campaign immediately (throttled background job). */
    public function campaignSend(Request $r, AudienceResolver $aud)
    {
        $c = Campaign::find((int) $r->input('id', 0));
        if (! $c) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $count = $aud->count($c->audience, $c->category);
        if ($count === 0) {
            return response()->json(['ok' => false, 'error' => 'no_audience']);
        }

        $c->update(['status' => 'sending', 'scheduled_for' => null]);
        SendCampaign::dispatch($c->tenant_id, $c->id);

        return response()->json(['ok' => true, 'queued' => true, 'audience' => $count]);
    }

    /** Preview how many customers an audience covers. */
    public function campaignAudience(Request $r, AudienceResolver $aud)
    {
        $count = $aud->count((string) $r->input('audience', 'all'), $r->input('category'));
        return response()->json(['ok' => true, 'count' => $count]);
    }

    /** AI suggests a promotion the owner can review and approve. */
    public function campaignSuggest(Request $r)
    {
        $kind     = (string) $r->input('kind', 'weekend');     // slow | overstock | new | weekend
        $products = $this->suggestProducts($kind);
        $names    = $products->pluck('name')->all();
        $message  = $this->aiPromo($kind, $names) ?: $this->cannedPromo($kind, $names);

        return response()->json([
            'ok'       => true,
            'kind'     => $kind,
            'type'     => $this->kindToType($kind),
            'products' => $products->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name])->values(),
            'message'  => $message,
        ]);
    }

    protected function suggestProducts(string $kind)
    {
        $q = Product::where('active', true);
        // stock-on-hand is the proxy for slow/overstock until there's enough
        // order history for true sales-velocity ranking (v2).
        return match ($kind) {
            'new'      => $q->orderByDesc('id')->limit(5)->get(),
            'overstock', 'slow' => $q->orderByDesc('stock')->limit(5)->get(),
            default    => $q->inRandomOrder()->limit(5)->get(),   // weekend / generic
        };
    }

    protected function kindToType(string $kind): string
    {
        return ['new' => 'launch', 'slow' => 'discount', 'overstock' => 'discount', 'weekend' => 'seasonal'][$kind] ?? 'promotion';
    }

    protected function aiPromo(string $kind, array $names): string
    {
        if (! (config('openai.api_key') ?: env('OPENAI_API_KEY'))) return '';
        $list  = $names ? implode(', ', $names) : 'our products';
        $angle = [
            'slow'      => 'move slow-selling stock with a friendly limited-time nudge',
            'overstock' => 'clear overstocked items with a value angle',
            'new'       => 'announce new arrivals with excitement',
            'weekend'   => 'a cheerful weekend special',
        ][$kind] ?? 'a friendly promotion';

        try {
            $resp = OpenAI::chat()->create([
                'model'       => env('OPENAI_MODEL', 'gpt-4o-mini'),
                'temperature' => 0.7,
                'max_tokens'  => 160,
                'messages'    => [
                    ['role' => 'system', 'content' => 'You write short, warm WhatsApp marketing messages for a Ugandan shop. One short paragraph, 1-2 emojis max, no hashtags, under 45 words. End naturally — a call to action is added separately.'],
                    ['role' => 'user', 'content' => "Write {$angle}. Featured products: {$list}."],
                ],
            ]);
            return trim((string) ($resp->choices[0]->message->content ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function cannedPromo(string $kind, array $names): string
    {
        $first = $names[0] ?? 'great deals';
        return match ($kind) {
            'new'       => "🆕 Just arrived! Fresh stock of {$first} and more is now in. Be the first to grab yours today.",
            'overstock' => "💛 Stock-clearing special! Great prices on {$first} and more — while stocks last.",
            'slow'      => "✨ This week only: special prices on {$first} and selected items. Don't miss out!",
            default     => "🎉 Weekend special! Treat yourself to {$first} and more — order today and we'll deliver.",
        };
    }

    /** Recent bot-pipeline events for this shop (powers /panel/diagnostics). */
    public function diagnostics(Request $r)
    {
        $tid  = $r->user()->tenant->id;
        $rows = \Illuminate\Support\Facades\DB::table('bot_events')
            ->where(function ($q) use ($tid) {
                $q->where('tenant_id', $tid)->orWhere('stage', 'no_tenant');
            })
            ->orderByDesc('id')->limit(80)->get()
            ->map(fn ($e) => [
                'time'   => (string) $e->created_at,
                'phone'  => $e->phone,
                'stage'  => $e->stage,
                'detail' => $e->detail,
                'ms'     => $e->ms,
                'trace'  => substr((string) $e->trace, -6),
            ])->values();

        return response()->json(['ok' => true, 'events' => $rows]);
    }

    // AI bot setup — owner describes the business in plain words, we generate the persona.
    public function botGenerate(Request $r)
    {
        $t     = $r->user()->tenant;
        $name  = trim((string) $r->input('business_name', $t->name)) ?: $t->name;
        $sells = trim((string) $r->input('sells', ''));
        $city  = trim((string) $r->input('city', ''));
        $tone  = trim((string) $r->input('tone', 'friendly')) ?: 'friendly';
        $deliv = trim((string) $r->input('delivery', ''));
        $extra = trim((string) $r->input('extra', ''));

        // No API key -> still useful: build a sensible template instead of failing.
        if (! (config('openai.api_key') ?: env('OPENAI_API_KEY'))) {
            $greeting = "Hello \u{1F44B} Welcome to {$name}!"
                . ($sells ? " We've got {$sells}." : '')
                . " Just type what you'd like and I'll add it up. Say *cart* to review, or *checkout* when you're ready \u{1F6D2}";
            return response()->json(['ok' => true, 'ai' => false, 'greeting' => $greeting, 'profile' => $sells]);
        }

        $sys = "You configure a WhatsApp ordering assistant for a small local shop. "
            . "Return JSON ONLY: {\"greeting\":\"...\",\"profile\":\"...\"}. "
            . "greeting = a warm 2-3 line WhatsApp welcome shown when a customer says hi; use the business name; mention what they sell; tell them they can just type what they want, say 'cart' to review and 'checkout' to finish; include 1-2 emojis; no markdown headings. "
            . "profile = one short paragraph describing the business, for internal reference. Tone: {$tone}.";
        $usr = "Business name: {$name}\nSells: {$sells}\nCity: {$city}\nDelivery: {$deliv}\nExtra notes: {$extra}";

        try {
            $resp = OpenAI::chat()->create([
                'model'       => env('OPENAI_MODEL', 'gpt-4o-mini'),
                'temperature' => 0.6,
                'messages'    => [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $usr],
                ],
            ]);
            $c = trim(preg_replace('/```json|```/', '', $resp->choices[0]->message->content ?? ''));
            $d = json_decode($c, true);
            return response()->json([
                'ok'       => true,
                'ai'       => true,
                'greeting' => (string) ($d['greeting'] ?? ''),
                'profile'  => (string) ($d['profile'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'generate_failed', 'detail' => $e->getMessage()], 502);
        }
    }

    public function botSave(Request $r)
    {
        $t = $r->user()->tenant;
        $s = $t->settings ?? [];
        $s['bot_greeting']     = trim((string) $r->input('greeting', ''));
        $s['business_profile'] = trim((string) $r->input('profile', ''));
        $t->settings = $s;
        $t->save();
        return response()->json(['ok' => true]);
    }

    /* -------------------------------------------------- dispatch + riders (3b) */
    public function dispatch(Request $r, WhatsAppManager $wa)
    {
        if ($d = $this->planDeny($r, 'dispatch')) return $d;
        $o = Order::find((int) $r->query('row'));
        if (! $o) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $rn = trim((string) $r->query('rider', ''));
        $rp = preg_replace('/[^0-9]/', '', (string) $r->query('riderphone', ''));
        if ($rn === '') {
            return response()->json(['ok' => false, 'error' => 'rider_required'], 422);
        }

        // Find-or-create the rider for this tenant, keep phone fresh.
        $rider = Rider::where('name', $rn)->first();
        if (! $rider) {
            $rider = Rider::create(['name' => $rn, 'phone' => $rp, 'active' => true]);
        } elseif ($rp !== '' && $rider->phone !== $rp) {
            $rider->phone = $rp;
            $rider->save();
        }

        $o->rider_id = $rider->id;
        if (empty($o->track_token)) {
            $o->track_token = \Illuminate\Support\Str::random(12);
        }
        $o->status = 'Out for delivery';   // OrderObserver -> WhatsApp "on the way" + logs it
        $o->save();

        $tenant = \App\Models\Tenant::find($o->tenant_id);
        return response()->json([
            'ok'    => true,
            'track' => $tenant
                ? $tenant->publicUrl('/papi/track?o=' . $o->id . '&t=' . $o->track_token)
                : url('/papi/track?o=' . $o->id . '&t=' . $o->track_token),
        ]);
    }

    public function riderSave(Request $r)
    {
        if ($d = $this->planDeny($r, 'dispatch')) return $d;
        $name = trim((string) $r->query('name', ''));
        if ($name === '') {
            return response()->json(['ok' => false, 'error' => 'name_required'], 422);
        }
        $id = $r->query('id');
        $rider = $id ? Rider::find((int) $id) : null;
        if (! $rider) $rider = new Rider();

        $rider->name    = $name;
        $rider->phone   = preg_replace('/[^0-9]/', '', (string) $r->query('phone', ''));
        $rider->active  = $r->query('active', 'true') === 'true';
        $rider->city    = (string) $r->query('city', '');
        $rider->dob     = $r->query('dob') ?: null;
        $rider->address = (string) $r->query('address', '');
        if ($r->has('photo')) $rider->photo = trim((string) $r->query('photo', ''));

        $profile = [];
        foreach (['license_no', 'nid_no', 'doc_url', 'bank_name', 'account_name', 'bank_account', 'pay_notes', 'pay_type', 'comm_pct', 'comm_min', 'comm_max'] as $k) {
            if ($r->filled($k)) $profile[$k] = (string) $r->query($k);
        }
        $rider->profile = $profile ?: null;
        $rider->save();

        return response()->json(['ok' => true, 'riders' => $this->ridersList()]);
    }

    public function riderDel(Request $r)
    {
        if ($d = $this->planDeny($r, 'dispatch')) return $d;
        $rider = Rider::find((int) $r->query('id', 0));
        if ($rider) $rider->delete();
        return response()->json(['ok' => true, 'riders' => $this->ridersList()]);
    }

    /**
     * Save brand-site / branding settings from the seller panel. POST JSON (the FAQ + brand cards
     * are too large/structured for a query string). Each field is optional; only provided keys change.
     */
    public function brandingSave(Request $r)
    {
        if (! $r->user()->canMenu('settings')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t = $r->user()->tenant;
        $s = $t->settings ?? [];

        $hex = function ($v) {
            $v = trim((string) $v);
            return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : null;
        };
        $darken = function ($hex, $f = 0.82) {
            $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
            return sprintf('#%02X%02X%02X', (int) round($r * $f), (int) round($g * $f), (int) round($b * $f));
        };

        if ($r->has('theme_accent')) {
            $a = $hex($r->input('theme_accent'));
            if ($a) { $s['theme_accent'] = $a; $s['theme_accent_dark'] = $hex($r->input('theme_accent_dark')) ?: $darken($a); }
            elseif (trim((string) $r->input('theme_accent')) === '') { unset($s['theme_accent'], $s['theme_accent_dark']); }
        }
        foreach (['hero_image', 'factory_image', 'eyebrow', 'trust_line', 'website', 'tagline', 'hero_title', 'hero_text', 'meta_description', 'ai_persona', 'brand_knowledge'] as $k) {
            if ($r->has($k)) $s[$k] = trim((string) $r->input($k));
        }
        if ($r->has('faq')) {
            $faq = $r->input('faq');
            if (is_string($faq)) $faq = json_decode($faq, true);
            $s['faq'] = collect(is_array($faq) ? $faq : [])->map(fn ($x) => [
                'q' => trim((string) ($x['q'] ?? '')), 'a' => trim((string) ($x['a'] ?? '')),
            ])->filter(fn ($x) => $x['q'] !== '' || $x['a'] !== '')->values()->all();
        }
        if ($r->has('brands')) {
            $br = $r->input('brands');
            if (is_string($br)) $br = json_decode($br, true);
            $s['brands'] = collect(is_array($br) ? $br : [])->map(function ($b) use ($hex) {
                return array_filter([
                    'name'  => trim((string) ($b['name'] ?? '')),
                    'tag'   => trim((string) ($b['tag'] ?? '')),
                    'color' => $hex($b['color'] ?? '') ?: null,
                    'items' => array_values(array_filter(array_map('trim', (array) ($b['items'] ?? [])))),
                    'chips' => array_values(array_filter(array_map('trim', (array) ($b['chips'] ?? [])))),
                ], fn ($v) => $v !== null && $v !== '' && $v !== []);
            })->filter(fn ($b) => ! empty($b['name']))->values()->all();
        }
        if ($r->has('combos')) {
            $cb = $r->input('combos');
            if (is_string($cb)) $cb = json_decode($cb, true);
            $s['combos'] = \App\Support\Combos::normalize(is_array($cb) ? $cb : []);
        }
        if ($r->has('category_groups')) {
            $cg = $r->input('category_groups');
            if (is_string($cg)) $cg = json_decode($cg, true);
            $groups = [];
            foreach ((array) (is_array($cg) ? $cg : []) as $name => $cats) {
                $name = trim((string) $name);
                $cats = array_values(array_filter(array_map('trim', (array) $cats)));
                if ($name !== '' && $cats) $groups[$name] = $cats;
            }
            $s['category_groups'] = $groups;
        }
        if ($r->has('menu_files')) {
            $mf = $r->input('menu_files');
            if (is_string($mf)) $mf = json_decode($mf, true);
            $s['menu_files'] = collect(is_array($mf) ? $mf : [])->map(fn ($m) => [
                'label' => trim((string) ($m['label'] ?? '')),
                'url'   => trim((string) ($m['url'] ?? '')),
            ])->filter(fn ($m) => $m['label'] !== '' && $m['url'] !== '')->values()->all();
        }
        if ($r->has('catalog_files')) {
            $cf = $r->input('catalog_files');
            if (is_string($cf)) $cf = json_decode($cf, true);
            $s['catalog_files'] = collect(is_array($cf) ? $cf : [])->map(fn ($m) => [
                'label' => trim((string) ($m['label'] ?? '')),
                'url'   => trim((string) ($m['url'] ?? '')),
            ])->filter(fn ($m) => $m['label'] !== '' && $m['url'] !== '')->values()->all();
        }

        $t->settings = $s;
        $t->save();
        return response()->json(['ok' => true, 'settings' => $s]);
    }

    /* -------------------------------------------------- settings / config (3b) */
    /** POST /papi/order-system?on=1  — owner/manager turns the Order Trading system on/off for
     *  this tenant. This drives the sidebar section AND the money/purchase endpoints' 404 guard. */
    public function orderSystemToggle(Request $r)
    {
        $u = $r->user();
        if (! ($u->isOwnerLike() || in_array((string) $u->role, ['manager'], true))) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $u->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $on = filter_var($r->input('on', $r->query('on', true)), FILTER_VALIDATE_BOOLEAN);
        $s = $t->settings ?? [];
        $s['order_system_enabled'] = $on;
        $t->settings = $s;              // reassign whole array (it's an array cast)
        $t->save();
        return response()->json(['ok' => true, 'order_system_enabled' => $on]);
    }

    public function settingsSave(Request $r)
    {
        if (! $r->user()->canMenu('settings')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t = $r->user()->tenant;
        $s = $t->settings ?? [];
        foreach (['storeName', 'storePhone', 'storeAddress', 'storeEmail', 'base', 'perKm', 'min', 'round', 'freeOver', 'lat', 'lng', 'inventoryMode', 'usdUgx', 'usdSsp', 'ssMarkupPct', 'onboarded'] as $k) {
            if ($r->has($k)) $s[$k] = $r->query($k);
        }
        if ($r->has('logo')) $s['logo'] = trim((string) $r->query('logo'));
        $s['address'] = (string) $r->query('storeAddress', $s['address'] ?? '');
        $s['email']   = (string) $r->query('storeEmail', $s['email'] ?? '');
        if ($r->filled('storeName'))  $t->name = (string) $r->query('storeName');
        if ($r->filled('storePhone')) $t->whatsapp_number = preg_replace('/[^0-9+]/', '', (string) $r->query('storePhone'));

        if ($r->has('customDomain')) {
            $d = strtolower(trim((string) $r->query('customDomain')));
            $d = preg_replace('#^https?://#', '', $d);   // drop scheme
            $d = preg_replace('#/.*$#', '', $d);          // drop any path
            $d = preg_replace('/^www\./', '', $d);        // normalise www.
            $d = trim($d);
            if ($d === '') {
                $t->custom_domain = null;
            } elseif (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d)) {
                return response()->json(['ok' => false, 'error' => 'Enter a valid domain like palssnack.com'], 422);
            } elseif (\App\Models\Tenant::where('custom_domain', $d)->where('id', '!=', $t->id)->exists()) {
                return response()->json(['ok' => false, 'error' => 'That domain is already used by another shop.'], 422);
            } else {
                $t->custom_domain = $d;
            }
        }

        $t->settings = $s;
        $t->save();
        return response()->json([
            'ok'           => true,
            'settings'     => $s,
            'slug'         => (string) $t->slug,
            'customDomain' => (string) ($t->custom_domain ?? ''),
        ]);
    }

    /**
     * Instant on/off toggle for a whitelisted bot behaviour setting. Saved immediately
     * (no "Save settings" needed) and takes effect on the customer's next message.
     *   combo_recommendations  → "Goes well with" caption + "Often bought together" + checkout combos
     *   send_product_images    → product photos sent by the bot
     */
    public function botToggle(Request $r)
    {
        $t   = $r->user()->tenant;
        $key = (string) $r->query('key', '');
        if (! in_array($key, ['combo_recommendations', 'send_product_images', 'reengage_followup', 'intro_catalog', 'credit_reminder'], true)) {
            return response()->json(['ok' => false, 'error' => 'bad_key'], 422);
        }
        $on = in_array((string) $r->query('on', '1'), ['1', 'true', 'on', 'yes'], true);
        if ($key === 'reengage_followup' || $key === 'intro_catalog' || $key === 'credit_reminder') {
            $cfg = (array) $t->setting($key, []);
            $cfg['enabled'] = $on;
            $t->putSetting($key, $cfg);
        } else {
            $t->putSetting($key, $on);
        }

        return response()->json(['ok' => true, 'key' => $key, 'on' => $on]);
    }

    /** Save the lead-follow-up config (delay / link / warm-lead). Enable/disable is via botToggle. */
    public function reengageSave(Request $r)
    {
        if (! $r->user()->canMenu('settings')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t   = $r->user()->tenant;
        $cfg = (array) $t->setting('reengage_followup', []);
        if ($r->has('enabled'))      $cfg['enabled']      = in_array((string) $r->query('enabled'), ['1', 'true', 'on', 'yes'], true);
        if ($r->has('delay_min'))    $cfg['delay_min']    = max(1, (int) $r->query('delay_min'));
        if ($r->has('max_min'))      $cfg['max_min']      = max(2, (int) $r->query('max_min'));
        if ($r->has('require_lead')) $cfg['require_lead'] = in_array((string) $r->query('require_lead'), ['1', 'true', 'on', 'yes'], true);
        if ($r->has('link'))         $cfg['link']         = trim((string) $r->query('link'));
        if ($r->has('message'))      $cfg['message']      = trim((string) $r->query('message'));
        $t->putSetting('reengage_followup', $cfg);
        return response()->json(['ok' => true, 'reengage' => $cfg]);
    }

    /** Save the credit-reminder config (days + message). Enable/disable is via botToggle. */
    public function creditReminderSave(Request $r)
    {
        if (! $r->user()->canMenu('settings')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t   = $r->user()->tenant;
        $cfg = (array) $t->setting('credit_reminder', []);
        if ($r->has('enabled')) $cfg['enabled'] = in_array((string) $r->query('enabled'), ['1', 'true', 'on', 'yes'], true);
        if ($r->has('days')) {
            // Keep only sane day offsets (1..365), normalised to a clean comma list.
            $days = [];
            foreach (preg_split('/[,\s]+/', (string) $r->query('days'), -1, PREG_SPLIT_NO_EMPTY) as $p) {
                $n = (int) $p;
                if ($n >= 1 && $n <= 365) $days[$n] = $n;
            }
            ksort($days);
            $cfg['days'] = implode(',', array_values($days));
        }
        if ($r->has('message')) $cfg['message'] = trim((string) $r->query('message'));
        $t->putSetting('credit_reminder', $cfg);
        return response()->json(['ok' => true, 'creditReminder' => [
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'days'    => (string) ($cfg['days'] ?? ''),
            'message' => (string) ($cfg['message'] ?? ''),
        ]]);
    }

    /* ------------------------------------------ Activity Review Inbox (v17) */
    public function activityInbox(Request $r)
    {
        $items = app(\App\Services\Bot\Offers\ReviewQueueService::class)->pending($r->user()->tenant, 100);
        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function activityApprove(Request $r)
    {
        $ok = app(\App\Services\Bot\Offers\ReviewQueueService::class)
            ->approve($r->user()->tenant, (int) $r->query('id', 0), (string) ($r->user()->name ?? 'owner'));
        return response()->json(['ok' => $ok]);
    }

    public function activityReject(Request $r)
    {
        $ok = app(\App\Services\Bot\Offers\ReviewQueueService::class)
            ->reject($r->user()->tenant, (int) $r->query('id', 0), (string) ($r->user()->name ?? 'owner'));
        return response()->json(['ok' => $ok]);
    }

    public function activityEdit(Request $r)
    {
        $edits = [];
        foreach (['event', 'item'] as $k) {
            if ($r->query($k, null) !== null) $edits[$k] = (string) $r->query($k);
        }
        foreach (['qty', 'price'] as $k) {
            if ($r->query($k, null) !== null && $r->query($k) !== '') $edits[$k] = (int) $r->query($k);
        }
        $ok = app(\App\Services\Bot\Offers\ReviewQueueService::class)
            ->edit($r->user()->tenant, (int) $r->query('id', 0), $edits, (string) ($r->user()->name ?? 'owner'));
        return response()->json(['ok' => $ok]);
    }

    /**
     * Business Brain — one payload powering the whole section: readiness, Business DNA,
     * review queue, today's activity, and an AI-performance snapshot. Every block is guarded so a
     * tenant that hasn't run discovery yet still gets a usable (mostly-null) response.
     */
    public function brainData(Request $r)
    {
        try {
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);

        $readiness = null;
        try {
            $g = \App\Models\GoLiveReport::where('tenant_id', $t->id)->orderByDesc('id')->first();
            if ($g) {
                $readiness = [
                    'overall' => (int) $g->overall_score,
                    'mode'    => (string) $g->recommended_mode,
                    'classification' => (string) $g->classification,
                    'categories' => $g->category_scores ?? [],
                ];
            }
        } catch (\Throwable $e) {}

        $dna = null; $productsDiscovered = 0; $faqsDiscovered = 0;
        try {
            $d = \App\Models\BusinessDiscovery::where('tenant_id', $t->id)->orderByDesc('id')->first();
            if ($d && is_array($d->report)) {
                $sec = $d->report['sections'] ?? [];
                $productsDiscovered = count($sec['top_products'] ?? []);
                $faqsDiscovered = count($sec['faqs'] ?? []);
                $dna = [
                    'languages' => array_map(fn ($l) => ['lang' => $l['lang'] ?? '', 'pct' => $l['pct'] ?? 0], array_slice($sec['languages'] ?? [], 0, 5)),
                    'products'  => array_map(fn ($p) => $p['name'] ?? '', array_slice($sec['top_products'] ?? [], 0, 6)),
                    'faqs'      => array_map(fn ($f) => $f['label'] ?? ($f['topic'] ?? ''), array_slice($sec['faqs'] ?? [], 0, 6)),
                    'delivery'  => $sec['delivery'] ?? [],
                    'hours'     => $sec['hours']['text'] ?? null,
                    'style'     => $sec['owner_style']['tone'] ?? null,
                    'sales'     => array_map(fn ($b) => [
                        'label'   => $b['label'] ?? '',
                        'count'   => $b['count'] ?? 0,
                        'example' => $b['examples'][0]['response'] ?? '',
                    ], array_values($sec['sales_patterns']['by_type'] ?? [])),
                    'sales_questions' => $sec['sales_patterns']['questions'] ?? [],
                    'readiness_band' => $d->report['readiness_band'] ?? null,
                    'scanned_messages' => (int) $d->sample_messages,
                ];
            }
        } catch (\Throwable $e) {}

        $reviews = ['count' => 0, 'items' => []];
        try {
            $items = app(\App\Services\Bot\Offers\ReviewQueueService::class)->pending($t, 100);
            $reviews = ['count' => count($items), 'items' => array_slice($items, 0, 5)];
        } catch (\Throwable $e) {}

        // Mined-but-unmatched product candidates the owner can approve into the catalogue.
        $candidates = ['count' => 0, 'items' => []];
        try {
            $c = app(\App\Services\Bot\Discovery\ProductCandidateService::class)->list($t, 20);
            $candidates = ['count' => (int) $c['count'], 'items' => array_slice($c['items'], 0, 8)];
        } catch (\Throwable $e) {}

        $activity = [];
        try {
            $activity = \App\Models\ActivityFeedItem::where('tenant_id', $t->id)
                ->orderByDesc('id')->limit(8)->get()
                ->map(fn ($a) => [
                    'source'  => (string) $a->source,
                    'event'   => (string) $a->event_type,
                    'content' => mb_substr((string) ($a->raw_content ?? ''), 0, 80),
                    'at'      => optional($a->created_at)->diffForHumans(),
                ])->all();
        } catch (\Throwable $e) {}

        // AI performance proxy from conversations active yesterday: escalated = handed to a human.
        $perf = ['conversations' => 0, 'solved' => 0, 'escalated' => 0, 'success' => 0];
        try {
            $from = now()->subDay()->startOfDay();
            $to   = now()->subDay()->endOfDay();
            $q = \App\Models\Conversation::where('tenant_id', $t->id)
                ->whereBetween('last_message_at', [$from, $to]);
            $total = (clone $q)->count();
            $esc   = (clone $q)->where('agent_active', true)->count();
            $solved = max(0, $total - $esc);
            $perf = [
                'conversations' => $total,
                'solved'        => $solved,
                'escalated'     => $esc,
                'success'       => $total > 0 ? (int) round($solved / $total * 100) : 0,
            ];
        } catch (\Throwable $e) {}

        $team = null; $conflicts = [];
        try {
            $cd = \App\Models\CompanyDna::where('tenant_id', $t->id)->orderByDesc('id')->first();
            if ($cd && is_array($cd->snapshot)) {
                $s = $cd->snapshot;
                $team = [
                    'employee_count'    => (int) $cd->employee_count,
                    'employees'         => $s['employees'] ?? [],
                    'messages_analyzed' => (int) $cd->messages_analyzed,
                    'languages'         => $s['languages'] ?? [],
                    'styles'            => $s['styles'] ?? [],
                    'common_topics'     => $s['common_topics'] ?? [],
                ];
                $conflicts = $s['conflicts'] ?? [];
            }
        } catch (\Throwable $e) {}

        return response()->json([
            'ok' => true,
            'readiness' => $readiness,
            'dna' => $dna,
            'reviews' => $reviews,
            'candidates' => $candidates,
            'activity' => $activity,
            'performance' => $perf,
            'team' => $team,
            'conflicts' => $conflicts,
            'discovered' => ['products' => $productsDiscovered, 'faqs' => $faqsDiscovered],
            'has_discovery' => $dna !== null,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('brainData failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['ok' => false, 'has_discovery' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Discovery Wizard — run a scan synchronously and return per-section counts so the panel can
     * reveal "Step n/6 ✓ N discovered" with real numbers. Heavier than the async job; only fired
     * when the owner taps "Run discovery".
     */
    public function brainDiscover(Request $r)
    {
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);
        try {
            // Phase 1 — fast 30-day onboarding scan (keeps the wizard quick).
            $d = app(\App\Services\Bot\Discovery\DiscoveryScanner::class)->scan($t, 5000, 30);
            // also refresh multi-employee consensus so Team Insights & Conflicts populate
            try { app(\App\Services\Bot\Company\CompanyDiscoveryService::class)->discover($t, true); } catch (\Throwable $e) {}
            // generate the Go-Live readiness report so the Overview shows a score immediately
            try { app(\App\Services\Bot\Readiness\ReadinessService::class)->evaluate($t); } catch (\Throwable $e) {}
            // Phase 2 — widen the window in the background (90/180/365), non-blocking.
            try { \App\Jobs\ProgressiveDiscovery::dispatch($t->id, 0)->delay(now()->addMinutes(20)); } catch (\Throwable $e) {}
            $sec = is_array($d->report) ? ($d->report['sections'] ?? []) : [];
            $deliveryRules = count($sec['delivery']['areas'] ?? [])
                + (! empty($sec['delivery']['fee']) ? 1 : 0)
                + (! empty($sec['delivery']['free_threshold']) ? 1 : 0);
            return response()->json([
                'ok' => true,
                'steps' => [
                    ['label' => 'Analyzing Products',       'count' => count($sec['top_products'] ?? []), 'noun' => 'products'],
                    ['label' => 'Analyzing FAQs',           'count' => count($sec['faqs'] ?? []),        'noun' => 'FAQs'],
                    ['label' => 'Analyzing Delivery Rules', 'count' => $deliveryRules,                    'noun' => 'delivery rules'],
                    ['label' => 'Analyzing Offers',         'count' => count($sec['promotions'] ?? []),  'noun' => 'offers'],
                    ['label' => 'Detecting Languages',      'count' => count($sec['languages'] ?? []),   'noun' => 'languages'],
                    ['label' => 'Scoring Readiness',        'count' => (int) $d->readiness,               'noun' => '% ready', 'is_pct' => true],
                ],
                'readiness' => (int) $d->readiness,
                'messages'  => (int) $d->sample_messages,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('brainDiscover failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['ok' => false, 'error' => 'Discovery failed: ' . $e->getMessage()], 200);
        }
    }

    /* ------------------------------------------ Business Brain — Product Candidates */

    /** Mined-but-unmatched product terms the owner can approve into the catalogue. */
    public function brainCandidates(Request $r)
    {
        try {
            $t = $r->user()->tenant;
            app(\App\Support\TenantContext::class)->set($t->id);
            $c = app(\App\Services\Bot\Discovery\ProductCandidateService::class)->list($t, 20);
            return response()->json(['ok' => true, 'count' => $c['count'], 'items' => $c['items']]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('brainCandidates failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'count' => 0, 'items' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Approve a candidate → create a DRAFT product (owner sets a price to make it live). */
    public function brainCandidateApprove(Request $r)
    {
        try {
            $t = $r->user()->tenant;
            app(\App\Support\TenantContext::class)->set($t->id);
            $term = (string) $r->query('term', '');
            $res = app(\App\Services\Bot\Discovery\ProductCandidateService::class)
                ->approve($t, $term, (string) ($r->user()->name ?? 'owner'));
            return response()->json($res);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('brainCandidateApprove failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Dismiss a candidate so it never re-surfaces. */
    public function brainCandidateDismiss(Request $r)
    {
        try {
            $t = $r->user()->tenant;
            app(\App\Support\TenantContext::class)->set($t->id);
            $term = (string) $r->query('term', '');
            $res = app(\App\Services\Bot\Discovery\ProductCandidateService::class)
                ->dismiss($t, $term, (string) ($r->user()->name ?? 'owner'));
            return response()->json($res);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('brainCandidateDismiss failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function botConfigSave(Request $r)
    {
        $t = $r->user()->tenant;
        $s = $t->settings ?? [];
        foreach (['currency', 'usdUgx', 'usdSsp', 'discountPct', 'discountAmt'] as $k) {
            if ($r->has($k)) $s[$k] = $r->query($k);
        }
        $s['showSwitcher'] = ($r->query('showSwitcher', '0') === '1');
        $t->settings = $s;
        $t->save();
        return response()->json(['ok' => true]);
    }

    /* -------------------------------------------------- branches (3b) */
    public function branchSave(Request $r)
    {
        if ($d = $this->planDeny($r, 'pos')) return $d;
        $name = trim((string) $r->query('name', ''));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'name_required'], 422);
        $id = $r->query('id');
        $b = $id ? Branch::find((int) $id) : null;
        if (! $b) $b = new Branch();
        $b->name    = $name;
        $b->phone   = (string) $r->query('phone', '');
        $b->address = (string) $r->query('address', '');
        $b->lat     = is_numeric($r->query('lat')) ? (float) $r->query('lat') : null;
        $b->lng     = is_numeric($r->query('lng')) ? (float) $r->query('lng') : null;
        $b->save();
        return response()->json(['ok' => true, 'branches' => $this->branchesList()]);
    }

    public function branchDel(Request $r)
    {
        if ($d = $this->planDeny($r, 'pos')) return $d;
        $b = Branch::find((int) $r->query('id', 0));
        if ($b) $b->delete();
        return response()->json(['ok' => true, 'branches' => $this->branchesList()]);
    }

    /* -------------------------------------------------- customers (3b) */
    public function customerSave(Request $r)
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $r->query('phone', ''));
        if ($phone === '') return response()->json(['ok' => false, 'error' => 'phone_required'], 422);
        $c = CustomerProfile::firstOrNew(['phone' => $phone]);
        $c->name      = (string) $r->query('name', '');
        $c->alt_phone = (string) $r->query('alt_phone', '');
        $c->email     = (string) $r->query('email', '');
        $c->address   = (string) $r->query('address', '');
        $c->lang      = (string) $r->query('lang', '');
        $c->greeting  = (string) $r->query('greeting', '');
        $c->notes     = (string) $r->query('notes', '');
        if ($r->has('ss_markup_pct')) {
            $v = trim((string) $r->query('ss_markup_pct', ''));
            $c->ss_markup_pct = ($v === '') ? null : (float) $v;
        }
        $c->save();
        // The panel says editing the name updates it on their orders too.
        if (trim((string) $c->name) !== '') {
            Order::where('customer_phone', $phone)->update(['customer_name' => $c->name]);
        }
        return response()->json(['ok' => true]);
    }

    /* -------------------------------------------------- returns / refunds (3b) */
    public function returnSave(Request $r)
    {
        if ($d = $this->planDeny($r, 'returns')) return $d;
        $o      = Order::find((int) $r->query('row'));
        $phone  = preg_replace('/[^0-9]/', '', (string) $r->query('phone', ''));
        $res    = (string) $r->query('resolution', 'adjust');
        $amount = (float) $r->query('amount', 0);

        ReturnRecord::create([
            'order_id'       => $o?->id,
            'customer_phone' => $phone,
            'customer_name'  => (string) $r->query('name', ''),
            'items_text'     => (string) $r->query('items', ''),
            'amount'         => $amount,
            'resolution'     => $res,
            'reason'         => (string) $r->query('reason', ''),
        ]);

        // adjust / redeem reduce the order's outstanding total
        if ($r->filled('newtotal') && $o) {
            $o->total = (float) $r->query('newtotal');
            $o->save();
        }

        return response()->json(['ok' => true, 'credit' => (object) $this->creditMap()]);
    }

    /* -------------------------------------------------- writes pending (3b) */
    // Return ok:false so the panel shows its honest "saved on this device only"
    // fallback rather than silently dropping data. Wired for real in Phase 3b.
    public function pending()
    {
        return response()->json(['ok' => false, 'pending' => true]);
    }

    /**
     * First-login setup wizard save (POST /papi/onboard-save). Owner-only.
     * Writes the business profile + locale into tenant settings and marks onboarding done.
     */
    public function onboardSave(Request $r)
    {
        $user = $r->user();
        if (! $user || ! $user->tenant_id) {
            return response()->json(['ok' => false, 'error' => 'Not signed in.'], 401);
        }
        if (method_exists($user, 'isOwnerLike') && ! $user->isOwnerLike()) {
            return response()->json(['ok' => false, 'error' => 'Only the owner can run setup.'], 403);
        }
        $t = $user->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'No business found.'], 404);
        }

        $data    = $r->json()->all() ?: $r->all();
        $skipped = (bool) ($data['skipped'] ?? false);

        $clean = function ($v, int $max = 120): string {
            return trim(mb_substr(preg_replace('/[\x00-\x1F<>]/u', '', (string) $v), 0, $max));
        };

        if (! $skipped) {
            $name = $clean($data['name'] ?? '');
            if ($name !== '') {
                $t->name = $name;
            }
            $wa = $clean($data['whatsapp'] ?? '', 32);
            if ($wa !== '') {
                $t->whatsapp_number = $wa;
            }
            $t->save();

            $kind = $clean($data['bot_kind'] ?? '', 24);
            if ($kind !== '') $t->putSetting('bot_kind', $kind);

            $country = $clean($data['country'] ?? '');
            if ($country !== '') $t->putSetting('country', $country);
            $cc = strtoupper($clean($data['country_code'] ?? '', 2));
            if ($cc !== '') $t->putSetting('country_code', $cc);

            $city = $clean($data['city'] ?? '');
            if ($city !== '') $t->putSetting('city', $city);

            $tz = $clean($data['timezone'] ?? '', 48);
            if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
                $t->putSetting('timezone', $tz);
            }

            $cur = strtoupper($clean($data['currency'] ?? '', 8));
            if ($cur !== '') $t->putSetting('currency', $cur);

            $basis = strtolower($clean($data['currency_basis'] ?? '', 16));
            $basis = in_array($basis, ['native', 'converted'], true) ? $basis : 'native';
            $t->putSetting('currency_basis', $basis);
            // Native pricing must not trigger the UGX-base / ex-works USD conversion logic.
            if ($basis === 'native') {
                $t->putSetting('usdUgx', 0);
            }

            $langs = $data['languages'] ?? [];
            if (is_string($langs)) $langs = array_map('trim', explode(',', $langs));
            $langs = array_values(array_filter(array_map(fn ($l) => $clean($l, 32), (array) $langs)));
            $t->putSetting('languages', array_slice($langs, 0, 8));

            $areas = $data['delivery_areas'] ?? [];
            if (is_string($areas)) $areas = array_map('trim', explode(',', $areas));
            $areas = array_values(array_filter(array_map(fn ($a) => $clean($a, 60), (array) $areas)));
            if ($areas) $t->putSetting('delivery_areas', array_slice($areas, 0, 50));

            $moqMode = strtolower($clean($data['moq_mode'] ?? '', 16));
            $moqMode = in_array($moqMode, ['off', 'roundup'], true) ? $moqMode : 'off';
            $t->putSetting('moq_mode', $moqMode);
        }

        $t->putSetting('onboarded', true);
        $t->putSetting('onboarded_at', now()->toIso8601String());

        return response()->json(['ok' => true]);
    }

    /**
     * In-app AI help assistant (POST /papi/help-ask). Answers "how do I use this system" questions,
     * grounded in the CloudBSS how-to knowledge. Uses the GLOBAL CloudBSS OpenAI key (support is on
     * us, not billed to the tenant). Degrades gracefully if the model is unavailable.
     */
    public function helpAsk(Request $r)
    {
        $user = $r->user();
        if (! $user || ! $user->tenant_id) {
            return response()->json(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $data = $r->json()->all() ?: $r->all();
        $msg  = trim((string) ($data['message'] ?? ''));
        if ($msg === '') {
            return response()->json(['ok' => false, 'error' => 'Empty question.'], 422);
        }
        $msg = mb_substr($msg, 0, 1000);

        // last few turns only (cost + focus). Expect [{role:'user'|'assistant', content:'…'}, …]
        $history = [];
        foreach (array_slice((array) ($data['history'] ?? []), -6) as $h) {
            $role = (($h['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $c    = trim((string) ($h['content'] ?? ''));
            if ($c !== '') $history[] = ['role' => $role, 'content' => mb_substr($c, 0, 1000)];
        }

        $system   = \App\Support\HelpKnowledge::systemPrompt($user->tenant, (string) ($user->role ?? ''));
        $messages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $history,
            [['role' => 'user', 'content' => $msg]]
        );

        try {
            $model = (string) env('OPENAI_MODEL', 'gpt-4o-mini');
            $resp  = OpenAI::getFacadeRoot()->chat()->create([
                'model'       => $model,
                'temperature' => 0.2,
                'messages'    => $messages,
            ]);
            $reply = trim((string) ($resp->choices[0]->message->content ?? ''));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('helpAsk failed: ' . $e->getMessage());
            return response()->json([
                'ok'       => true,
                'degraded' => true,
                'reply'    => "I can't reach the help assistant right now. For most tasks: use the left menu — Products to add or import items, Orders to see orders, Chats for WhatsApp, and Settings for currency, bot and staff. If you're stuck, contact DishNet/CloudBSS support.",
            ]);
        }

        if ($reply === '') {
            $reply = "I'm not sure about that one — try rephrasing, or contact DishNet/CloudBSS support.";
        }
        return response()->json(['ok' => true, 'reply' => $reply]);
    }

    /* ============================ platform diagnostics (owner-only) ============================ */

    private function ownerGate(Request $r)
    {
        $u = $r->user();
        if (! $u || ! method_exists($u, 'isOwnerLike') || ! $u->isOwnerLike()) {
            return response()->json(['ok' => false, 'error' => 'Owner access required.'], 403);
        }
        return null;
    }

    /**
     * POST /papi/resync-balances — recompute every order's amount_paid and every invoice's balance
     * from the ONE payment ledger (settlement CustomerPayments). Idempotent + safe: it only rewrites
     * denormalised paid/balance fields to match the ledger, fixing drift left by older payment paths
     * (e.g. an order that stayed "Paid $450" from when pay_unify was off). Owner-only. Reports what changed.
     */
    public function resyncBalances(Request $r)
    {
        if ($g = $this->ownerGate($r)) return $g;
        $t = $r->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);

        $fx   = \App\Services\Money\Fx::forTenant($t);
        $base = \App\Services\Money\SalesBaseCurrency::baseCurrency($t);

        $orderChanges = [];
        foreach (\App\Models\Order::where('tenant_id', $t->id)->get() as $o) {
            $oc = \App\Services\Money\SalesBaseCurrency::txnCurrency($o, $t);
            $paid = 0.0;
            foreach (\App\Models\CustomerPayment::where('tenant_id', $t->id)->where('order_no', $o->order_no)
                        ->where('kind', 'settlement')->where('applied', true)->get() as $p) {
                $paid += strtoupper((string) $p->currency) === $oc ? (float) $p->amount
                       : ($oc === $base ? (float) $p->usd_amount : (float) $p->usd_amount * $fx->ratePerUsd($oc));
            }
            $paid = round($paid, 2);
            if (abs($paid - (float) $o->amount_paid) >= 0.01) {
                $orderChanges[] = ['order' => (string) $o->order_no, 'was' => (float) $o->amount_paid, 'now' => $paid];
                $o->amount_paid = $paid; $o->save();
            }
        }

        $invChanges = [];
        $svc = app(\App\Services\Invoicing\InvoiceService::class);
        foreach (\App\Models\Invoice::where('tenant_id', $t->id)->where('status', '!=', 'void')->get() as $inv) {
            $before = (float) $inv->balance;
            try { $svc->syncPaidFromPayments($inv); $inv->refresh(); } catch (\Throwable $e) { continue; }
            if (abs((float) $inv->balance - $before) >= 0.01) {
                $invChanges[] = ['invoice' => (string) $inv->invoice_no, 'was' => $before,
                    'now' => (float) $inv->balance, 'status' => (string) $inv->status];
            }
        }

        return response()->json([
            'ok' => true,
            'orders_resynced'   => count($orderChanges),
            'invoices_resynced' => count($invChanges),
            'order_changes'     => array_slice($orderChanges, 0, 50),
            'invoice_changes'   => array_slice($invChanges, 0, 50),
        ]);
    }

    /**
     * Platform diagnostics (GET /papi/diagnostics) — build id, opcache/deploy staleness,
     * and core service checks. The opcache "any_stale" flag answers "is my deploy actually live?"
     */
    public function platformDiag(Request $r)
    {
        if ($g = $this->ownerGate($r)) return $g;

        $checks = [];

        // DB
        try { \Illuminate\Support\Facades\DB::select('select 1'); $checks['database'] = ['ok' => true]; }
        catch (\Throwable $e) { $checks['database'] = ['ok' => false, 'error' => $e->getMessage()]; }

        // Cache read/write
        try {
            \Illuminate\Support\Facades\Cache::put('diag:ping', 1, 5);
            $checks['cache'] = ['ok' => ((int) \Illuminate\Support\Facades\Cache::get('diag:ping') === 1), 'driver' => (string) config('cache.default')];
        } catch (\Throwable $e) { $checks['cache'] = ['ok' => false, 'error' => $e->getMessage()]; }

        // Storage writable
        try {
            $p = storage_path('logs/.diag-write');
            @file_put_contents($p, '1'); $w = is_file($p); @unlink($p);
            $checks['storage_writable'] = ['ok' => $w];
        } catch (\Throwable $e) { $checks['storage_writable'] = ['ok' => false, 'error' => $e->getMessage()]; }

        // Laravel caches that commonly cause "works in CLI, not in web"
        $checks['caches'] = [
            'config_cached' => is_file(base_path('bootstrap/cache/config.php')),
            'routes_cached' => (bool) glob(base_path('bootstrap/cache/routes-*.php')),
        ];

        return response()->json([
            'ok'         => true,
            'request_id' => (string) ($r->attributes->get('request_id') ?? ''),
            'build'      => \App\Support\BuildInfo::light(),
            'opcache'    => \App\Support\BuildInfo::opcache(),
            'checks'     => $checks,
            'queue'      => ['driver' => (string) config('queue.default')],
        ]);
    }

    /**
     * Recent application errors (GET /papi/diagnostics/errors) — read the tail of the log so you
     * can see real production errors WITHOUT shell access. Owner-only; reads only the last chunk.
     */
    public function platformErrors(Request $r)
    {
        if ($g = $this->ownerGate($r)) return $g;

        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        if (! $files) return response()->json(['ok' => true, 'errors' => [], 'note' => 'No log file found.']);
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $path = $files[0];

        // Read only the last ~256KB.
        $max  = 256 * 1024;
        $size = filesize($path);
        $fh   = fopen($path, 'rb');
        if ($size > $max) fseek($fh, $size - $max);
        $blob = (string) stream_get_contents($fh);
        fclose($fh);

        $lines = preg_split('/\r?\n/', $blob);
        $hits  = [];
        foreach ($lines as $ln) {
            if ($ln === '') continue;
            if (preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):|Exception|Stack trace/i', $ln)) {
                $hits[] = mb_substr($ln, 0, 600);
            }
        }
        $hits = array_slice(array_reverse($hits), 0, (int) min(80, max(10, $r->integer('n', 40))));

        return response()->json([
            'ok'        => true,
            'log'       => basename($path),
            'count'     => count($hits),
            'errors'    => $hits,
        ]);
    }

    /**
     * Reset opcache from the web process (GET/POST /papi/diagnostics/opcache-reset). Owner-only.
     * The escape hatch when validate_timestamps=Off pins old code after an in-place change.
     */
    public function opcacheReset(Request $r)
    {
        if ($g = $this->ownerGate($r)) return $g;

        $before = function_exists('opcache_get_status') ? (int) (@opcache_get_status(false)['opcache_statistics']['num_cached_scripts'] ?? 0) : null;
        $reset  = function_exists('opcache_reset') ? (bool) @opcache_reset() : false;
        try { \Illuminate\Support\Facades\Artisan::call('optimize:clear'); } catch (\Throwable $e) {}
        $after  = function_exists('opcache_get_status') ? (int) (@opcache_get_status(false)['opcache_statistics']['num_cached_scripts'] ?? 0) : null;

        return response()->json([
            'ok'              => $reset,
            'opcache_reset'   => $reset,
            'scripts_before'  => $before,
            'scripts_after'   => $after,
            'note'            => $reset ? 'opcache cleared; the web process will recompile from disk.' : 'opcache not available in this SAPI.',
        ]);
    }

    /* ============================ first-run activation checklist ============================ */

    /** Activation state (GET /papi/activation) — the 5 first-run steps, computed from real data. */
    public function activationState(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) {
            return response()->json(['ok' => false, 'error' => 'Not signed in.'], 401);
        }
        $t = $u->tenant;

        $isOwner = method_exists($u, 'isOwnerLike') && $u->isOwnerLike();

        $products = 0; $orders = 0;
        try { $products = (int) Product::where('tenant_id', $t->id)->whereNull('archived_at')->count(); } catch (\Throwable $e) {}
        try { $orders   = (int) \App\Models\Order::where('tenant_id', $t->id)->count(); } catch (\Throwable $e) {}

        $slug    = (string) ($t->slug ?? '');
        $shopUrl = $slug !== '' ? url('/' . $slug) : '';
        $waConnected = ! empty($t->whatsapp_instance);
        $linkShared  = (bool) $t->setting('activation_link_shared_at', false);

        $steps = [
            ['key' => 'setup',    'title' => 'Set up your shop',       'desc' => 'Name, country, currency and language.',         'done' => (bool) $t->setting('onboarded', false), 'cta' => 'Open setup',        'href' => '/panel/onboard'],
            ['key' => 'product',  'title' => 'Add your first product', 'desc' => 'One item is enough to open the shop.',          'done' => $products > 0,                          'cta' => 'Add a product',     'href' => '/app/products/create',
                'alt' => $products === 0 && $isOwner
                    ? ['cta' => '✨ Add ' . \App\Support\StarterCatalogue::countFor($t) . ' sample products', 'action' => 'starter']
                    : null],
            ['key' => 'whatsapp', 'title' => 'Connect WhatsApp',       'desc' => 'Scan the QR so the assistant can chat & sell.', 'done' => $waConnected,                           'cta' => 'Connect WhatsApp',  'href' => '/panel/setup'],
            ['key' => 'share',    'title' => 'Share your shop link',   'desc' => 'Send it to customers so they can browse & order.','done' => $linkShared,                            'cta' => 'Copy & share',      'href' => ''],
            ['key' => 'order',    'title' => 'Get your first order',   'desc' => 'When a customer orders, it appears in Orders.',  'done' => $orders > 0,                            'cta' => '',                  'href' => '/panel/today'],
        ];
        $doneCount = count(array_filter($steps, fn ($s) => $s['done']));

        return response()->json([
            'ok'        => true,
            'owner'     => $isOwner,
            'hidden'    => (bool) $t->setting('activation_hidden', false),
            'complete'  => $doneCount === count($steps),
            'done'      => $doneCount,
            'total'     => count($steps),
            'shop_url'  => $shopUrl,
            'steps'     => $steps,
        ]);
    }

    /** Persist a checklist action (GET/POST /papi/activation/mark) — {action: 'link'|'hide'|'show'}. */
    public function activationMark(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) {
            return response()->json(['ok' => false, 'error' => 'Not signed in.'], 401);
        }
        $t = $u->tenant;
        $action = (string) ($r->input('action', $r->query('action', '')));
        if ($action === 'link')  $t->putSetting('activation_link_shared_at', now()->toIso8601String());
        if ($action === 'hide')  $t->putSetting('activation_hidden', true);
        if ($action === 'show')  $t->putSetting('activation_hidden', false);

        return response()->json(['ok' => true]);
    }

    /**
     * Starter catalogue — GET previews the sample set for the shop's business type; POST seeds it.
     * Owner-only, idempotent: refuses to seed once the shop already has products, so it can never
     * duplicate or clobber real catalogue work.
     */
    public function starterCatalogue(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) {
            return response()->json(['ok' => false, 'error' => 'Not signed in.'], 401);
        }
        if (! method_exists($u, 'isOwnerLike') || ! $u->isOwnerLike()) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t     = $u->tenant;
        $items = \App\Support\StarterCatalogue::for($t);
        $kind  = (string) $t->setting('bot_kind', 'other');

        // Preview only.
        if (strtoupper($r->method()) === 'GET') {
            return response()->json([
                'ok'    => true,
                'kind'  => $kind,
                'count' => count($items),
                'names' => array_map(fn ($i) => $i['name'], $items),
            ]);
        }

        // Seed — never on a shop that already has products.
        $existing = Product::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->whereNull('archived_at')->count();
        if ($existing > 0) {
            return response()->json(['ok' => false, 'error' => 'has_products',
                'detail' => 'Your shop already has products, so sample items were not added.'], 409);
        }

        $order = 0;
        $added = 0;
        foreach ($items as $it) {
            try {
                Product::create([
                    'tenant_id'     => $t->id,
                    'name'          => $it['name'],
                    'category'      => $it['category'],
                    'keywords'      => $it['keywords'] ?? '',
                    'base_price'    => $it['price'],
                    'price'         => $it['price'],
                    'stock'         => 100,
                    'moq'           => $it['moq'] ?? null,
                    'pack_size'     => $it['pack_size'] ?? null,
                    'unit_label'    => $it['unit_label'] ?? null,
                    'display_order' => $order++,
                    'active'        => true,
                ]);
                $added++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('starter.seed_item_failed', ['tenant' => $t->id, 'name' => $it['name'], 'err' => $e->getMessage()]);
            }
        }
        $t->putSetting('starter_seeded_at', now()->toIso8601String());

        return response()->json(['ok' => true, 'added' => $added, 'kind' => $kind]);
    }

    /** In-product release notes (GET /papi/whatsnew). */
    public function whatsNew(Request $r)
    {
        return response()->json([
            'ok'      => true,
            'latest'  => \App\Support\Changelog::latestVersion(),
            'entries' => \App\Support\Changelog::entries(),
        ]);
    }
}
