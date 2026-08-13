<?php declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\CollectorGateway;
use App\Services\Bot\WebChatPolicy;
use App\Support\BotTrace;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * v4.78 (M-CHAT-1, ADR-018) — POST /chat: the storefront talks to the SAME brain as WhatsApp.
 *
 * This controller is deliberately thin: resolve the shop (slug or custom domain, exactly like
 * the rest of the storefront), read the counters, and let WebChatPolicy decide; when the answer
 * is "ai", run the UNCHANGED AiBrain through a CollectorGateway and hand back what it "sent".
 * Same conversation table, same inbox, same human-takeover, same clock, same honesty rules.
 *
 * No API keys, no model names, no business rules live in the browser — it only ever sees this
 * endpoint. Gate: setting web_chat (default OFF). Caps fail closed to a WhatsApp handoff.
 */
class ChatController extends Controller
{
    public function message(Request $request, ?string $shop = null)
    {
        $tenant = $this->resolve($shop);

        $data = $request->validate([
            'message'       => 'required|string|max:2000',
            'session_id'    => 'nullable|string|max:80',
            'location'      => 'nullable|string|max:40',    // v4.80 — the storefront's chosen delivery place
            'action'        => 'nullable|string|in:human',  // v4.80 — "Talk to a person"
            'auth'          => 'nullable|string|max:2000',  // v4.81 — the customer login token, validated server-side
            'contact_name'  => 'nullable|string|max:60',    // v4.85 — pre-chat form (owner's contact-first rule)
            'contact_phone' => 'nullable|string|max:32',    // v4.85 — WhatsApp number, canonicalised server-side
            'source'        => 'nullable|string|max:48',    // v4.93 — attribution, re-sanitised below
            'campaign'      => 'nullable|string|max:48',
            'biz'           => 'nullable|string|max:40',    // v4.98 — tapped "Shop by Business" tile, resolved in bizNote()
        ]);

        $sid = (string) ($data['session_id'] ?? '');
        if (! WebChatPolicy::sessionOk($sid)) {
            $sid = (string) Str::uuid();                    // fresh visitor (or a hostile id — same answer)
        }
        $from = WebChatPolicy::fromKey($sid);
        $text = trim($data['message']);

        // One message per session at a time — the same serialization rule the WhatsApp queue
        // enforces with WithoutOverlapping, done synchronously here.
        $lock = Cache::lock('webchat:lock:' . $tenant->id . ':' . $sid, 25);
        if (! $lock->get()) {
            return response()->json(['ok' => false, 'error' => 'busy', 'retry' => true], 429);
        }

        try {
            // v4.85 — THE CONTACT GATE (owner's rule, 10 Aug): "collect first customer name and
            // his whatsapp number then only we add to customers." Until a visitor gives a name
            // and a plausible WhatsApp number — or proves identity with a login token — NOTHING
            // is written: no conversation row, no message log, no counters, no AI spend.
            // Anonymous browsing therefore leaves ZERO residue in the portal. The form in the
            // widget is UI; this check is the law (fail closed). Gate: web_chat_contact,
            // default ON — protections default on, features default off.
            $convo = Conversation::where('customer_phone', $from)
                ->where('instance', WebChatPolicy::INSTANCE)->first();
            $state = ($convo && is_array($convo->state)) ? $convo->state : [];

            $auth      = trim((string) ($data['auth'] ?? ''));
            $authPhone = $auth !== '' ? CustomerAuthController::customerPhoneFromToken($tenant->id, $auth) : null;

            $cName  = trim((string) ($data['contact_name'] ?? ''));
            $cPhone = \App\Support\PhoneNormalizer::canonical((string) ($data['contact_phone'] ?? ''));
            $cOk    = mb_strlen($cName) >= 2 && preg_match('/^\d{7,15}$/', $cPhone) === 1;

            if ((bool) $tenant->setting('web_chat_contact', true)
                && empty($state['contact_phone']) && $authPhone === null && ! $cOk) {
                return response()->json(['ok' => false, 'error' => 'need_contact'], 422);
            }

            $convo = $convo ?: Conversation::firstOrCreate(
                ['customer_phone' => $from, 'instance' => WebChatPolicy::INSTANCE],
                ['state' => [], 'cart' => []]
            );

            // The inbox sees every web message, exactly like WhatsApp — even when the bot is
            // off, capped, or a person has taken over. A message nobody can find is worse than
            // any of those.
            MessageLog::record($tenant->id, $from, WebChatPolicy::INSTANCE, 'in', 'customer', $text);
            $convo->last_message_at = now();
            $convo->last_inbound_at = now();
            $convo->save();

            $state = is_array($convo->state) ? $convo->state : [];

            // v4.93 — where this conversation came from, recorded ONCE per session. The values are
            // sanitised server-side in StorefrontController::ctxWord before they ever reach the page,
            // so this is a short slug, not a query string. Written to bot_events (the telemetry that
            // already exists) rather than a new table, so "did the Facebook aluminium-foil advert
            // produce quotations?" is answerable by reading the same log as everything else.
            $src = substr(preg_replace('/[^a-z0-9._-]+/', '', strtolower((string) ($data['source'] ?? ''))), 0, 48);
            $cmp = substr(preg_replace('/[^a-z0-9._-]+/', '', strtolower((string) ($data['campaign'] ?? ''))), 0, 48);
            if (($src !== '' || $cmp !== '') && empty($state['source']) && empty($state['campaign'])) {
                $state['source']   = $src;
                $state['campaign'] = $cmp;
                $convo->state = $state;
                $convo->save();
                BotTrace::log($tenant->id, 'web', $from, 'chat_source', trim($src . ' ' . $cmp));
            }

            // v4.85 — first valid contact: remember it on the conversation, tell the inbox and
            // the brain who this is, and create the customer master the way ORDERS do
            // (PhoneNormalizer::canonical; fill blanks, never clobber a curated name).
            // SECURITY: a TYPED number is unverified — it must NEVER link this web session to an
            // existing WhatsApp history (anyone could type someone else's number and read their
            // conversation). linked_phone stays exclusive to the signed-in path below.
            if ($cOk && empty($state['contact_phone'])) {
                $state['contact_name']  = mb_substr($cName, 0, 60);
                $state['contact_phone'] = $cPhone;
                $state['contact_at']    = time();
                $convo->state = $state;
                $convo->save();
                MessageLog::record($tenant->id, $from, WebChatPolicy::INSTANCE, 'out', 'system',
                    '[Website visitor introduced themselves on the pre-chat form: ' . $state['contact_name'] . ', WhatsApp +' . $cPhone . ']');
                BotTrace::log($tenant->id, 'web', $from, 'web_contact', $cPhone);
                try {
                    $prof = \App\Models\CustomerProfile::withoutGlobalScopes()->firstOrNew(
                        ['tenant_id' => $tenant->id, 'phone' => $cPhone]
                    );
                    if (trim((string) $prof->name) === '') {
                        $prof->name = $state['contact_name'];
                    }
                    if (! $prof->exists) {
                        $prof->notes = trim((string) $prof->notes . "\nMet on website chat (pre-chat form).");
                    }
                    if (! $prof->exists || $prof->isDirty()) {
                        $prof->save();
                    }
                } catch (\Throwable $e) {
                    // the chat must not die on a CRM hiccup; the system line above still names them
                }
            }

            // v4.81 (M-CHAT-4) — a signed-in visitor links this conversation to their customer
            // identity ONCE. The token is the same encrypted, tenant-pinned, expiring credential
            // the account area uses — validated server-side; an invalid token simply leaves the
            // visitor anonymous. The link line lands in the SAME message history, so the inbox
            // AND the brain both know who they're talking to from here on.
            if ($authPhone !== null && empty($state['linked_phone'])) {   // v4.85 — token already validated once at the gate
                $phone = $authPhone;
                if ($phone !== null) {
                    $name = '';
                    try {
                        $prof = \App\Models\CustomerProfile::withoutGlobalScopes()
                            ->where('tenant_id', $tenant->id)->where('phone', $phone)->first();
                        $name = trim((string) ($prof->name ?? ''));
                    } catch (\Throwable $e) {
                        // profile is a nicety; the phone link stands
                    }
                    $state['linked_phone'] = $phone;
                    $state['linked_name']  = $name;
                    $convo->state = $state;
                    $convo->save();
                    MessageLog::record($tenant->id, $from, WebChatPolicy::INSTANCE, 'out', 'system',
                        '[Customer signed in on the website: ' . ($name !== '' ? $name . ', ' : '') . '+' . $phone . ']');
                    BotTrace::log($tenant->id, 'web', $from, 'web_linked', $phone);
                }
            }

            // v4.80 — "Talk to a person": flips the SAME human-takeover switch the inbox uses,
            // alerts the owner, and never burns a cap. The visitor can keep typing; every line
            // lands in the inbox for the person who picks it up.
            if (($data['action'] ?? '') === 'human') {
                $mins = max(15, (int) $tenant->setting('human_takeover_minutes', 60));
                $state['human_until'] = time() + $mins * 60;
                $convo->state = $state;
                $convo->save();
                $this->alertOwner($tenant,
                    '🌐 A website visitor asked to talk to a person — open Chats (' . $from . ') to reply.');
                BotTrace::log($tenant->id, 'web', $from, 'web_human_requested');

                return response()->json(WebChatPolicy::shape(
                    [['type' => 'text', 'text' => WebChatPolicy::HUMAN_REQUEST_MSG]], true,
                    (array) ($convo->cart ?? []), $sid, true, (bool) $tenant->whatsapp_number));
            }

            $humanActive = $convo->agent_active || ((int) ($state['human_until'] ?? 0) > time());

            $sessionCount = $this->bump('webchat:s:' . $tenant->id . ':' . $sid, 86400);
            $dayCount     = $this->bump('webchat:d:' . $tenant->id . ':' . now()->format('Y-m-d'), 172800);

            // Website chat answers over HTTP in the browser and never touches Evolution — there is no
            // sendText anywhere in this path — so it cannot earn a WhatsApp reach-out lock. Reading
            // bot_mode directly meant a WhatsApp pause silently closed a channel that was not
            // implicated in it: tenant 17 sat behind a Meta 463 timelock with bot_mode=off, and web
            // chat deflected every visitor for the duration. `web_chat_mode` lets the two be paused
            // separately, and DEFAULTS to bot_mode, so no tenant's behaviour changes until it is set.
            $v = WebChatPolicy::verdict(
                (bool) $tenant->setting('web_chat', false),
                (string) $tenant->setting('web_chat_mode', $tenant->setting('bot_mode', 'auto')),
                $humanActive,
                $sessionCount, (int) $tenant->setting('web_chat_session_cap', WebChatPolicy::SESSION_CAP_DEFAULT),
                $dayCount, (int) $tenant->setting('web_chat_daily_cap', WebChatPolicy::DAY_CAP_DEFAULT),
            );

            if ($v['action'] === 'off') {
                return response()->json(['ok' => false, 'error' => 'chat_off'], 404);
            }
            if ($v['action'] !== 'ai') {
                BotTrace::log($tenant->id, 'web', $from, 'web_' . $v['action']);

                return response()->json(WebChatPolicy::shape(
                    [['type' => 'text', 'text' => $v['message']]], true,
                    (array) ($convo->cart ?? []), $sid, $v['action'] === 'human', $v['wa']));
            }

            // v4.80 — the visitor's chosen delivery place rides along as DATA (same weave pattern
            // as ad-awareness in the WhatsApp job). The inbox logged the raw text above; the brain
            // sees the annotation and stops asking for a location the website already knows.
            $loc = trim((string) ($data['location'] ?? ''));
            $aiText = $text . ($loc !== '' ? "\n[delivery location selected on the website: {$loc}]" : '')
                    . $this->bizNote($tenant, (string) ($data['biz'] ?? ''), $from);

            $collector = new CollectorGateway();
            try {
                $ok = app(\App\Services\Bot\AiBrain::class)->handle($tenant, $convo, $from, $aiText, $collector, []);
            } catch (\Throwable $e) {
                BotTrace::log($tenant->id, 'web', $from, 'web_failed', mb_substr($e->getMessage(), 0, 160));
                $this->alertOwner($tenant,
                    '🌐 Web chat failed for a visitor — please check. (' . mb_substr($e->getMessage(), 0, 120) . ')');

                return response()->json(['ok' => false, 'error' => 'failed', 'retry' => true], 503);
            }

            $convo->refresh();
            BotTrace::log($tenant->id, 'web', $from, $ok ? 'web_replied' : 'web_no_reply');

            // v4.80 — product cards: the brain stars EXACT catalogue names (its own protocol);
            // we look those up for real and card ONLY what exists. No row, no card — a wrongly
            // starred phrase costs nothing. Explicit tenant scoping, console-rule style.
            $cards  = [];
            $missed = [];
            $msgs   = $collector->messages();
            $cur    = strtoupper(trim((string) $tenant->setting('currency', 'UGX')));
            try {
                $names = \App\Services\Bot\ChatCards::starredNames($msgs);
                if ($names !== []) {
                    $rows = \App\Models\Product::withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)->whereIn('name', $names)->get()->keyBy('name');
                    // v4.82 — second pass for the misses: candidates fetched by the name's
                    // longest token, then accepted ONLY on normalized equality (case/suffix
                    // drift). Ambiguity or spelling drift → no card, never a guessed product.
                    foreach ($names as $n) {
                        if (! $rows->has($n)) {
                            $tokens = preg_split('/\s+/u', $n) ?: [];
                            usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                            $tok = (string) ($tokens[0] ?? '');
                            if (mb_strlen($tok) >= 4) {
                                $cands = \App\Models\Product::withoutGlobalScopes()
                                    ->where('tenant_id', $tenant->id)
                                    ->where('name', 'like', '%' . $tok . '%')->limit(12)->get();
                                $want = \App\Services\Bot\ChatCards::normalize($n);
                                $hit = null;
                                foreach ($cands as $c) {
                                    if (\App\Services\Bot\ChatCards::normalize((string) $c->name) === $want) {
                                        if ($hit !== null) { $hit = null; break; }   // two matches = ambiguous = no card
                                        $hit = $c;
                                    }
                                }
                                if ($hit !== null) {
                                    $rows->put($n, $hit);
                                }
                            }
                        }
                        if ($rows->has($n)) {
                            $p = $rows[$n];
                            $cards[] = \App\Services\Bot\ChatCards::card([
                                'id' => $p->id, 'name' => $p->name, 'image_url' => (string) $p->image_url,
                                'price' => $p->price, 'currency' => $cur, 'unit_label' => (string) $p->unit_label,
                                'pack_size' => $p->pack_size, 'moq' => $p->moq,
                            ]);
                        } else {
                            // v5.01 — a name the reply used that matched NO product. Silence here is
                            // how "3 named, 2 carded" stayed a mystery: the miss is the interesting
                            // half. Logged, capped, never guessed at.
                            $missed[] = $n;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $cards = [];                    // cards are a bonus; the reply never dies for them
            }
            if ($missed !== []) {
                BotTrace::log($tenant->id, 'web', $from, 'web_card_miss',
                    mb_substr(implode(' | ', $missed), 0, 280));
            }

            // v4.97 — a photo reply becomes a REAL card here too. See cardImages().
            try {
                $msgs = $this->cardImages($tenant, $msgs, $cards, $cur, $from);
            } catch (\Throwable $e) {
                $msgs = $collector->messages();  // same rule: the reply never dies for a card
            }

            if ($cards !== []) {
                BotTrace::log($tenant->id, 'web', $from, 'web_cards', (string) count($cards));
            }

            return response()->json(WebChatPolicy::shape(
                $msgs, $ok, (array) ($convo->cart ?? []), $sid, false, false, $cards));
        } finally {
            $lock->release();
        }
    }

    /**
     * v4.98 — translate a tapped "Shop by Business" tile into something the brain can act on.
     *
     * The rail's tiles come from `business_banners`, which is MERCHANDISING: a grouping that exists
     * on this website and nowhere in the catalogue. A visitor tapped "Office Supplies", the page
     * sent "Show me Office Supplies", and the bot replied — correctly, from where it was standing —
     * "We don't have a specific category for Office Supplies". The label was never in its world.
     *
     * The tile's ID rides along with that one message and is resolved HERE, against the tenant's own
     * saved config, into the categories and keywords the owner put behind it. Nothing about any
     * collection is written into the prompt, nothing is hardcoded, and a shop that has never
     * configured business banners produces no annotation at all. Same weave pattern as the delivery
     * location above: the inbox logs what the customer actually said, the brain gets the context.
     */
    private function bizNote(Tenant $tenant, string $bizId, string $from): string
    {
        $bizId = mb_substr(strtolower(trim($bizId)), 0, 40);
        $bizId = (string) preg_replace('/[^a-z0-9._-]+/', '', $bizId);
        if ($bizId === '') return '';

        try {
            foreach ((array) data_get($tenant->settings, 'business_banners.businesses', []) as $b) {
                if (! is_array($b)) continue;
                if (strtolower(trim((string) ($b['id'] ?? ''))) !== $bizId) continue;
                if (($b['active'] ?? true) === false) return '';

                $label = trim((string) ($b['label'] ?? '')) ?: $bizId;
                $cats  = array_values(array_filter(array_map('trim', (array) data_get($b, 'match.categories', []))));
                $kws   = array_values(array_filter(array_map('trim', (array) data_get($b, 'match.keywords', []))));

                $bits = [];
                if ($cats !== []) $bits[] = 'catalogue categories ' . implode(', ', array_slice($cats, 0, 20));
                if ($kws !== [])  $bits[] = 'keywords ' . implode(', ', array_slice($kws, 0, 30));
                if (data_get($b, 'match.bulk')) $bits[] = 'plus every bulk or carton line (pack size or MOQ above 1)';

                BotTrace::log($tenant->id, 'web', $from, 'chat_biz', $bizId);

                // v5.02 — phrased as a STATE, not an event. The tile now sets the context and it
                // rides every message until another is opened, so "the customer tapped…" would read
                // as new on turn five. This says where they are, which stays true.
                return "\n[the customer is browsing the \"" . $label . '" collection on this shop\'s website. '
                     . 'It is a grouping this shop publishes, not a catalogue category, so it will not appear in the '
                     . 'PRODUCTS list — but it is real and it is ours. '
                     . ($bits !== []
                        ? 'It covers ' . implode('; ', $bits) . '. Prefer those products, and read anything they ask '
                          . 'for narrowly ("cleaning products") as a request WITHIN this collection.'
                        : 'The owner has not yet mapped it to categories or keywords, so offer the closest lines you can '
                          . 'and say you will confirm the full range.')
                     . ' Do not tell the customer we have no such collection.]';
            }
        } catch (\Throwable $e) {
            return '';                      // context is a bonus; the message goes through regardless
        }

        return '';
    }

    /**
     * v4.97 — a product PHOTO reply becomes a real card on the web.
     *
     * ProductImageResponder answers a product question the WhatsApp way: sendImage(url, caption).
     * That is the correct shape there — on WhatsApp a photo with a caption IS the product card. In
     * a browser the same thing arrived as a bare <img> with a paragraph under it: no pack size, no
     * MOQ, no price formatting and — the part that costs money — NO ADD BUTTON. The card renderer
     * was sitting right there unused, because ChatCards::starredNames() only ever reads messages of
     * type 'text', and a photo's caption is not one.
     *
     * The join is by IMAGE PATH, not by reading the caption: an exact equality against
     * products.image_url for this tenant. That matters beyond tidiness — it is the one route that
     * still works for a product whose NAME contains an asterisk ("Aluminium Foil 45CM*150/300"),
     * which the star protocol cannot express and therefore can never card. No row, or two rows
     * sharing one photo, means the image is left exactly as it arrived.
     *
     * Gallery shots ("more photos") live in gallery_1..3 and marketplace Special Order photos sit
     * on foreign hosts, so neither can match image_url. Both keep their plain bubble, which is
     * right: neither is a thing to add to a cart.
     *
     * v5.01 — AND THE REPLY'S OWN WORDS DECIDE WHICH PRODUCTS APPEAR.
     *
     * Two selectors were feeding this screen and they never agreed. The brain picks what to name;
     * ProductImageResponder independently browses the same category and returns up to three photos.
     * A real turn came back with "Here are some options: 1. Kent Hazelnut 2. Macdo Chilli 3. Macdo
     * Tomato" over FIVE cards — two from the names, three from the browse, and Kent Hazelnut absent
     * from both. Every card was a real, correctly-priced product, so nothing false was shown; it was
     * simply not the answer the sentence above it had given.
     *
     * So: if the reply NAMED products that exist, those are the cards. Photos of products it did not
     * name are dropped — card and photo both — because rendering them as bare photos is the very
     * thing v4.97 fixed, and adding them as cards is what made the screen incoherent. When the reply
     * named nothing that matched, the photos are all we have and every one of them cards, exactly as
     * before. Drops are counted into BotTrace, never silent.
     *
     * @param  array<int,array<string,mixed>>  $msgs
     * @param  array<int,array<string,mixed>>  $cards  appended to, in place
     * @return array<int,array<string,mixed>>  the rewritten message list
     */
    private function cardImages(Tenant $tenant, array $msgs, array &$cards, string $cur, string $from = ''): array
    {
        // ProductImageResponder::absUrl() sends "https://<domain>/<image_url>", while the column
        // itself may hold "/storage/x.jpg", "storage/x.jpg" or a full URL depending on how the
        // product was created. The SQL asks for every spelling, and the comparison afterwards is
        // done on a normalised key, so a missing or extra leading slash cannot silently cost a card.
        $key = static function (string $v): string {
            $v = trim($v);
            if ($v === '') return '';
            $p = preg_match('#^https?://#i', $v) ? (string) (parse_url($v, PHP_URL_PATH) ?: '') : $v;

            return $p === '' ? '' : '/' . ltrim($p, '/');
        };

        $want = [];
        foreach ($msgs as $m) {
            if (($m['type'] ?? '') !== 'image') continue;
            $u = trim((string) ($m['url'] ?? ''));
            if ($u === '') continue;
            $want[$u] = true;
            $k = $key($u);
            if ($k !== '') { $want[$k] = true; $want[ltrim($k, '/')] = true; }
        }
        if ($want === []) return $msgs;

        $byUrl = [];
        $rows = \App\Models\Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('image_url', array_keys($want))
            ->get();
        foreach ($rows as $p) {
            $k = $key((string) $p->image_url);
            if ($k === '') continue;
            // two products sharing one photo is ambiguous — card neither of them
            $byUrl[$k] = array_key_exists($k, $byUrl) ? null : $p;
        }
        if ($byUrl === []) return $msgs;

        $seen = [];
        foreach ($cards as $c) $seen[(int) ($c['id'] ?? 0)] = true;

        // The reply named products that exist → it has already answered, and the browse is noise.
        $replyNamed = $cards !== [];
        $dropped    = 0;

        $new = [];              // held back until we know the rewrite is safe to keep
        $out = [];
        foreach ($msgs as $m) {
            if (($m['type'] ?? '') !== 'image') { $out[] = $m; continue; }

            $k = $key(trim((string) ($m['url'] ?? '')));
            $p = ($k !== '' && array_key_exists($k, $byUrl)) ? $byUrl[$k] : null;

            if ($p === null) { $out[] = $m; continue; }

            if ($replyNamed && ! isset($seen[(int) $p->id])) {
                $dropped++;      // a product the sentence above did not mention — neither card nor photo
                continue;
            }

            if (! isset($seen[(int) $p->id])) {
                $seen[(int) $p->id] = true;
                $new[] = \App\Services\Bot\ChatCards::card([
                    'id' => $p->id, 'name' => $p->name, 'image_url' => (string) $p->image_url,
                    'price' => $p->price, 'currency' => $cur, 'unit_label' => (string) $p->unit_label,
                    'pack_size' => $p->pack_size, 'moq' => $p->moq,
                ]);
            }

            // The caption's FIRST line is the name and price, which the card now renders properly.
            // Everything AFTER it — weight variants, "Reply with:", "Goes well with:" — is real
            // information the card has no room for, so it stays as an ordinary bubble instead of
            // being thrown away with the photo.
            $lines = preg_split('/\R/u', (string) ($m['caption'] ?? '')) ?: [];
            array_shift($lines);
            $rest = trim(implode("\n", $lines));
            if ($rest !== '') $out[] = ['type' => 'text', 'text' => $rest];
        }

        // A turn that was ONLY photos would now be an empty message list, and shape() reads that as
        // "the brain said nothing" and apologises on its behalf. Keep the originals in that case —
        // and drop the cards with them, so the customer never sees the photo and its card twice.
        if ($out === []) return $msgs;

        foreach ($new as $c) $cards[] = $c;

        if ($dropped > 0) {
            BotTrace::log($tenant->id, 'web', $from, 'web_browse_dropped', (string) $dropped);
        }

        return $out;
    }

    /**
     * Owner alert for a web-chat event — WhatsApp when that channel is usable, the log when it is not.
     *
     * NotifyOwner sends a real WhatsApp from the tenant's own instance. That is the ONE thread from
     * this controller back into the locked channel, and it matters once web chat can run while
     * WhatsApp is paused (`web_chat_mode`): a shop sitting under a reach-out timelock would answer
     * web visitors happily and, every time one asked for a person, fire a send that Meta refuses.
     * Refused sends are the behaviour that earns the lock, so they must not be made on its account.
     *
     * Nothing is lost when it is skipped: BotTrace already records both events at the call sites, so
     * the request is in Chats either way — the owner reads it there instead of on their handset.
     */
    private function alertOwner(Tenant $tenant, string $text): void
    {
        if ((string) $tenant->setting('bot_mode', 'auto') === 'off') {
            \Log::info('web chat owner alert not sent (bot_mode=off, WhatsApp paused): ' . $text);

            return;
        }
        \App\Jobs\NotifyOwner::dispatch($tenant->id, $text);
    }

    /** Counter with a lifetime, returning the count INCLUDING this hit. */
    private function bump(string $key, int $ttl): int
    {
        Cache::add($key, 0, $ttl);

        return (int) Cache::increment($key);
    }

    /** Shop by slug (platform path) or by request Host (custom domain) — the storefront's rule. */
    private function resolve(?string $shop): Tenant
    {
        if ($shop !== null && $shop !== '') {
            $tenant = Tenant::where('slug', strtolower(trim($shop)))->first();
            abort_if(! $tenant, 404);
        } else {
            $tenant = Tenant::byCustomDomain(request()->getHost());
            abort_if(! $tenant, 404);
        }
        abort_if(($tenant->status ?? 'active') === 'suspended', 404);
        app(TenantContext::class)->set($tenant->id);

        return $tenant;
    }
}
