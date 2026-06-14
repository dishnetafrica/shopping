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
        $rows = Product::orderBy('name')->get()->map(function (Product $p) {
            return [
                'Product Name' => (string) $p->name,
                'Variant'      => '',
                'Brand'        => '',
                'Category'     => (string) ($p->category ?? ''),
                'Keywords'     => (string) ($p->keywords ?? ''),
                'Price_UGX'    => (float) ($p->base_price ?? $p->price ?? 0),
                'Stock'        => (int) ($p->stock ?? 0),
                'StockByBranch'=> null,
                'Barcode'      => (string) ($p->barcode ?? ''),
                'Item_Code'    => (string) ($p->sku ?? ''),
                'Image'        => (string) ($p->image_url ?? ''),
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
        return response()->json([
            'ok'            => true,
            'storeName'     => (string) ($t->name ?? 'Family Shopper'),
            'storePhone'    => (string) ($t->whatsapp_number ?? ''),
            'storeAddress'  => (string) ($s['address'] ?? 'Kampala, Uganda'),
            'storeEmail'    => (string) ($s['email'] ?? ''),
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

    public function updateProduct(Request $r)
    {
        $p = Product::find((int) $r->query('row'));
        if (! $p) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $price = (float) $r->query('price', 0);
        $p->base_price = $price;
        $p->price      = $price;
        $p->stock      = (int) $r->query('stock', $p->stock);
        $img = trim((string) $r->query('image', ''));
        if ($img !== '') $p->image_url = $img;
        $p->save();

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

        return response()->json(['ok' => true, 'url' => Storage::url($file)]);
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

        $list = $convos->map(function (Conversation $c) use ($lasts, $names) {
            $m = $lasts->get($c->customer_phone);
            return [
                'phone'        => (string) $c->customer_phone,
                'name'         => (string) ($names->get($c->customer_phone) ?? ''),
                'last'         => $m ? (string) $m->body : '',
                'last_sender'  => $m ? (string) $m->sender : '',
                'last_at'      => optional($c->last_message_at)->format('Y-m-d H:i:s') ?? '',
                'unread'       => (int) $c->unread,
                'agent_active' => (bool) $c->agent_active,
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

        return response()->json(['messages' => $msgs, 'agent_active' => $agent]);
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

    public function chatBotMode(Request $r)
    {
        $mode = (string) $r->input('mode', 'auto');
        if (! in_array($mode, ['auto', 'off'], true)) $mode = 'auto';
        $t = $r->user()->tenant;
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
        $instance = (string) ($r->user()->tenant->whatsapp_instance ?? '');
        if ($instance !== '') $evo->disconnect($instance);
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

        return response()->json([
            'ok'    => true,
            'track' => url('/papi/track?o=' . $o->id . '&t=' . $o->track_token),
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

    /* -------------------------------------------------- settings / config (3b) */
    public function settingsSave(Request $r)
    {
        $t = $r->user()->tenant;
        $s = $t->settings ?? [];
        foreach (['storeName', 'storePhone', 'storeAddress', 'storeEmail', 'base', 'perKm', 'min', 'round', 'freeOver', 'lat', 'lng', 'inventoryMode', 'usdUgx', 'usdSsp'] as $k) {
            if ($r->has($k)) $s[$k] = $r->query($k);
        }
        $s['address'] = (string) $r->query('storeAddress', $s['address'] ?? '');
        $s['email']   = (string) $r->query('storeEmail', $s['email'] ?? '');
        if ($r->filled('storeName'))  $t->name = (string) $r->query('storeName');
        if ($r->filled('storePhone')) $t->whatsapp_number = preg_replace('/[^0-9+]/', '', (string) $r->query('storePhone'));
        $t->settings = $s;
        $t->save();
        return response()->json(['ok' => true, 'settings' => $s]);
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
