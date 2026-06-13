<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
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
        $rows = Rider::orderBy('name')->get()->map(fn (Rider $r) => [
            'id'      => (int) $r->id,
            'name'    => (string) $r->name,
            'phone'   => (string) ($r->phone ?? ''),
            'photo'   => (string) ($r->photo ?? ''),
            'city'    => (string) ($r->city ?? ''),
            'address' => (string) ($r->address ?? ''),
            'notes'   => (string) ($r->notes ?? ''),
            'active'  => (bool) ($r->active ?? true),
        ]);

        return response()->json(['riders' => $rows]);
    }

    public function returns()
    {
        return response()->json(['returns' => [], 'credit' => (object) []]);
    }

    public function settings(Request $r)
    {
        $t = $r->user()->tenant;
        return response()->json([
            'ok'           => true,
            'storeName'    => (string) ($t->name ?? 'Family Shopper'),
            'storePhone'   => (string) ($t->whatsapp_number ?? ''),
            'storeAddress' => (string) ($t->setting('address', 'Kampala, Uganda')),
            'storeEmail'   => (string) ($t->setting('email', '')),
        ]);
    }

    public function branches()
    {
        return response()->json(['branches' => []]);
    }

    public function customers()
    {
        return response()->json(['ok' => true, 'profiles' => (object) []]);
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

    public function saveOrder(Request $r)
    {
        $o = Order::find((int) $r->query('row'));
        if (! $o) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
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

        if ($r->filled('total'))  $o->total  = (float) $r->query('total');
        if ($r->filled('status')) $o->status = (string) $r->query('status');
        $o->save();

        return response()->json(['ok' => true]);
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
            $wa->driver($t->whatsapp_driver ?: null)->sendText($t->whatsapp_instance, $phone, $body);
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
        if ($c) { $c->agent_active = $active; $c->save(); }
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

                $text = (string) (data_get($m, 'message.conversation')
                    ?? data_get($m, 'message.extendedTextMessage.text') ?? '');
                if (trim($text) === '') continue; // skip media-only / empty

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

        return response()->json(['ok' => true, 'imported' => $imported, 'scanned' => $scanned]);
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

    /* -------------------------------------------------- writes pending (3b) */
    // Return ok:false so the panel shows its honest "saved on this device only"
    // fallback rather than silently dropping data. Wired for real in Phase 3b.
    public function pending()
    {
        return response()->json(['ok' => false, 'pending' => true]);
    }
}
