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
                \App\Jobs\NotifyOwner::dispatch($tenant->id,
                    '🌐 A website visitor asked to talk to a person — open Chats (' . $from . ') to reply.');
                BotTrace::log($tenant->id, 'web', $from, 'web_human_requested');

                return response()->json(WebChatPolicy::shape(
                    [['type' => 'text', 'text' => WebChatPolicy::HUMAN_REQUEST_MSG]], true,
                    (array) ($convo->cart ?? []), $sid, true, (bool) $tenant->whatsapp_number));
            }

            $humanActive = $convo->agent_active || ((int) ($state['human_until'] ?? 0) > time());

            $sessionCount = $this->bump('webchat:s:' . $tenant->id . ':' . $sid, 86400);
            $dayCount     = $this->bump('webchat:d:' . $tenant->id . ':' . now()->format('Y-m-d'), 172800);

            $v = WebChatPolicy::verdict(
                (bool) $tenant->setting('web_chat', false),
                (string) $tenant->setting('bot_mode', 'auto'),
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
            $aiText = $text . ($loc !== '' ? "\n[delivery location selected on the website: {$loc}]" : '');

            $collector = new CollectorGateway();
            try {
                $ok = app(\App\Services\Bot\AiBrain::class)->handle($tenant, $convo, $from, $aiText, $collector, []);
            } catch (\Throwable $e) {
                BotTrace::log($tenant->id, 'web', $from, 'web_failed', mb_substr($e->getMessage(), 0, 160));
                \App\Jobs\NotifyOwner::dispatch($tenant->id,
                    '🌐 Web chat failed for a visitor — please check. (' . mb_substr($e->getMessage(), 0, 120) . ')');

                return response()->json(['ok' => false, 'error' => 'failed', 'retry' => true], 503);
            }

            $convo->refresh();
            BotTrace::log($tenant->id, 'web', $from, $ok ? 'web_replied' : 'web_no_reply');

            // v4.80 — product cards: the brain stars EXACT catalogue names (its own protocol);
            // we look those up for real and card ONLY what exists. No row, no card — a wrongly
            // starred phrase costs nothing. Explicit tenant scoping, console-rule style.
            $cards = [];
            try {
                $names = \App\Services\Bot\ChatCards::starredNames($collector->messages());
                if ($names !== []) {
                    $cur = strtoupper(trim((string) $tenant->setting('currency', 'UGX')));
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
                        }
                    }
                }
            } catch (\Throwable $e) {
                $cards = [];                    // cards are a bonus; the reply never dies for them
            }
            if ($cards !== []) {
                BotTrace::log($tenant->id, 'web', $from, 'web_cards', (string) count($cards));
            }

            return response()->json(WebChatPolicy::shape(
                $collector->messages(), $ok, (array) ($convo->cart ?? []), $sid, false, false, $cards));
        } finally {
            $lock->release();
        }
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
