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

    public function products()
    {
        $rows = Product::orderByDesc('display_order')->orderBy('name')->get()->map(function (Product $p) {
            return [
                'Product Name' => (string) $p->name,
                'Variant'      => '',
                'Brand'        => '',
                'Category'     => (string) ($p->category ?? ''),
                'Keywords'     => (string) ($p->keywords ?? ''),
                'Price_UGX'    => (float) ($p->base_price ?? $p->price ?? 0),
                'Price'        => (float) ($p->price ?? 0),
                'Base Price'   => (float) ($p->base_price ?? $p->price ?? 0),
                'Active'       => (bool) ($p->active ?? true),
                'Stock'        => (int) ($p->stock ?? 0),
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

        return response()->json(['products' => $rows]);
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
            'tagline'         => (string) ($s['tagline'] ?? ($isMfr ? $bd['tagline'] : '')),
            'heroTitle'       => (string) ($s['hero_title'] ?? ($isMfr ? $bd['heroTitle'] : '')),
            'heroText'        => (string) ($s['hero_text'] ?? ($isMfr ? $bd['heroText'] : '')),
            'metaDescription' => (string) ($s['meta_description'] ?? ''),
            'faq'             => array_values($s['faq'] ?? ($isMfr ? \App\Support\BrandDefaults::faq() : [])),
            'brands'          => array_values($s['brands'] ?? ($isMfr ? \App\Support\BrandDefaults::brands((string) ($s['theme_accent'] ?? '#103A8C')) : [])),
            'combos'          => array_values($s['combos'] ?? []),
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
            'usdUgx'        => (float) ($s['usdUgx'] ?? 3750),
            'usdSsp'        => (float) ($s['usdSsp'] ?? 7000),
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

        $lastWebhook = $lastProcessed = null;
        try {
            $lastWebhook   = \Illuminate\Support\Facades\DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'started')->max('created_at');
            $lastProcessed = \Illuminate\Support\Facades\DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'replied')->max('created_at');
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

        return response()->json([
            'ok'                => true,
            'now'               => now()->toIso8601String(),
            'openai_configured' => $openai,
            'redis'             => $redis,
            'queue'             => ['driver' => (string) config('queue.default'), 'failed_jobs' => $failedJobs],
            'whatsapp'          => $wa,
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
            'usdUgx'       => (float) $t->setting('usdUgx', 3750),
            'usdSsp'       => (float) $t->setting('usdSsp', 7000),
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
        if ($r->filled('payment'))  $o->payment        = (string) $r->query('payment');
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

        // South Sudan / Juba pricing: one markup % (per-client override, else the
        // tenant default settings.ssMarkupPct), auto-priced in USD and rounded to the
        // nearest $0.50. The matched UGX base is converted at the tenant USD rate.
        // The stored order is kept in marked-up UGX at convert time so Reports stay
        // in one base currency — here we only change what the customer is quoted.
        $usdMeta = null;
        if (strtoupper(trim((string) $r->input('currency', ''))) === 'USD') {
            $pct  = $this->ssMarkupFor($t, $phone);
            $rate = (float) $t->setting('usdUgx', 3750);
            if ($rate <= 0) $rate = 3750;
            $factor = (1 + $pct / 100) / $rate;
            $total  = 0.0;
            foreach ($quote['lines'] as &$ln) {
                if (empty($ln['matched'])) continue;
                $unit        = round(((float) $ln['price']) * $factor * 2) / 2; // nearest $0.50
                $ln['price'] = $unit;
                $ln['sum']   = $unit * (int) $ln['qty'];
                $total      += $ln['sum'];
            }
            unset($ln);
            $quote['total']    = round($total, 2);
            $quote['currency'] = 'USD';
            $usdMeta = ['usd_rate' => $rate, 'markup_pct' => $pct];
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
            ->orderByDesc('id')->limit(200)->get();

        $out = [];
        foreach ($rows as $q) {
            $url = '';
            if ($q->pdf_path) {
                try {
                    if (\Illuminate\Support\Facades\Storage::disk('public')->exists($q->pdf_path)) {
                        $url = \Illuminate\Support\Facades\Storage::disk('public')->url($q->pdf_path);
                    }
                } catch (\Throwable $e) { /* file may be gone */ }
            }
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
                $url = \Illuminate\Support\Facades\Storage::disk('public')->url($q->pdf_path);
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

        // A USD (South Sudan) quotation is stored back as marked-up UGX so the order
        // and Reports stay in one base currency. Convert at the rate captured on the quote.
        $usd  = strtoupper((string) $q->currency) === 'USD';
        $rate = $usd ? (float) (data_get($q->meta, 'usd_rate') ?: $t->setting('usdUgx', 3750)) : 1.0;
        if ($usd && $rate <= 0) $rate = (float) $t->setting('usdUgx', 3750);

        $items = []; $textParts = [];
        foreach ($q->items as $it) {
            if (! $it->matched) continue;
            $unit        = $usd ? round(((float) $it->unit_price) * $rate) : (float) $it->unit_price;
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
        $o->total          = $usd ? round(((float) $q->total) * $rate) : (float) $q->total;
        $o->save();        // OrderObserver assigns order_no + track_token

        $q->order_id = (int) $o->id;
        $q->status   = 'converted';
        $q->save();

        return response()->json(['ok' => true, 'order_id' => (int) $o->id, 'order_no' => (string) $o->order_no]);
    }

    /** Rebuild an OrderCalculator-shaped quote array from a stored quotation (for PDF regen). */
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

    public function updateProduct(Request $r)
    {
        $p = Product::find((int) $r->query('row'));
        if (! $p) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
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
            $updated++;
        }

        if ($apply && $ups) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($ups) {
                foreach ($ups as $id => $u) {
                    \Illuminate\Support\Facades\DB::table('products')->where('id', $id)->update($u);
                }
            });
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

    public function deleteProduct(Request $r)
    {
        $p = Product::find((int) $r->query('row'));
        if (! $p) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        $p->delete();
        return response()->json(['ok' => true]);
    }

    public function addProduct(Request $r)
    {
        $name = trim((string) $r->query('name', ''));
        $price = (float) $r->query('price', 0);
        if ($name === '' || $price <= 0) {
            return response()->json(['ok' => false, 'error' => 'name_and_price_required'], 422);
        }
        $variant = trim((string) $r->query('variant', ''));
        $full = $variant !== '' ? $name.' '.$variant : $name;

        Product::create([
            'name'       => $full,
            'category'   => (string) $r->query('category', ''),
            'keywords'   => (string) $r->query('keywords', ''),
            'base_price' => $price,
            'price'      => $price,
            'stock'      => (int) $r->query('stock', 0),
            'image_url'  => trim((string) $r->query('image', '')),
            'moq'        => $r->filled('moq') ? max(1, (int) $r->query('moq')) : null,
            'pack_size'  => $r->filled('pack_size') ? max(1, (int) $r->query('pack_size')) : null,
            'unit_label' => trim((string) $r->query('unit_label', '')) ?: null,
            'active'     => true,
        ]);

        return response()->json(['ok' => true]);
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
        $this->catFlush($t);
        return response()->json(['ok' => true]);
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

        if ($ref === '' || $amount <= 0) {
            return response()->json(['ok' => false, 'error' => 'bad_input'], 422);
        }

        $order = ctype_digit($ref)
            ? Order::find((int) $ref)
            : Order::where('order_no', $ref)->first();
        if (! $order) {
            return response()->json(['ok' => false, 'error' => 'order_not_found'], 404);
        }

        LedgerEntry::create([
            'type'        => 'in',
            'category'    => 'order_payment',
            'order_id'    => $order->id,
            'amount'      => $amount,
            'currency'    => 'UGX',
            'method'      => $method,
            'received_by' => (string) ($r->user()->name ?? ''),
            'note'        => $note,
        ]);

        $order->amount_paid = round((float) $order->amount_paid + $amount, 2);
        $order->save();

        $balance = $order->balanceDue();
        $state   = $order->paymentState();

        // Receipt to the customer.
        $sent = false;
        if ($notify && $order->customer_phone) {
            $amt = number_format($amount);
            $txt = $state === 'paid'
                ? "✅ Payment received: UGX {$amt} for order *{$order->order_no}*. Paid in full — thank you! 🛍"
                : "✅ Payment received: UGX {$amt} for order *{$order->order_no}*. Balance left: *UGX " . number_format($balance) . "*.";
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
    public function staffList(Request $r)
    {
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
                'self'  => (int) $u->id === $me,
            ])->values();

        return response()->json([
            'ok'        => true,
            'staff'     => $staff,
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
        $t     = $r->user()->tenant;
        $name  = trim((string) $r->input('name', ''));
        $email = strtolower(trim((string) $r->input('email', '')));
        $phone = preg_replace('/\D+/', '', (string) $r->input('phone', ''));
        $pass  = (string) $r->input('password', '');
        $role  = trim((string) $r->input('role', 'staff')) ?: 'staff';

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
        ]);

        return response()->json(['ok' => true]);
    }

    /** Remove a staff login (cannot remove yourself or the last login). */
    public function staffDelete(Request $r)
    {
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
        $t  = $r->user()->tenant;
        $id = (int) $r->input('id', 0);
        $u  = User::where('tenant_id', $t->id)->where('id', $id)->first();
        if (! $u) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $name  = trim((string) $r->input('name', (string) $u->name));
        $email = strtolower(trim((string) $r->input('email', (string) $u->email)));
        $phone = preg_replace('/\D+/', '', (string) $r->input('phone', (string) $u->phone));
        $role  = trim((string) $r->input('role', (string) ($u->role ?: 'staff'))) ?: 'staff';
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
    public function settingsSave(Request $r)
    {
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
        if (! in_array($key, ['combo_recommendations', 'send_product_images'], true)) {
            return response()->json(['ok' => false, 'error' => 'bad_key'], 422);
        }
        $on = in_array((string) $r->query('on', '1'), ['1', 'true', 'on', 'yes'], true);
        $t->putSetting($key, $on);

        return response()->json(['ok' => true, 'key' => $key, 'on' => $on]);
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
}
