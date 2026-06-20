<?php
namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Catalogue\ProductSearch;
use App\Services\Pricing;
use Illuminate\Support\Str;

/**
 * Conversational ordering. Understanding is done by BotNlu (OpenAI) when
 * available; if it's disabled or fails, deterministic keyword parsing takes
 * over. Either path runs through the SAME cart/checkout executor below, so
 * pricing and order creation are always deterministic PHP.
 */
class BotBrain
{
    /** Bump on every deploy. Query it from WhatsApp by sending "version" to confirm what's live. */
    public const VERSION = '2026.06.18-86.9.1  fix-diagnostics-route';

    public function __construct(
        protected ProductSearch $search,
        protected BotNlu $nlu,
        protected ShoppingParser $parser,
        protected CatalogueMatcher $matcher,
        protected ClarificationFlow $clarify,
    ) {}

    /** One-shot "with <accompaniment>" hint, set per message in respondInner, consumed by maybeAskModifier. */
    protected string $modHint = '';

    public function respond(Tenant $tenant, Conversation $convo, string $text): string
    {
        return $this->appendWebsite($tenant, $this->respondInner($tenant, $convo, $text));
    }

    /** For custom-domain shops, append the website link to every reply (once). */
    protected function appendWebsite(Tenant $tenant, string $reply): string
    {
        if (trim((string) $reply) === '') return (string) $reply;       // nothing being sent
        $nudge = $this->websiteNudge($tenant);                          // '' unless custom domain + enabled
        if ($nudge === '') return $reply;
        $dom = trim((string) $tenant->custom_domain);
        if ($dom !== '' && str_contains($reply, $dom)) return $reply;   // already linked (greeting/menu)
        return $reply . $nudge;
    }

    // ---------------- modifier (accompaniment) flow ----------------
    // All paths are gated behind tenantHasModifiers() and wrapped in try/catch, so a tenant
    // with no modifier groups (every grocery shop) gets byte-identical behaviour, and any
    // modifier glitch degrades to "don't ask" rather than breaking the order.

    /** Cheap gate: does this tenant use any modifier groups at all? */
    protected function tenantHasModifiers(int $tenantId): bool
    {
        try {
            return \App\Models\ModifierGroup::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('active', true)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Lowercased option names across the tenant's groups (for the "with naan" pre-strip). */
    protected function modifierOptionNames(int $tenantId): array
    {
        try {
            $gids = \App\Models\ModifierGroup::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('active', true)->pluck('id');
            if ($gids->isEmpty()) return [];
            return \App\Models\ModifierOption::query()
                ->whereIn('modifier_group_id', $gids)->where('active', true)->pluck('name')
                ->map(fn ($n) => mb_strtolower((string) $n))->filter()->unique()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Required groups (as ModifierFlow arrays) attached to a product. */
    protected function productGroups(int $tenantId, int $productId): array
    {
        try {
            return \App\Models\ModifierGroup::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('active', true)
                ->whereHas('products', fn ($q) => $q->where('products.id', $productId))
                ->with(['options' => fn ($q) => $q->where('active', true)->orderBy('sort')])
                ->orderBy('sort')->get()
                ->map(fn ($g) => [
                    'id' => $g->id, 'name' => $g->name, 'required' => (bool) $g->required,
                    'min_select' => (int) $g->min_select, 'max_select' => (int) $g->max_select,
                    'options' => $g->options->map(fn ($o) => [
                        'id' => $o->id, 'name' => $o->name, 'price_delta' => (float) $o->price_delta,
                    ])->values()->all(),
                ])->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * After the cart changed: if a line still needs a required choice, set the pending state and
     * return the question. A one-shot "with <accompaniment>" hint attaches the choice silently.
     * Returns null when nothing is pending (the normal reply then stands).
     */
    protected function maybeAskModifier(Tenant $tenant, Conversation $convo): ?string
    {
        if (! $tenant->id || ! $this->tenantHasModifiers((int) $tenant->id)) return null;
        $cart = is_array($convo->cart) ? $convo->cart : [];
        foreach ($cart as $i => $line) {
            if (! is_array($line) || ! empty($line['modifiers'])) continue;
            $pid = (int) ($line['product_id'] ?? 0);
            if (! $pid) continue;
            $groups = $this->productGroups((int) $tenant->id, $pid);
            $need = \App\Support\ModifierFlow::nextRequired($groups, []);
            if (! $need) continue;

            if ($this->modHint !== '') {
                $opt = \App\Support\ModifierFlow::resolve($this->modHint, $need);
                if ($opt) {
                    $cart[$i]['modifiers'] = [['group' => $need['name'], 'name' => $opt['name'], 'price_delta' => (float) $opt['price_delta']]];
                    $cart[$i]['price'] = \App\Support\ModifierCalc::unitPrice((float) $cart[$i]['price'], $cart[$i]['modifiers']);
                    $this->modHint = '';
                    $convo->cart = $cart; $convo->save();
                    continue; // satisfied; keep scanning for the next line
                }
            }

            $st = is_array($convo->state) ? $convo->state : [];
            $st['pending_modifier'] = ['i' => $i, 'group' => $need];
            $convo->state = $st; $convo->save();
            return \App\Support\ModifierFlow::prompt($need, (string) ($line['name'] ?? ''));
        }
        return null;
    }

    /** Top-of-flow guard: while awaiting a required choice, treat the reply as the pick. */
    protected function resolvePendingModifier(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        $st = is_array($convo->state) ? $convo->state : [];
        $pm = $st['pending_modifier'] ?? null;
        if (! $pm || ! is_array($pm) || ! is_array($pm['group'] ?? null)) return null;

        $group = $pm['group'];
        $i = (int) ($pm['i'] ?? -1);
        $cart = is_array($convo->cart) ? $convo->cart : [];
        if (! isset($cart[$i]) || ! is_array($cart[$i])) {
            unset($st['pending_modifier']); $convo->state = $st; $convo->save();
            return null; // stale; let the message flow normally
        }

        $opt = \App\Support\ModifierFlow::resolve($text, $group);
        if (! $opt) {
            if (preg_match('/\b(cancel|skip|nevermind|never mind|leave it|any|whatever)\b/i', $text)) {
                $opt = $group['options'][0] ?? null;        // don't trap the customer
            }
            if (! $opt) {
                return "Sorry, I didn't catch that.\n\n" . \App\Support\ModifierFlow::prompt($group, (string) ($cart[$i]['name'] ?? ''));
            }
        }

        $cart[$i]['modifiers'] = [['group' => $group['name'], 'name' => $opt['name'], 'price_delta' => (float) $opt['price_delta']]];
        $cart[$i]['price'] = \App\Support\ModifierCalc::unitPrice((float) $cart[$i]['price'], $cart[$i]['modifiers']);
        unset($st['pending_modifier']);
        $convo->cart = $cart; $convo->state = $st; $convo->save();

        $line = "Added *{$cart[$i]['name']} + {$opt['name']}*.";
        $next = $this->maybeAskModifier($tenant, $convo);
        return $next !== null ? ($line . "\n\n" . $next) : ($line . "\n" . $this->cartSummary($tenant, $cart));
    }

    protected function respondInner(Tenant $tenant, Conversation $convo, string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';

        // Mid-checkout: the next message is always the delivery location.
        if (data_get($convo->state, 'step') === 'awaiting_location') {
            return $this->placeOrder($tenant, $convo, $text);
        }

        // Awaiting a yes/no on the priced order (a pin was sent with items in the cart). A plain
        // "yes/ok/go ahead" places it; "no/not yet" holds; anything else is a fresh request.
        if (data_get($convo->state, 'step') === 'awaiting_confirm') {
            if (($c = $this->handleOrderConfirm($tenant, $convo, $text)) !== null) {
                return $c;
            }
        }

        // Awaiting a required modifier choice (e.g. "choose your accompaniment"): the reply is the pick.
        if (($pm = $this->resolvePendingModifier($tenant, $convo, $text)) !== null) {
            return $pm;
        }

        // "...with naan/rice/chapati" → remember the included accompaniment and strip it, so it's
        // read as the choice (not a separate bread line). One-shot, consumed by maybeAskModifier.
        $this->modHint = '';
        if ($tenant->id && $this->tenantHasModifiers((int) $tenant->id)) {
            $names = $this->modifierOptionNames((int) $tenant->id);
            if ($names) {
                $alt = implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names));
                if (preg_match('/\bwith\s+(' . $alt . ')\b/i', $text, $mm)) {
                    $this->modHint = $mm[1];
                    $text = trim((string) preg_replace('/\bwith\s+' . preg_quote($mm[1], '/') . '\b/i', '', $text));
                }
            }
        }

        // A customer tapping "Confirm on WhatsApp" after a website checkout sends a fixed
        // message ("I just placed order ORD-NN on the website. Please confirm..."). Acknowledge
        // it instead of funnelling it into cart/shopping logic (which replied "basket is empty").
        if (($ack = $this->tryWebsiteOrderAck($tenant, $convo, $text)) !== null) {
            return $ack;
        }

        // A multi-line shopping list ("Jsk / 2 packet panipuri / 2 packet kachori / …") is a
        // direct order, not a greeting. Parse it deterministically and build the cart BEFORE the
        // NLU, so a leading "Jsk"/"hi" can't get the whole message classified as a greeting.
        if (($bulk = $this->tryBulkOrder($tenant, $convo, $text)) !== null) {
            return $this->maybeAskModifier($tenant, $convo) ?? $bulk;
        }

        // Try the LLM first; fall back to keywords.
        $action = $this->nlu->parse($tenant, $convo, $text);
        if ($action) {
            $reply = $this->execute($tenant, $convo, $action['intent'], $action['items'] ?? [], $action['note'] ?? '');
            return $this->maybeAskModifier($tenant, $convo) ?? $reply;
        }
        $reply = $this->keywordRespond($tenant, $convo, $text);
        return $this->maybeAskModifier($tenant, $convo) ?? $reply;
    }

    // ---------------- shared executor ----------------

    /**
     * Deterministic multi-line bulk order. Returns a cart reply when the message is a
     * quantity-led shopping list with ≥2 lines that match real products; otherwise null so
     * the normal NLU/keyword flow handles it. Never runs when a clarification / pending-order /
     * checkout step is active (those replies must reach their own handlers).
     */
    protected function tryBulkOrder(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        $st = is_array($convo->state) ? $convo->state : [];
        if (! empty($st['options']) || ! empty($st['pending_order']) || ! empty($st['pending_resolved'])
            || (($st['step'] ?? null) === 'awaiting_location') || (($st['step'] ?? null) === 'awaiting_confirm')) {
            return null;
        }

        if (! \App\Services\Bot\BulkOrderParser::looksLikeBulkOrder($text)) return null;
        $lines = \App\Services\Bot\BulkOrderParser::parseAll($text);
        if (count($lines) < 2) return null;

        $cart = is_array($convo->cart) ? $convo->cart : [];
        $added = []; $missed = [];
        foreach ($lines as $ln) {
            $p = $this->search->find($ln['query'])->first();
            if (! $p) { $missed[] = $ln['query']; continue; }
            $net  = Pricing::net($tenant, (float) $p->price);
            $cart = $this->addToCart($cart, $p->id, $p->name, $net, $ln['qty']);
            $added[] = "{$ln['qty']} x {$p->name}";
        }

        // Need at least 2 confident matches to treat this as a bulk order; otherwise fall through.
        if (count($added) < 2) return null;

        $convo->cart = $cart;
        $convo->save();
        \App\Support\BotTrace::log($tenant->id, 'bulk', (string) $convo->customer_phone, 'bulk_order', count($added) . ' items' . ($missed ? ' /' . count($missed) . ' missed' : ''));

        $head = "Added *" . implode('*, *', $added) . "*.";
        if ($missed) $head .= "\n(I couldn't find: " . implode(', ', $missed) . ". Tell me the name and I'll add it.)";
        return $head . "\n\n" . $this->cartSummary($tenant, $cart) . "\n\nAdd more, or say *checkout* to place the order.";
    }

    protected function tryWebsiteOrderAck(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        $lc = mb_strtolower($text);

        // High-precision: only a *past-tense* placed-order confirmation, not "I want to place an order".
        $isPlaced = preg_match('/\bplaced\s+(an?\s+)?order\b/u', $lc) === 1
            || (str_contains($lc, 'on the website') && (str_contains($lc, 'confirm') || str_contains($lc, 'order')));
        if (! $isPlaced) return null;

        // Pull an order number if present (ORD-12 / ORD 12 / ORD12).
        $ref = '';
        if (preg_match('/\bORD[-\s]?\d+\b/i', $text, $m)) {
            $ref = preg_replace('/^ORD[-\s]?/i', 'ORD-', strtoupper(trim($m[0])));
        }

        // Resolve the order (tenant-scoped) by number, else the customer's most recent (30 min).
        $order = null;
        if ($ref !== '') {
            $order = \App\Models\Order::where('order_no', $ref)->latest('id')->first();
        }
        if (! $order) {
            $phone = preg_replace('/[^0-9]/', '', (string) $convo->customer_phone);
            if ($phone !== '') {
                $order = \App\Models\Order::where('customer_phone', 'like', '%' . $phone)
                    ->where('created_at', '>=', now()->subMinutes(30))->latest('id')->first();
            }
        }

        $name   = ($order && $order->customer_name) ? ' ' . trim((string) $order->customer_name) : '';
        $refTxt = ($order && $order->order_no) ? " *{$order->order_no}*" : ($ref !== '' ? " *{$ref}*" : '');

        return "\u{2705} Thank you{$name}! We've received your website order{$refTxt} and our team will confirm and arrange delivery shortly. \u{1F69A}"
             . "\n\nNeed anything else? Tell me a product or say *menu*.";
    }

    protected function execute(Tenant $tenant, Conversation $convo, string $intent, array $items, string $note = ''): string
    {
        $cart = is_array($convo->cart) ? $convo->cart : [];

        switch ($intent) {
            case 'greet':
                $custom = trim((string) $tenant->setting('bot_greeting', ''));
                $g = $custom !== '' ? $custom
                    : "Hello \u{1F44B} Welcome to {$tenant->name}! Tell me what you'd like and I'll add it up. "
                    . "Say *cart* to see your basket or *checkout* when ready.";
                return $g . $this->websiteNudge($tenant);

            case 'view_cart':
                return $this->cartSummary($tenant, $cart) ?: "Your basket is empty. Tell me a product to add.";

            case 'clear':
                $convo->cart = []; $convo->save();
                return "Basket cleared. What would you like to order?";

            case 'checkout':
                if (! $cart) return "Your basket is empty. Add a product first, then say *checkout*.";
                if ($tenant->effectivePlan() === 'free' && $tenant->overOrderCap()) {
                    // Free plan hit its monthly order limit: don't auto-place,
                    // nudge the owner (once a day) and let a human take over.
                    if (\Illuminate\Support\Facades\Cache::add("cap_notice:{$tenant->id}:" . date('Y-m-d'), 1, 86400)) {
                        \App\Jobs\NotifyOwner::dispatch(
                            $tenant->id,
                            "\u{26A0} You've reached your " . $tenant->orderCap() . " free orders this month on CloudBSS. "
                            . "Upgrade to keep the bot taking orders automatically — open your panel and tap *Upgrade*."
                        );
                    }
                    return "Thank you! \u{1F64F} Please hold on — someone from the shop will confirm your order shortly.";
                }
                $st = is_array($convo->state) ? $convo->state : [];
                // Already have a pin volunteered before checkout? Place the order now using it —
                // don't make the customer send their location a second time.
                if (! empty($st['delivery_lat']) && ! empty($st['delivery_lng'])) {
                    return $this->placeOrder(
                        $tenant, $convo,
                        (string) ($st['delivery_area'] ?? 'your pinned location'),
                        (float) $st['delivery_lat'], (float) $st['delivery_lng'],
                        ((string) ($st['delivery_maps'] ?? '')) ?: null
                    );
                }
                $convo->state = array_merge($st, [
                    'step' => 'awaiting_location',
                    'checkout_token' => (string) Str::uuid(),   // 2C: seeds the order idempotency key
                ]);
                $convo->save();
                return $this->cartSummary($tenant, $cart)
                     . "\n\n\u{1F4CD} Please send your *delivery location* (area / landmark) to place the order.";

            case 'remove':
                $removed = [];
                foreach ($items as $it) {
                    $p = $this->search->find($it['query'])->first();
                    if (! $p) continue;
                    $cart = array_values(array_filter($cart, function ($l) use ($p, &$removed) {
                        if ($l['product_id'] === $p->id) { $removed[] = $l['name']; return false; }
                        return true;
                    }));
                }
                $convo->cart = $cart; $convo->save();
                $msg = $removed ? "Removed: ".implode(', ', $removed).".\n\n" : "I couldn't find that in your basket.\n\n";
                return $msg.($this->cartSummary($tenant, $cart) ?: "Your basket is now empty.");

            case 'add':
                $added = []; $missed = [];
                foreach ($items as $it) {
                    $p = $this->search->find($it['query'])->first();
                    if (! $p) { $missed[] = $it['query']; continue; }
                    $net = Pricing::net($tenant, (float) $p->price);
                    $cart = $this->addToCart($cart, $p->id, $p->name, $net, $it['qty']);
                    $added[] = "{$it['qty']} x {$p->name}";
                }
                $convo->cart = $cart; $convo->save();
                if (! $added && $missed) {
                    return "I couldn't find ".implode(', ', $missed).". Try another name, or say *cart* / *checkout*.";
                }
                $head = "Added *".implode('*, *', $added)."*.";
                if ($missed) $head .= "\n(I couldn't find: ".implode(', ', $missed).".)";
                return $head."\n\n".$this->cartSummary($tenant, $cart)."\n\nAdd more, or say *checkout*.";

            case 'search':
                $term = $items[0]['query'] ?? '';
                $results = $term ? $this->search->find($term) : collect();
                if ($results->isEmpty()) {
                    return ($note ?: "I couldn't find that.")."\nTell me a product name and I'll check.";
                }
                $lines = $results->take(6)->map(
                    fn ($p) => "• {$p->name} — ".Pricing::money($tenant, Pricing::net($tenant, (float) $p->price))
                )->implode("\n");
                return "Here's what I found:\n{$lines}\n\nSay *add <item>* to put it in your basket.";

            default: // unknown
                return ($note ?: "I can help you shop \u{1F6D2}").
                    "\nTell me a product to add, say *cart* to review, or *checkout* to finish.";
        }
    }

    // ---------------- deterministic brain (used when NLU is off/unavailable) ----------------
    // Ported from the production n8n workflow: multi-item, quantity-any-position,
    // synonyms, category, fuzzy matching, and clarify-on-ambiguity with numbered options.

    protected function keywordRespond(Tenant $tenant, Conversation $convo, string $text): string
    {
        $lc = mb_strtolower(trim($text));

        // ---- Build stamp ----  "version" / "build" reports exactly which code is live, plus the
        // server's last-write time of this file — the simplest way to confirm a deploy landed.
        if (in_array($lc, ['version', 'build', '!version', '!ping', 'diag'], true)) {
            return $this->versionStamp();
        }

        // ---- Session expiry & cart recovery ----------------------------------------
        // After 10 min idle, transient context (clarification options, last query,
        // checkout step) expires so an old session can't pollute a new one. A stored
        // cart survives: on return we ask continue-vs-fresh rather than silently
        // appending the new request to a stale cart. After 24 h the cart is discarded.
        if (($reentry = $this->handleSessionLifecycle($tenant, $convo, $text)) !== null) {
            return $reentry;
        }

        // ---- Conversation stage decomposition ----
        // A single message that spans several shopping stages ("Need rice / which is good? /
        // add 2 / checkout") is handled ONE stage at a time, like a shop attendant: keep only
        // the earliest stage and let the rest arrive as the customer's next messages. Skipped
        // when a clarification / read-back / location step is already in progress, because then
        // the message is a REPLY to that step, not a fresh multi-stage journey.
        $stStage   = is_array($convo->state) ? $convo->state : [];
        $inProgress = ! empty($stStage['options']) || ! empty($stStage['pending_order'])
            || ! empty($stStage['pending_resolved']) || (($stStage['step'] ?? null) === 'awaiting_location') || (($stStage['step'] ?? null) === 'awaiting_confirm');
        if (! $inProgress && \App\Services\Bot\ConversationStageAnalyzer::isMultiStage($text)) {
            $lead = \App\Services\Bot\ConversationStageAnalyzer::leadSegment($text);
            if ($lead !== '' && $lead !== $text) {
                $text = $lead;
                $lc   = mb_strtolower(trim($text));
            }
        }

        // ---- Pending order confirmation (read-back) ----
        // A wholesale list / best-guess was read back and is awaiting *OK*. This must run
        // BEFORE the command words below, because "confirm"/"ok" would otherwise be eaten by
        // the checkout/affirmation handlers. The engine commits on yes, drops on no, and on
        // anything else clears the proposal and lets us reprocess the message normally.
        $stPO = is_array($convo->state) ? $convo->state : [];
        if (! empty($stPO['pending_order'])) {
            $catalogue = $this->tenantCatalogue($tenant);
            $res = $this->runShoppingEngine($tenant, $convo, $text, $catalogue);
            if ($res['handled']) {
                $convo->cart  = $res['cart'];
                $convo->state = $res['state'];
                $convo->save();
                return $res['reply'];
            }
            // Not yes/no: the engine cleared the proposal; persist that and fall through so the
            // message is handled as a fresh request (new product, edit, greeting, etc.).
            $convo->state = $res['state'];
            $convo->save();
        }

        // ---- Conflicted multi-line message guard ----
        // One message packing add + remove/correction ("Add it / actually remove it / add X
        // instead") cannot be resolved safely line-by-line by a deterministic bot — doing so
        // silently removes the wrong product. Ask for one action at a time instead. A plain
        // multi-item ORDER is not conflicted and passes through to the wholesale read-back.
        if (\App\Services\Bot\MultiLineGuard::isConflicted($text)) {
            return \App\Services\Bot\MultiLineGuard::prompt();
        }

        // ---- Cart management (remove / clear / change quantity, by number or name) ----
        // Explicit cart commands take priority and must never product-search.
        if (\App\Services\Bot\CartEditor::isEditIntent($lc)) {
            if (($edit = $this->tryCartEdit($tenant, $convo, $text)) !== null) {
                return $edit;
            }
        }

        // ---- Discovery context (multi-message recommendation flow) ----
        // "Need rice" then "Not basmati" then "Family of 5" build ONE growing context in
        // state.discovery and produce a recommendation, instead of each message being parsed
        // (and searched) on its own. Wins over product search; breaks out the moment the
        // customer places a concrete order line ("5 coke") or selects/checks out.
        if (($disc = $this->tryDiscovery($tenant, $convo, $text)) !== null) {
            return $disc;
        }

        // ---- Follow-up within the active product/category context ----
        // "more brands", "other options", "larger size", "cheaper one" continue the last
        // list rather than searching for the literal words. Must run before the pending
        // selection check (a follow-up phrase is never a number), but a numeric reply (a
        // real selection) is left untouched.
        if (($fu = $this->tryFollowUp($tenant, $convo, $text)) !== null) {
            return $fu;
        }

        // ---- Human shopkeeper layer: opinion / doubt / comparison ----
        // "which one is good?", "are you sure?", "which is better, X or Y?" are answered like a
        // shop attendant (recommend / reaffirm / compare — on real data), not with a product
        // search. It returns null for everything else (numbers, orders, greetings, plain product
        // names) so the normal flow below is untouched.
        if (($sa = $this->trySalesAssistant($tenant, $convo, $text)) !== null) {
            return $sa;
        }

        // ---- Active clarification takes priority ----
        // If we previously showed options, a reply like "1" or "1 2 3" must resolve
        // that selection — it must NOT be swallowed by the greeting/affirmation/intent
        // checks below (which would otherwise treat a bare number as "unknown").
        $state0 = is_array($convo->state) ? $convo->state : [];
        if (! empty($state0['options']) && is_array($state0['options'])) {
            $catalogue = $this->tenantCatalogue($tenant);
            $res = $this->runShoppingEngine($tenant, $convo, $text, $catalogue);
            if ($res['handled']) {
                $convo->cart  = $res['cart'];
                $convo->state = $res['state'];
                $convo->save();
                return $res['reply'];
            }

            // ---- Intent Override Layer ----
            // The reply isn't a selection. A delivery/price/business/location/greeting/decline/
            // checkout/availability message must be answered — NOT met with "reply with a number".
            // Informational answers keep the option list live so the customer can still pick.
            if (($ov = $this->pendingOverride($tenant, $convo, $text, $lc, $catalogue)) !== null) {
                return $ov;
            }

            // A fresh PRODUCT ORDER arriving mid-clarification must not be matched against the
            // stale list, nor nagged for a number. Abandon the old options and reprocess it as a
            // new message — this is the core fix for state contamination across turns.
            if ($this->isFreshProductRequest($text, $catalogue)) {
                $st = is_array($convo->state) ? $convo->state : [];
                unset($st['options'], $st['pending_resolved'], $st['pending_order'], $st['last_recommended'], $st['discovery']);
                $convo->state = $st;
                $convo->save();
                // fall through to normal processing below (no return)
            } else {
                // Genuinely ambiguous (no recognised intent): keep options and re-prompt.
                return "Please reply with the *number* you want from the list above (e.g. *1*, or *1 2 3*) — or type a product name to search again.";
            }
        }

        // command words win before shopping parsing
        if (\App\Services\Bot\IntentClassifier::isLocationHelp($lc)) return $this->locationHelpReply();
        if (\App\Services\Bot\GreetingDictionary::isGreeting($lc)) return $this->greetingReply($tenant, $text);
        if (in_array($lc, ['cart','basket','my order','my cart','view cart'], true))           return $this->execute($tenant, $convo, 'view_cart', []);
        if (in_array($lc, ['clear','empty','reset','clear cart','empty cart'], true))           return $this->execute($tenant, $convo, 'clear', []);
        if (\App\Services\Bot\IntentClassifier::looksLikeCheckout($lc)) return $this->execute($tenant, $convo, 'checkout', []);

        // ---- Daily set-meal (thali) ----
        $thaliCfg = (array) ($tenant->setting('thali', []) ?: []);
        if (\App\Services\Bot\ThaliMenu::enabled($thaliCfg)) {
            if (\App\Services\Bot\ThaliMenu::isMenuQuery($lc)) {
                return $this->thaliMenuReply($tenant, $thaliCfg, $lc);
            }
            if (($this->cartHasThali($convo) || str_contains($lc, 'thali')) && \App\Services\Bot\ThaliMenu::isModification($lc)) {
                return $this->thaliModificationReply($tenant, $convo, $text);
            }
        }
        // affirmations: a bare "good/ok/yes" is chat, not a product search
        if (in_array($lc, ['ok','okay','yes','yeah','yep','good','nice','cool','great','thanks','thank you','thx','sure','fine'], true)) {
            return "\u{1F44D} Great! Tell me what you'd like, say *cart* to review, or *checkout* when ready.";
        }

        // Social / coordination chit-chat (pickup & delivery talk, time-of-day greetings) —
        // reply briefly and warmly, never with the "tell me a product" pitch.
        if (($social = $this->socialReply($lc)) !== null) {
            return $social;
        }

        // Gujlish (romanised Gujarati) — most Pal's customers chat this way. Catch the common
        // declines / affirmations / thanks / chit-chat and reply in Gujlish, instead of the
        // robotic English intro ("Aaj vakhte nathi levanu" -> "Vandho nahi!").
        if (($guj = $this->gujlishReply($lc, $tenant)) !== null) {
            return $guj;
        }

        // Bare delivery time ("7 pm", "evening", "sanje") or a location reference ("this location",
        // "deliver here") — capture as delivery info instead of product-searching it.
        if (($dd = $this->deliveryDetailReply($tenant, $convo, $lc)) !== null) {
            return $dd;
        }

        // Standalone cooking instruction ("less spicy", "no onion", "make it mild").
        if (($ir = $this->instructionReply($tenant, $convo, $lc)) !== null) {
            return $ir;
        }

        // "What is X?" / "what's in X?" -> read back the menu description (restaurant).
        if (($pi = $this->productInfoReply($tenant, $convo, $lc)) !== null) {
            return $pi;
        }

        // declines: "no", "cancel", "i don't want anything" etc. are NOT product
        // searches — never run them through the catalogue (that's what matched
        // "Dent"/"Donut"/"Dot" for "i dont want anything").
        $declineExact = ['no','nope','nah','cancel','stop','nothing','none','not interested',
            'no thanks','no thank you','nothing else','no more','thats all','that\'s all',
            'im good','i\'m good','nahi','kuch nahi'];
        $declineNorm = preg_replace('/[^a-z\s]/', '', $lc);
        if (in_array($lc, $declineExact, true) || in_array($declineNorm, $declineExact, true)
            || preg_match('/\b(dont|do not|not)\s+want\b/', $declineNorm)
            || str_contains($declineNorm, 'not interested')) {
            $hasCart = is_array($convo->cart) && count($convo->cart) > 0;
            return $hasCart
                ? "No problem \u{1F642} Whenever you're ready, tell me another product, say *cart* to review, or *checkout* to finish."
                : "No problem \u{1F642} Whenever you're ready, just tell me a product you'd like and I'll help you shop.";
        }

        // ---- Past payment / credit (khata) settlement ----
        // Credit shops get a lot of "I'll send money for last week / please confirm" talk.
        // The bot can't verify payments, so it must never run this through the catalogue
        // (which produced "we don't stock confirm"). Acknowledge warmly and alert the shop
        // to confirm the customer's account by hand.
        $payState  = is_array($convo->state) ? $convo->state : [];
        $payRecent = (time() - (int) ($payState['pay_alerted_at'] ?? 0)) < 1800;
        if ($this->looksLikePayment($lc)
            || ($payRecent && (bool) preg_match('/\b(confirm|confirmed|done|received|sent|paid|ok)\b/', $lc))) {
            if ((time() - (int) ($payState['pay_alerted_at'] ?? 0)) > 1800) {
                \App\Jobs\NotifyOwner::dispatch($tenant->id,
                    "\u{1F4B0} +{$convo->customer_phone} is talking about a payment: \""
                    . trim(mb_substr($text, 0, 120)) . "\". Open Chats to confirm their account.");
            }
            $payState['pay_alerted_at'] = time();
            $convo->state = $payState;
            $convo->save();
            return "\u{1F64F} Thank you! I've let the shop know \u{2014} they'll check and confirm your payment shortly. "
                 . "Is there anything else I can help you with?";
        }

        // ---- Intent classification (runs BEFORE any catalogue search) ----
        // The bot is a shop assistant, not a search engine: conversational messages
        // (feedback / greeting / thanks / questions / gibberish) must never search.
        $catalogue = $this->tenantCatalogue($tenant);

        // Direct category-name browse: "dry fruits", "snacks", "masala", "mewa" -> list that
        // category's items. Runs before intent classification so a question framing
        // ("do you have dry fruits?") still lists the category instead of escalating.
        if (($catList = $this->tryCategoryName($tenant, $convo, $text, $catalogue)) !== null) {
            return $catList;
        }

        $intent = IntentClassifier::classify($text, IntentClassifier::tokenSetFromProducts($catalogue));

        switch ($intent) {
            case IntentClassifier::FEEDBACK:
                return "\u{1F64F} Thank you for the feedback! Glad it's working well for you. Whenever you're ready, just tell me what you'd like to order.";
            case IntentClassifier::THANKS:
                return "\u{1F60A} You're welcome! Anything else you'd like to order? Say *cart* to review or *checkout* when ready.";
            case IntentClassifier::GREETING:
                return $this->greetingReply($tenant, $text);
            case IntentClassifier::QUESTION:
                return \App\Services\Bot\FaqDictionary::match($text, $this->faqContext($tenant))
                    ?? $this->forwardQuestionToShop($tenant, $convo, $text);
            case IntentClassifier::HUMAN_AGENT:
                $convo->agent_active = true;
                $convo->save();
                \App\Jobs\NotifyOwner::dispatch($tenant->id, "\u{1F64B} +{$convo->customer_phone} asked to speak with a person. Open Chats to take over.");
                return "\u{1F642} Sure — I'm letting the shop know. Someone will reply here shortly. Meanwhile you can keep typing your order if you like.";
            case IntentClassifier::CATALOG:
                return $this->catalogResponse($tenant);
            case IntentClassifier::BUSINESS:
                return $this->businessResponse($tenant, IntentClassifier::businessKind($lc), $text);
            case IntentClassifier::CATEGORY:
                return $this->categoryResponse($tenant, $convo, $text);
            case IntentClassifier::PRICE:
                return $this->priceResponse($tenant, IntentClassifier::priceQuery($lc) ?? $text);
            case IntentClassifier::SHOP_START:
                return "\u{1F6D2} Great! What would you like to order today?\n"
                     . "Examples: *Rice 5kg*, *Sugar 2kg*, *Milk 1 litre*, *Bread 2 pcs*.\n"
                     . "You can send several items in one message.";
            case IntentClassifier::LOCATION:
                return $this->captureLocation($tenant, $convo, $text);
            case IntentClassifier::UNKNOWN:
                // Don't dead-end. An "unknown" message often still names a product behind question
                // framing or a typo ("ou have balaji masala wafer in stock"). Try the FAQ, then
                // fall through to category-browse + product search; only if those also find nothing
                // does the "don't stock / didn't catch" fallback below apply.
                if (($faq = \App\Services\Bot\FaqDictionary::match($text, $this->faqContext($tenant))) !== null) {
                    return $faq;
                }
                break;
            // CART / CHECKOUT / DECLINE are already handled above; anything else is SHOPPING.
        }

        // Broad multi-brand browse ("india gate rice chenab ... ravi rice mb") -> show only the
        // dominant category's products, never unrelated items that collide on noise tokens.
        if (($browse = $this->tryCategoryBrowse($tenant, $convo, $text, $catalogue)) !== null) {
            return $browse;
        }

        // hand off to the deterministic shopping engine
        $result = $this->runShoppingEngine($tenant, $convo, $text, $catalogue);

        if ($result['handled']) {
            $convo->cart  = $result['cart'];
            $convo->state = $result['state'];
            $convo->save();
            return $result['reply'];
        }

        // No catalogue match. Say "we don't stock X" ONLY when X really looks like a product
        // term — never echo a greeting or a natural-language question back to the customer
        // (no "we don't stock *hello will you be coming tomorrow*").
        $want = trim(preg_replace('/\b(do you (have|sell|stock)|have you got|got any|any|looking for|i (want|need)|please|pls)\b/i', ' ', mb_strtolower($text)));
        $want = trim(preg_replace('/\s+/', ' ', $want));
        $wantWords = $want === '' ? [] : preg_split('/\s+/', $want, -1, PREG_SPLIT_NO_EMPTY);
        $conversational = '/\b(hi|hii|hey|hello|helo|hiya|yo|hola|salaam|salam|will|would|can|could|are|is|am|was|were|you|your|u|when|what|why|how|who|tomorrow|today|tonight|now|later|soon|coming|come|reach|open|closed?|deliver|delivery|time|hours?|there|available|reply|call|phone|number|whatsapp|thanks|thank|confirm|confirmed|dish|dishes|plate|plates|paid|pay|payment|money|balance|sent|send|received|done|noted|please|ok|okay)\b/i';
        if ($want !== '' && count($wantWords) <= 4 && preg_match('/[a-z]/i', $want) && ! preg_match($conversational, $want)) {
            \App\Support\BotMiss::record($tenant->id, $want, $text);
            return "Sorry, we don't stock *{$want}* right now \u{1F642} Tell me another product, or say *menu* to see what we have.";
        }

        // Conversational / off-topic message: try the FAQ, then forward genuine questions to
        // the shop; otherwise a warm catch-all that never blames the wording.
        if (($faq = \App\Services\Bot\FaqDictionary::match($text, $this->faqContext($tenant))) !== null) {
            return $faq;
        }
        if (preg_match('/\?|\b(will|would|can|could|do|does|are|is|when|what|how|why|provide|deliver|delivery|open|close|closed|available|price|cost|charge|free|refund|return|exchange)\b/i', $text)) {
            return $this->forwardQuestionToShop($tenant, $convo, $text);
        }
        return "\u{1F44B} Hi! I'm {$tenant->name}'s ordering assistant. Tell me a product like *rice* or *sugar*, or say *menu* to see what we have \u{2014} and for anything else, the shop will reply here shortly.";
    }

    /**
     * Answer a price question ("how much is X") with the matching product price(s).
     * Never mutates the cart. Falls back to a clear "not stocked" message on a miss.
     */
    protected function priceResponse(Tenant $tenant, string $query): string
    {
        $query = trim($query);
        $cat   = $this->tenantCatalogue($tenant);
        $cands = (new \App\Services\Bot\CatalogueMatcher())->search($query, $cat);
        $cur   = $this->currencyFor($tenant);

        if (! $cands) {
            \App\Support\BotMiss::record($tenant->id, $query, $query);
            return "Sorry, I couldn't find *{$query}* \u{1F642} We may not stock it. Tell me another product, or say *menu* to browse.";
        }

        $prods = array_slice(array_map(fn ($c) => $c['product'], $cands), 0, 6);

        if (count($prods) === 1) {
            $p = $prods[0];
            $desc = trim((string) ($p['description'] ?? ''));
            $line = "*{$p['name']}* is {$cur} " . number_format((float) $p['price'], \App\Services\Pricing::decimalsForCurrency($cur)) . '.';
            if ($desc !== '') $line .= "\n_{$desc}_";
            return $line . "\n\nWant me to add it? Just say *add {$p['name']}* or tell me the quantity.";
        }

        $lines = [];
        foreach ($prods as $p) {
            $lines[] = "• {$p['name']} — {$cur} " . number_format((float) $p['price'], \App\Services\Pricing::decimalsForCurrency($cur));
        }
        return "Here are the prices for *{$query}*:\n" . implode("\n", $lines)
             . "\n\nTell me which one you'd like, or the quantity.";
    }

    /**
     * Localised greeting reply based on the detected language of the customer's greeting.
     * Never product-searches. Falls back to the tenant's custom greeting for English.
     */
    /**
     * The bot can't answer a customer question: actually forward it to the shop owner
     * (debounced to once per ~10 min per conversation so we don't spam), and tell the
     * customer truthfully that it's been passed on. The message is also in Chats.
     */
    protected function forwardQuestionToShop(Tenant $tenant, $convo, string $text): string
    {
        $st   = is_array($convo->state) ? $convo->state : [];
        $last = (int) ($st['q_pinged_at'] ?? 0);
        if (time() - $last > 600) {
            $q = trim(mb_substr(trim($text), 0, 160));
            \App\Jobs\NotifyOwner::dispatch(
                $tenant->id,
                "\u{2753} +{$convo->customer_phone} asked: \"{$q}\"\nOpen Chats to reply."
            );
            $st['q_pinged_at'] = time();
            $convo->state = $st;
            $convo->save();
        }
        return "\u{1F642} Good question \u{2014} I've passed it to the shop and they'll reply here shortly. "
             . "Meanwhile you can keep adding items, or say *menu* to see what we have.";
    }

    /** True if the active cart already contains a thali line. */
    protected function cartHasThali($convo): bool
    {
        if (! is_array($convo->cart)) return false;
        foreach ($convo->cart as $line) {
            $n = is_array($line) ? ($line['name'] ?? '') : (string) $line;
            if (stripos((string) $n, 'thali') !== false) return true;
        }
        return false;
    }

    /** Answer "what's in today's / Monday's thali" with the day's set menu. */
    protected function thaliMenuReply(Tenant $tenant, array $cfg, string $lc): string
    {
        $tz  = (string) $tenant->setting('timezone', 'Africa/Kampala');
        $cur = $this->currencyFor($tenant);
        $explicitDay     = \App\Services\Bot\ThaliMenu::dayFromText($lc);
        $explicitSession = \App\Services\Bot\ThaliMenu::sessionFromText($lc);
        $rollover = false;
        if ($explicitDay !== null) {
            $day = $explicitDay; $session = $explicitSession ?? 'day';
        } elseif ($explicitSession !== null) {
            $day = \App\Services\Bot\ThaliMenu::todayKey($tz); $session = $explicitSession;
        } else {
            $ctx = \App\Services\Bot\ThaliMenu::effective($cfg, $tz);
            $day = $ctx['day']; $session = $ctx['session']; $rollover = $ctx['rollover'];
        }

        $body = \App\Services\Bot\ThaliMenu::render($cfg, $day, $cur, $session);
        if ($rollover) {
            $body = "\u{1F319} Tonight's kitchen is closed \u{2014} here's *tomorrow's* lunch:\n\n" . $body;
        }
        return $body . $this->websiteThaliNudge($tenant, $cfg);
    }

    /**
     * Brief, warm replies for social / coordination chit-chat (pickup & delivery talk,
     * "good morning", "how are you", "see you"). Returns null when it's not small talk,
     * so the message continues to the normal shopping path.
     */
    /**
     * Gujlish (romanised Gujarati) replies for the common non-product intents Pal's
     * customers use — declines, affirmations, thanks, chit-chat. Product words (kaju,
     * badam…) deliberately return null so they continue to the catalogue search.
     */
    protected function gujlishReply(string $lc, Tenant $tenant): ?string
    {
        $shop = trim((string) $tenant->name) !== '' ? $tenant->name : 'amari dukan';

        // Decline / not now / next time  ("aaj vakhte nathi levanu", "have nathi", "pachi", "next time")
        if (preg_match('/\b(nathi|nthi|nai)\b[^.]*\b(levanu|levu|joiye|joitu|joi tu|lewu|joeye)\b/', $lc)
            || preg_match('/\b(aaje?|atyare|have|hamna|hamnaa)\s+(nahi|nathi|nai)\b/', $lc)
            || preg_match('/\b(pachi|paachi|baad ma|next time)\b/', $lc)
            || preg_match('/\bnathi joi/', $lc)) {
            return "\u{1F642} Vandho nahi! Jyare joiye tyare kaho \u{2014} {$shop} hammesha taiyar che. Aabhar! \u{1F64F}";
        }
        // Greeting (also caught earlier as a greeting; kept as a safety net)
        if (preg_match('/\b(kem cho|kemcho|kem chho|kaa cho|jai shree krishna|jai shri krishna|jsk|namaste|namaskar|ram ram|jai mataji)\b/', $lc)) {
            return "\u{1F64F} Jai Shree Krishna! {$shop} ma swagat che. Aaje su joiye che? Menu jova mate *menu* lakho.";
        }
        // How are you / chit-chat
        if (preg_match('/\b(majama|maja ma|su chale|shu chale|kem chale|kem ave che)\b/', $lc)) {
            return "\u{1F60A} Majama! Tame kao, aaje su joiye che? Menu jova *menu* lakho.";
        }
        // Affirmation
        if (preg_match('/^\s*(ha+|haan|saru|saaru|barabar|barobar|theek che|thik che|theek|thik|chalse|ok che|bahu saru)\s*[!.]*$/', $lc)) {
            return "\u{1F44D} Saru! Su joiye che e kaho, athva *cart* jova mate lakho.";
        }
        // Thanks
        if (preg_match('/\b(aabhar|abhar|dhanyavaad|dhanyavad|dhanyvad)\b/', $lc)) {
            return "\u{1F64F} Aabhar! Phari malsho. Kai pan joiye to kaho.";
        }
        // Positive buy intent with NO product named ("levanu che", "joiye che", "lena hai").
        // Anchored to the whole message so "kaju levanu che" still goes to catalogue search,
        // and guarded against negation so "nathi levanu" stays a decline.
        if (! preg_match('/\b(nathi|nthi|nai|nahi)\b/', $lc)
            && preg_match('/^(hu |me |mare |mane |mara |kaik |kainck |kainik |kuch |thodu |saman )*'
                . '(levanu|levu|lewu|leva|joiye|joie|joitu|joeye|kharidvu|khareedvu|kharidva|lena|khareedna)'
                . '( che| chhe| chee| hai| chu| joiye che)?\s*$/u', $lc)) {
            return "\u{1F44D} Saru! Su joiye che e kaho \u{2014} product nu naam lakho, athva *menu* lakho.";
        }
        // "(this) is not on the bill" / billing mistake -> clarify, never product-search.
        if (preg_match('/\bbill\s*(ma|may|me|mein)?\s*(nathi|nthi|nahi|nai)\b/', $lc)
            || preg_match('/\b(bill|hisab|hisaab)\b[^.]*\b(khoto|khotu|wrong|galat|gadbad)\b/', $lc)) {
            return "\u{1F9FE} Ek minute \u{2014} kaya item ni gadbad che e kaho. Tamaru basket jova mate *cart* lakho.";
        }
        // "ha bolo" / "bolo" -> go ahead, I'm listening.
        if (preg_match('/^(ha+|haa|haan)?\s*(bolo|bol|kaho|kao)\s*[!.]*$/u', $lc)) {
            return "\u{1F642} Ha kaho! Aaje su joiye che?";
        }
        // "I was out / busy" -> reassure, no product search ("hu bar hati", "kaam ma hato").
        if (preg_match('/\b(bahar|baar|bar)\s+(hati|hato|hto|gayo|gaya|gayi|chu|chhu)\b/', $lc)
            || preg_match('/\b(busy|kaam ma|kam ma)\b/', $lc)) {
            return "\u{1F642} Vandho nahi! Jyare free hov tyare order kaho.";
        }
        return null;
    }

    /** Up to N distinct category names from the shop's catalogue, joined for a greeting line. */
    protected function shopCategories(Tenant $tenant, int $n = 3): string
    {
        $cats = [];
        foreach ($this->tenantCatalogue($tenant) as $p) {
            $c = trim((string) ($p['category'] ?? ''));
            if ($c !== '' && ! in_array($c, $cats, true)) $cats[] = $c;
            if (count($cats) >= $n) break;
        }
        if (! $cats) return '';
        if (count($cats) === 1) return $cats[0];
        $last = array_pop($cats);
        return implode(', ', $cats) . ' & ' . $last;
    }

    protected function deliveryDetailReply(Tenant $tenant, Conversation $convo, string $lc): ?string
    {
        $lc = trim($lc);
        $st = is_array($convo->state) ? $convo->state : [];
        $hasCart = is_array($convo->cart) && count($convo->cart) > 0;

        // --- bare delivery TIME: "7 pm", "7:30pm", "19:30", "after 6", "evening", "sanje" ---
        $timeRe = '/^(?:'
            . '(?:at|by|after|before|around|aaje|aaj)?\s*(?:1[0-2]|0?[1-9])(?::[0-5]\d)?\s*(?:am|pm)'
            . '|(?:[01]?\d|2[0-3]):[0-5]\d'
            . '|(?:at|by|after|before|around)\s+(?:1?\d)(?:\s*o.?clock)?'
            . '|(?:morning|afternoon|evening|night|noon|midday|tonight)'
            . '|(?:savare|sawre|bapore|sanje|saanje|raat|raate|ratre|aaje sanje|kale)'
            . ')\s*(?:thi|sudhi|vagye|vage|baad|pachi|o.?clock|onwards?)?\s*[!.]*$/u';
        if (preg_match($timeRe, $lc)) {
            $when = trim(preg_replace('/[!.]+$/', '', $lc));
            $convo->state = array_merge($st, ['delivery_time' => $when]);
            $convo->save();
            if ($hasCart) {
                return "\u{1F552} Got it \u{2014} I'll note delivery around *{$when}*. Add anything else, or say *checkout* to place the order.";
            }
            return "\u{1F552} Noted \u{2014} *{$when}* for delivery. Tell me what you'd like to order \u{1F642}";
        }

        // --- LOCATION reference: "this location", "deliver here", "same address", "ahiya" ---
        $locRefs = ['this location','on this location','on this','here','deliver here','deliver it here',
            'deliver to this location','at this location','same location','same address','same place',
            'my location','this place','this address','ahiya','ahi','aahiya','aa jagya','aa jagyae','ahin','ahija'];
        if (in_array($lc, $locRefs, true)) {
            if (! empty($st['delivery_lat']) && ! empty($st['delivery_lng'])) {
                return "\u{1F4CD} Got it \u{2014} same pinned location. "
                    . ($hasCart ? "Say *checkout* to place the order." : "Tell me your order and I'll deliver there.");
            }
            return "\u{1F4CD} Sure! Please share your location pin so we can deliver \u{2014} tap *+* (or the \u{1F4CE}) in WhatsApp \u{2192} *Location* \u{2192} *Send your current location*. Or just type your area name.";
        }

        return null;
    }

    protected function socialReply(string $lc): ?string
    {
        $lc = trim($lc);
        if (in_array($lc, ['by mistake','by mistek','mistake','wrong one','wrong item','galti se','galati se','bhul thi','bhulthi','bhul thai'], true)
            || preg_match('/\bby mistake\b/', $lc)) {
            return "\u{1F642} No problem! Tell me which item to remove \u{2014} say *remove <product>* \u{2014} or *cart* to see your basket.";
        }
        // "message me" / contact request — keep them shopping here, offer a person if needed.
        if (in_array($lc, ['message me','mujhe message karna','message karna','msg me','text me','call me later'], true)) {
            return "\u{1F642} Sure \u{2014} just tell me what you need here and I'll help. To talk to a person, say *agent*.";
        }
        // coordination: customer is coming / on the way / will reach
        if (preg_match('/\b(on my way|omw|i.?m coming|i am coming|coming now|let me come|i.?ll come|i will come|i.?m reaching|i am reaching|reaching|on the way|i.?ll be there|be there soon|coming over|just coming)\b/', $lc)
            || in_array($lc, ['ok coming','ok i come','let me come','coming','i come'], true)) {
            return "\u{1F44D} Sure, see you soon! I'll have your order ready.";
        }
        // see you / bye-style sign off
        if (preg_match('/\b(see you|see u|c u|catch you|talk later|bye|good night|goodnight)\b/', $lc)) {
            return "\u{1F44B} See you! We're here whenever you need us.";
        }
        // time-of-day greetings (the plain "hi/hello" set is handled as GREETING elsewhere)
        if (preg_match('/\b(good (morning|afternoon|evening)|gud (morning|mrng)|shubh|kem cho|kemcho|namaste|salaam|salam)\b/', $lc)) {
            return "\u{1F31E} Hello! How can I help you today? Tell me a product or say *menu* to see what we have.";
        }
        // "how are you" style
        if (preg_match('/\b(how are you|how r u|how are u|hw r u|kaise ho|kem cho|how is it going)\b/', $lc)) {
            return "\u{1F60A} I'm doing well, thank you! What can I get for you today?";
        }
        return null;
    }

    /**
     * Detect talk about money / credit settlement ("I'll send money for last week",
     * "I paid", "my balance", "khata", "MoMo") — but NOT a "how do I pay?" question.
     * Such messages must skip the catalogue so the bot never says "we don't stock confirm".
     */
    protected function looksLikePayment(string $lc): bool
    {
        // future intent — needs will / going to (so plain "how do i pay" is excluded)
        if (preg_match('/\b(i ?\'?ll|i will|today i will|i am going to|i\'?m going to|gonna)\s+(pay|send|deposit|transfer)\b/', $lc)) return true;
        // stated fact — paid / sent / paying / sending
        if (preg_match('/\b(paid|sent the money|sent money|paying|sending money|transferred|deposited)\b/', $lc)) return true;
        // money movement
        if (preg_match('/\b(send|sending|sent|transfer(red|ring)?|deposit(ed|ing)?)\b[^.]*\b(money|cash|payment|amount|balance)\b/', $lc)) return true;
        // account / credit words
        if (preg_match('/\b(my )?(payment|balance|khata|khaata|udhaar|udhar|hisab|hisaab|baki|baaki|dues?|outstanding)\b/', $lc)) return true;
        if (preg_match('/\bmoney for (last|the)\b/', $lc)) return true;
        if (preg_match('/\blast (week|month)\b[^.]*\b(money|pay|paid|payment|bill|balance|due)\b/', $lc)) return true;
        if (preg_match('/\b(momo|mobile money|airtel money|mtn money)\b/', $lc)) return true;
        return false;
    }

    /**
     * Menu-specific website pointer for lunch/dinner questions (custom-domain shops).
     * Because this already contains the domain, the global website append is skipped,
     * so the customer sees this tailored line instead of the generic "browse & order".
     */
    protected function websiteThaliNudge(Tenant $tenant, array $cfg): string
    {
        if ((string) $tenant->setting('bot_website_link', '1') === '0') return '';
        $dom = trim((string) $tenant->custom_domain);
        if ($dom === '') return '';
        $both = \App\Services\Bot\ThaliMenu::hasNight($cfg);
        $what = $both ? "lunch & dinner menu (switch between them)" : "full menu with photos";
        return "\n\n\u{1F37D}\u{FE0F} See the {$what} on our website: https://{$dom}";
    }

    /** A set-meal can't be re-priced by the bot — capture the change, echo it, promise a call, tell the shop. */
    protected function thaliModificationReply(Tenant $tenant, $convo, string $text): string
    {
        $note = trim(preg_replace('/\s+/', ' ', $text));

        // Attach the note to the thali line if it's already in the cart; otherwise stash it for checkout.
        $cart = is_array($convo->cart) ? $convo->cart : [];
        $attached = false;
        foreach ($cart as &$l) {
            if (stripos((string) ($l['name'] ?? ''), 'thali') !== false) {
                $prev = trim((string) ($l['note'] ?? ''));
                $l['note'] = $prev !== '' ? ($prev . '; ' . $note) : $note;
                $attached = true;
            }
        }
        unset($l);
        if ($attached) {
            $convo->cart = $cart;
        } else {
            $st = is_array($convo->state) ? $convo->state : [];
            $prev = trim((string) ($st['thali_note'] ?? ''));
            $st['thali_note'] = $prev !== '' ? ($prev . '; ' . $note) : $note;
            $convo->state = $st;
        }
        $convo->save();

        $this->forwardQuestionToShop($tenant, $convo, 'Thali change request: ' . $note);

        $p = \App\Services\Bot\ThaliMenu::parseModification($text);
        $bits = '';
        if ($p['remove']) $bits .= "\n\u{2796} Remove: " . implode(', ', $p['remove']);
        if ($p['add'])    $bits .= "\n\u{2795} Add: " . implode(', ', $p['add']);
        if ($bits === '') $bits = "\n\u{201C}" . $note . "\u{201D}";

        return "\u{1F44C} Got it — noted for your thali:" . $bits
             . "\n\nIt's a set meal, so our team will *call you before dispatch* to confirm this and any price difference. "
             . "Say *add thali* to put it on your order, or *checkout* when you're ready.";
    }

    /** Append the day's dishes and any customer note to thali line names (for the kitchen/record). */
    protected function stampThaliDishes(Tenant $tenant, array $cart): array
    {
        $cfg   = (array) ($tenant->setting('thali', []) ?: []);
        $label = '';
        if (\App\Services\Bot\ThaliMenu::enabled($cfg)) {
            $tz    = (string) $tenant->setting('timezone', 'Africa/Kampala');
            $day   = \App\Services\Bot\ThaliMenu::todayKey($tz);
            $items = $cfg['days'][$day] ?? [];
            $items = is_array($items) ? array_values(array_filter(array_map('trim', $items))) : [];
            if ($items) $label = \App\Services\Bot\ThaliMenu::dayName($day) . ': ' . implode(', ', $items);
        }
        foreach ($cart as &$line) {
            $n = (string) ($line['name'] ?? '');
            if (stripos($n, 'thali') === false) continue;
            $add = '';
            if ($label !== '' && strpos($n, '(') === false) $add .= ' (' . $label . ')';
            $note = trim((string) ($line['note'] ?? ''));
            if ($note !== '' && stripos($n, 'note:') === false) $add .= ' · Note: ' . $note;
            if ($add !== '') $line['name'] = $n . $add;
        }
        unset($line);
        return $cart;
    }

    protected function greetingReply(Tenant $tenant, string $text): string
    {
        $shop = $tenant->name;
        $d    = \App\Services\Bot\GreetingDictionary::detect($text) ?? ['lang' => 'en', 'kind' => 'greet'];

        if ($d['kind'] === 'smalltalk') {
            return "\u{1F642} I'm here and ready! What can I get for you today at {$shop}?";
        }

        switch ($d['lang']) {
            case 'sw':
                $msg = "Habari \u{1F60A} Karibu {$shop}.\nWhat would you like today?";
                break;
            case 'lg':
                $msg = "Bulungi \u{1F60A}\nWhat can I get for you today?";
                break;
            case 'ar':
                $msg = "Salaam \u{1F44B}\nWelcome to {$shop}.\nHow can I help you today?";
                break;
            case 'in':
                if (preg_match('/krishna/i', $text)) {
                    $msg = "\u{1F64F} Jai Shree Krishna! Welcome to {$shop} — what would you like today?";
                } else {
                    $msg = "Namaste \u{1F64F}\nWelcome to {$shop}! What would you like today?";
                }
                break;
            case 'en':
            default:
                $custom = trim((string) $tenant->setting('bot_greeting', ''));
                $msg = $custom !== '' ? $custom
                    : "Hello \u{1F44B} Welcome to {$shop}! Tell me what you'd like and I'll add it up. "
                    . "Say *cart* to see your basket or *checkout* when ready.";
                break;
        }

        $cats = $this->shopCategories($tenant);
        if ($cats !== '') {
            $msg .= ($d['lang'] === 'in')
                ? "\n\nAmari pase *{$cats}* ane biju ghanu che \u{2014} *menu* lakho."
                : "\n\nWe stock *{$cats}* and more \u{2014} say *menu* to see everything.";
        }

        return $msg . $this->websiteNudge($tenant);
    }

    /** The shop's public storefront URL: custom domain if set, else the mycloudbss slug URL. */
    protected function storefrontUrl(Tenant $tenant): string
    {
        $dom = trim((string) $tenant->custom_domain);
        if ($dom !== '') return 'https://' . $dom;
        $slug = trim((string) $tenant->slug);
        return $slug !== '' ? 'https://mycloudbss.com/' . $slug : '';
    }

    /** A short "order on our website" line — only for shops that have their own domain. */
    protected function websiteNudge(Tenant $tenant): string
    {
        if ((string) $tenant->setting('bot_website_link', '1') === '0') return '';
        $dom = trim((string) $tenant->custom_domain);
        return $dom !== '' ? "\n\n\u{1F6D2} Or browse & order on our website: https://{$dom}" : '';
    }

    protected const SESSION_IDLE = 600;     // 10 min: expire clarification/shopping context
    protected const CART_TTL     = 86400;   // 24 h: discard a stale cart entirely

    /**
     * Manage idle expiry and cart recovery. Returns a reply string to short-circuit, or null
     * to let normal handling continue (with context already expired and the activity stamp
     * refreshed). Pure decision logic lives in sessionDecision() for testing.
     */
    protected function handleSessionLifecycle(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        $st   = is_array($convo->state) ? $convo->state : [];
        $cart = is_array($convo->cart) ? $convo->cart : [];

        // (1) Awaiting the customer's continue-vs-fresh choice from a prior return.
        if (! empty($st['awaiting_cart_choice'])) {
            $choice = self::cartChoice($text);
            if ($choice === 'continue') {
                unset($st['awaiting_cart_choice']);
                $st['last_activity'] = time();
                $convo->state = $st; $convo->save();
                return "Great \u{1F642} continuing your previous cart:\n\n"
                     . ($this->cartSummary($tenant, $cart) ?: '')
                     . "\n\nAdd more, or say *checkout*.";
            }
            // 'new' or anything else -> fresh cart; a non-choice message then falls through
            // to be processed as a brand-new request.
            $convo->cart = [];
            unset($st['awaiting_cart_choice']);
            $st['last_activity'] = time();
            $convo->state = $st; $convo->save();
            if ($choice === 'new') {
                return "Started a fresh cart \u{1F6D2} What would you like to order?";
            }
            return null; // process $text as a new request on the now-empty cart
        }

        // (2) Idle-based expiry.
        $decision = self::sessionDecision((int) ($st['last_activity'] ?? 0), time(), count($cart));

        if ($decision['expire_context']) {
            unset($st['options'], $st['last_query'], $st['last_kind'], $st['step'],
                  $st['checkout_token'], $st['pending'], $st['pending_order'], $st['pending_resolved'], $st['last_recommended'], $st['discovery']);
        }
        if ($decision['discard_cart']) {
            $convo->cart = [];
        }
        if ($decision['ask_recovery']) {
            $st['awaiting_cart_choice'] = true;
            $st['last_activity'] = time();
            $convo->state = $st; $convo->save();
            $n = count($cart);
            return "\u{1F44B} Welcome back! You have a previous cart ({$n} " . ($n === 1 ? 'item' : 'items') . ").\n"
                 . "1. Continue previous cart\n"
                 . "2. Start a new cart\n\n"
                 . "Reply *1* or *2*.";
        }

        $st['last_activity'] = time();
        $convo->state = $st; $convo->save();
        return null;
    }

    /**
     * Pure idle decision. Returns flags for what to do given idle seconds and cart size.
     * @return array{expire_context:bool,discard_cart:bool,ask_recovery:bool}
     */
    public static function sessionDecision(int $lastActivity, int $now, int $cartCount): array
    {
        $idle = $lastActivity > 0 ? ($now - $lastActivity) : 0;
        $expired = $lastActivity > 0 && $idle > self::SESSION_IDLE;
        $tooOld  = $lastActivity > 0 && $idle > self::CART_TTL;
        return [
            'expire_context' => $expired,
            'discard_cart'   => $expired && $tooOld && $cartCount > 0,
            'ask_recovery'   => $expired && ! $tooOld && $cartCount > 0,
        ];
    }

    /** Resolve a continue-vs-fresh reply. Returns 'continue' | 'new' | null. */
    public static function cartChoice(string $text): ?string
    {
        $t = trim(mb_strtolower(preg_replace('/[^a-z0-9\s]+/i', ' ', $text)));
        $t = trim(preg_replace('/\s+/', ' ', $t));
        $continue = ['1', 'continue', 'continue previous cart', 'continue previous', 'keep', 'keep it',
            'previous', 'previous cart', 'old', 'old cart', 'resume', 'yes', 'yeah', 'yep', 'continue cart'];
        $new = ['2', 'new', 'new cart', 'start new', 'start a new cart', 'start new cart', 'fresh',
            'fresh cart', 'start over', 'start fresh', 'clear', 'no', 'restart', 'new one'];
        if (in_array($t, $continue, true)) return 'continue';
        if (in_array($t, $new, true)) return 'new';
        return null;
    }

    /**
     * If the message is a confident single-category browse, present that category's products as a
     * clean numbered list (max 20, best/exact-brand first) and never unrelated categories.
     */
    /** List a whole category's items when the customer names a category. */
    protected function tryCategoryName(Tenant $tenant, Conversation $convo, string $text, array $catalogue): ?string
    {
        $res = (new \App\Services\Bot\CatalogueMatcher())->categoryByName($text, $catalogue);
        if ($res === null || count($res['products']) === 0) return null;

        $cur   = $this->currencyFor($tenant);
        $label = $res['category'] !== '' ? $res['category'] : 'Options';
        $built = $this->clarify->buildOptions(
            [['label' => $label, 'qty' => 1, 'products' => array_slice($res['products'], 0, 20)]],
            fn ($a) => $cur . ' ' . number_format((float) $a, \App\Services\Pricing::decimalsForCurrency($cur))
        );

        $st = is_array($convo->state) ? $convo->state : [];
        $st['options']      = $built['flat'];
        $st['last_query']   = $label;
        $st['last_kind']    = 'search';
        $st['last_activity'] = time();
        $convo->state       = $st;
        $convo->save();

        return "Here are the *{$label}* we have:\n" . $built['text']
             . "\n\nReply with the *number(s)* you want \u{2014} e.g. *3*, or *2 x 3* for two of item 3.";
    }

    protected function tryCategoryBrowse(Tenant $tenant, Conversation $convo, string $text, array $catalogue): ?string
    {
        $res = (new \App\Services\Bot\CatalogueMatcher())->categoryBrowse($text, $catalogue, 20);
        if ($res === null) return null;

        $cur   = $this->currencyFor($tenant);
        $label = $res['category'] !== '' ? $res['category'] : 'Options';
        $built = $this->clarify->buildOptions(
            [['label' => $label, 'qty' => 1, 'products' => $res['products']]],
            fn ($a) => $cur . ' ' . number_format((float) $a, \App\Services\Pricing::decimalsForCurrency($cur))
        );

        $st = is_array($convo->state) ? $convo->state : [];
        $st['options']    = $built['flat'];
        $st['last_query'] = $label;
        $st['last_kind']  = 'search';
        $st['last_activity'] = time();
        $convo->state     = $st;
        $convo->save();

        return "Here are the *{$label}* options we have:\n" . $built['text']
             . "\n\nReply with the *number(s)* you want — e.g. *3*, or *2 x 3* for two of item 3.";
    }

    /** A closing acknowledgement ("thanks", "okay", "noted", 👍, "will check") — not a selection. */
    protected function isClosingAck(string $text): bool
    {
        foreach (["\u{1F44D}", "\u{1F64F}", "\u{1F44C}", "\u{1F642}"] as $e) {  // 👍 🙏 👌 🙂
            if (str_contains($text, $e)) return true;
        }
        $t = trim(preg_replace('/[^a-z\s]/', '', mb_strtolower($text)));
        $t = trim(preg_replace('/\s+/', ' ', $t));
        $ack = ['thanks', 'thank you', 'thankyou', 'thank u', 'thx', 'ty', 'tnx',
            'asante', 'asante sana', 'webale', 'shukran', 'dhanyavaad',
            'ok', 'okay', 'okey', 'okie', 'k', 'kk', 'noted', 'alright', 'all right',
            'got it', 'gotit', 'will check', 'i will check', 'let me check', 'checking',
            'cool', 'fine', 'sure', 'great', 'nice one', 'no problem', 'np', 'understood', 'sawa'];
        return in_array($t, $ack, true);
    }

    /**
     * Intent Override Layer. While a numbered list is pending and the reply is NOT a selection,
     * recognise a real intent (delivery/price/business/location/greeting/thanks/decline/checkout/
     * availability/category/new product) and answer it. Returns the reply, or null to re-prompt.
     * Informational answers KEEP the pending options; decline/thanks/checkout clear them; a new
     * product/category search replaces them.
     */
    protected function pendingOverride(Tenant $tenant, Conversation $convo, string $text, string $lc, array $catalogue): ?string
    {
        // "Only those ones you have?" / "Is that all?" -> confirm the current list is complete.
        if ($this->asksIfListComplete($lc)) {
            return $this->confirmCurrentList($tenant, $convo);
        }

        // Closing acknowledgement -> warm close, drop the list.
        if ($this->isClosingAck($text)) {
            $st = is_array($convo->state) ? $convo->state : []; unset($st['options'], $st['pending_resolved'], $st['pending_order'], $st['last_recommended'], $st['discovery']);
            $convo->state = $st; $convo->save();
            return "You're welcome \u{1F60A}\n\nLet me know if you'd like to order any item or search for another product.";
        }

        // "Can I send a location pin?" -> instructions, keep the list.
        if (\App\Services\Bot\IntentClassifier::isLocationHelp($lc)) {
            return $this->locationHelpReply();
        }

        $intent = IntentClassifier::classify($text, IntentClassifier::tokenSetFromProducts($catalogue));
        $keepNote = "\n\n(Your options are still above \u{1F446} reply with a *number* when you're ready.)";

        switch ($intent) {
            case IntentClassifier::GREETING:
                return $this->greetingReply($tenant, $text) . $keepNote;
            case IntentClassifier::THANKS:
                $st = is_array($convo->state) ? $convo->state : []; unset($st['options'], $st['pending_resolved'], $st['pending_order'], $st['last_recommended'], $st['discovery']);
                $convo->state = $st; $convo->save();
                return "You're welcome \u{1F60A}\n\nLet me know if you'd like to order any item or search for another product.";
            case IntentClassifier::BUSINESS:
                return $this->businessResponse($tenant, IntentClassifier::businessKind($lc), $text) . $keepNote;
            case IntentClassifier::PRICE:
                return $this->priceResponse($tenant, IntentClassifier::priceQuery($lc) ?? $text) . $keepNote;
            case IntentClassifier::CATALOG:
                return $this->catalogResponse($tenant) . $keepNote;
            case IntentClassifier::SHOP_START:
                return "\u{1F6D2} Sure! Tell me what you'd like to add" . $keepNote;
            case IntentClassifier::LOCATION:
                return $this->captureLocation($tenant, $convo, $text); // stores area, keeps options
            case IntentClassifier::HUMAN_AGENT:
                $convo->agent_active = true; $convo->save();
                \App\Jobs\NotifyOwner::dispatch($tenant->id, "\u{1F64B} +{$convo->customer_phone} asked for a person. Open Chats to take over.");
                return "\u{1F642} Sure — I'm letting the shop know. Someone will reply here shortly.";
            case IntentClassifier::DECLINE:
                $st = is_array($convo->state) ? $convo->state : []; unset($st['options'], $st['pending_resolved'], $st['pending_order'], $st['last_recommended'], $st['discovery']);
                $convo->state = $st; $convo->save();
                $hasCart = is_array($convo->cart) && count($convo->cart) > 0;
                return $hasCart
                    ? "No problem \u{1F642} say *cart* to review, or *checkout* to finish whenever you're ready."
                    : "No problem \u{1F642} tell me a product whenever you'd like to order.";
            case IntentClassifier::CHECKOUT:
                return $this->execute($tenant, $convo, 'checkout', []);
            case IntentClassifier::CATEGORY:
                return $this->categoryResponse($tenant, $convo, $text); // replaces options with the category list
            case IntentClassifier::SHOPPING:
                // a new product / availability query ("you don't have cous cous") -> fresh search,
                // which replaces the pending options with the new results.
                if (($b = $this->tryCategoryBrowse($tenant, $convo, $text, $catalogue)) !== null) return $b;
                $r = $this->runShoppingEngine($tenant, $convo, $text, $catalogue);
                if ($r['handled']) {
                    $convo->cart = $r['cart']; $convo->state = $r['state']; $convo->save();
                    return $r['reply'];
                }
                return null; // couldn't resolve -> re-prompt
            default:
                // An everyday question while a list is up -> answer it, keep the list live.
                if (($faq = \App\Services\Bot\FaqDictionary::match($text, $this->faqContext($tenant))) !== null) {
                    return $faq . $keepNote;
                }
                return null; // QUESTION / UNKNOWN -> re-prompt
        }
    }

    /** "Only those ones you have?" / "is that all?" — asking whether the shown list is complete. */
    protected function asksIfListComplete(string $lc): bool
    {
        $t = trim(preg_replace('/[^a-z\s]/', '', mb_strtolower($lc)));
        $t = trim(preg_replace('/\s+/', ' ', $t));
        $phrases = ['only those', 'only these', 'only those ones', 'only these ones', 'only that',
            'only those ones you have', 'only what you have', 'is that all', 'is this all', 'that all',
            'thats all you have', 'is that all you have', 'are those all', 'are these all',
            'those are the only ones', 'is that the only ones', 'only those you have', 'just those'];
        if (in_array($t, $phrases, true)) return true;
        return (bool) preg_match('/^(is|are)\b.*\b(all|only)\b.*\b(you have|in stock|available)\b/', $t)
            || (bool) preg_match('/^only\b.*\byou have\b/', $t);
    }

    /** Re-affirm the currently shown options as the complete list for the active query. */
    protected function confirmCurrentList(Tenant $tenant, Conversation $convo): string
    {
        $st   = is_array($convo->state) ? $convo->state : [];
        $opts = is_array($st['options'] ?? null) ? $st['options'] : [];
        if (! $opts) return "Tell me a product and I'll show you what we have \u{1F642}";
        $q    = trim((string) ($st['last_query'] ?? ''));
        $cur  = $this->currencyFor($tenant);
        $lines = [];
        foreach ($opts as $o) {
            $lines[] = "  {$o['n']}. {$o['name']} — {$cur} " . number_format((float) ($o['price'] ?? 0), \App\Services\Pricing::decimalsForCurrency($cur));
        }
        $head = $q !== '' ? "Yes \u{1F642} those are the *{$q}* options we currently have:" : "Yes \u{1F642} those are what we currently have:";
        return $head . "\n" . implode("\n", $lines) . "\n\nWould you like to add any of these? Reply with the *number*.";
    }

    /** Instructions for sharing a WhatsApp location pin. */
    protected function locationHelpReply(): string
    {
        return "Yes \u{1F60A} please send your WhatsApp *location pin* and I'll calculate the exact delivery fee.\n"
             . "Tap \u{1F4CE} (or +) \u{2192} *Location* \u{2192} *Send your current location*.";
    }

    /** Context passed to the FAQ matcher so answers use the tenant's real settings. */
    protected function faqContext(Tenant $tenant): array
    {
        $payments = $tenant->setting('payment_methods', null);
        if (is_string($payments) && $payments !== '') {
            $payments = array_map('trim', explode(',', $payments));
        }
        $areas = $tenant->setting('delivery_areas', null);
        if (is_string($areas) && $areas !== '') {
            $areas = array_map('trim', explode(',', $areas));
        }
        return array_filter([
            'currency'        => $this->currencyFor($tenant),
            'payments'        => is_array($payments) ? $payments : null,
            'hours'           => trim((string) $tenant->setting('business_hours', '')) ?: null,
            'deliver_areas'   => is_array($areas) ? $areas : null,
            'min_order'       => $tenant->setting('min_order', null),
            'delivery_note'   => trim((string) $tenant->setting('delivery_note', '')) ?: null,
            'pay_on_delivery' => $tenant->setting('pay_on_delivery', true),
        ], fn ($v) => $v !== null);
    }

    /** Build a fresh engine (request-scoped token cache) and handle one message. */
    /**
     * Is this message a brand-new order (rather than a reply to a pending clarification)?
     * A multi-line list, or anything the classifier reads as a strong shopping signal, means
     * the customer has moved on — old options/pending state must be abandoned, not applied.
     */
    protected function isFreshProductRequest(string $text, array $catalogue): bool
    {
        if (preg_match('/\S[\r\n]+\S/', trim($text))) return true; // multi-line order
        $intent = \App\Services\Bot\IntentClassifier::classify(
            $text,
            \App\Services\Bot\IntentClassifier::tokenSetFromProducts($catalogue)
        );
        return $intent === \App\Services\Bot\IntentClassifier::SHOPPING;
    }

    /**
     * The human-shopkeeper conversational layer (recommend / reaffirm / compare).
     * Persists its own state when it handles the message; returns null to fall through.
     */
    protected function trySalesAssistant(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        $catalogue = $this->tenantCatalogue($tenant);
        return (new \App\Services\Bot\SalesAssistantBrain($this->clarify))
            ->respond($tenant, $convo, $text, $catalogue, $this->currencyFor($tenant));
    }

    /**
     * The customer was shown a priced order (pin + cart) and asked to confirm. A plain "yes / ok /
     * go ahead" places it using the stored pin; "no / not yet" holds the cart; anything else (a new
     * product, an edit, another pin) leaves confirm mode and is processed normally. Returns null to
     * fall through to the normal pipeline.
     */
    protected function handleOrderConfirm(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        $lc = mb_strtolower(trim($text));
        $st = is_array($convo->state) ? $convo->state : [];

        if (\App\Services\Bot\IntentClassifier::isAffirmative($lc)
            || \App\Services\Bot\IntentClassifier::looksLikeCheckout($lc)) {
            if (! empty($st['delivery_lat']) && ! empty($st['delivery_lng'])) {
                return $this->placeOrder(
                    $tenant, $convo,
                    (string) ($st['delivery_area'] ?? 'your pinned location'),
                    (float) $st['delivery_lat'], (float) $st['delivery_lng'],
                    ((string) ($st['delivery_maps'] ?? '')) ?: null
                );
            }
            unset($st['step']); $convo->state = $st; $convo->save();
            return "\u{1F4CD} Please send your *delivery location* (area / landmark) to place the order.";
        }

        if (\App\Services\Bot\IntentClassifier::isHold($lc)) {
            unset($st['step']); $convo->state = $st; $convo->save();
            return "No problem \u{1F642} Add more items, or say *confirm* when you're ready to place the order.";
        }

        // a fresh request — leave confirm mode and let the normal pipeline handle it
        unset($st['step']); $convo->state = $st; $convo->save();
        return null;
    }

    /** Build stamp: the live VERSION plus this file's last-write time on the server. */
    protected function versionStamp(): string
    {
        $when  = @filemtime((new \ReflectionClass($this))->getFileName());
        $stamp = $when ? gmdate('Y-m-d H:i', $when) . ' UTC' : 'unknown';
        return "\u{1F6E0} Build *" . self::VERSION . "*\nThis file last updated on the server: {$stamp}";
    }

    /**
     * Multi-message discovery. Keeps a single growing context in state.discovery so qualifiers
     * arriving over several messages ("Need rice" → "Not basmati" → "Family of 5") refine ONE
     * recommendation instead of being searched independently. The recommendation itself is
     * produced by the existing SalesAssistantBrain opinion path (fed a canonical sentence built
     * from the accumulated context), so the deterministic pick logic is unchanged. Returns null
     * to let the normal pipeline handle non-discovery messages.
     */
    protected function tryDiscovery(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        $catalogue = $this->tenantCatalogue($tenant);
        $st     = is_array($convo->state) ? $convo->state : [];
        $active = (isset($st['discovery']) && is_array($st['discovery'])) ? $st['discovery'] : null;

        $d = \App\Services\Bot\DiscoveryContextBuilder::decide($active, $text, $catalogue);

        if ($d['action'] === 'skip') {
            // A concrete order line ("5 coke") ends discovery and falls through to normal adding.
            if ($active !== null && \App\Services\Bot\DiscoveryContextBuilder::looksLikeConcreteAdd($text, $catalogue)) {
                unset($st['discovery']);
                $convo->state = $st;
                $convo->save();
            }
            return null;
        }

        $st['discovery'] = $d['ctx'];

        if ($d['action'] === 'ask') {
            $convo->state = $st;
            $convo->save();
            return "Happy to help you choose \u{1F642} What are you shopping for — rice, flour, oil, sugar…?";
        }

        // Recommend ONLY when the customer has given advisory context — a qualifier beyond the
        // bare product (an exclusion, usage, budget, household size or pack size). A bare product /
        // brand / variety ("basmati rice", "india gate") is a NARROWING SEARCH: hand it to the
        // reliable ranked product search, which understands multi-word phrases, rather than
        // collapsing it to one token and guessing a single pick (which surfaced a broom for
        // "india gate"). Discovery stays armed, so the moment a qualifier arrives we recommend.
        if (! \App\Services\Bot\DiscoveryContextBuilder::hasQualifier($d['ctx'])) {
            $convo->state = $st;       // keep discovery armed; let the normal search list it
            $convo->save();
            return null;
        }

        // Advisory recommendation: derive fresh, coherence-filtered candidates for the accumulated
        // subject — never inherit the previous numbered list (stale options would short-circuit
        // the search and recommend the cheapest item already on screen).
        unset($st['options'], $st['pending_resolved'], $st['last_recommended']);
        $convo->state = $st;
        $convo->save();

        $synth = \App\Services\Bot\DiscoveryContextBuilder::toOpinionText($d['ctx']);
        $reply = (new \App\Services\Bot\SalesAssistantBrain($this->clarify))
            ->respond($tenant, $convo, $synth, $catalogue, $this->currencyFor($tenant));

        return $reply ?? "Sure \u{1F642} Tell me a bit more and I'll recommend the right one.";
    }

    /**
     * A standalone cooking instruction ("less spicy", "no onion", "make it mild") with no
     * dish in it. Attach it to the most recent cart line; if the cart is empty, stash it as
     * a general order note for checkout. Returns null when the message isn't a pure
     * instruction, so real product messages fall through untouched.
     */
    /**
     * Answer "what is X?" / "what's in X?" with the dish's menu description + price.
     * Returns null when the question doesn't resolve to a product, so non-product
     * "what is …" questions (hours, delivery) fall through to the FAQ/forward path.
     */
    protected function productInfoReply(Tenant $tenant, Conversation $convo, string $lc): ?string
    {
        if (! preg_match('/^\s*(?:what\x27?s in|what is in|whats in|what\x27?s|what is|whats|tell me about|describe)\s+(.+?)\??\s*$/i', $lc, $m)) {
            return null;
        }
        $query = trim(preg_replace('/^(the|a|an|in|your)\s+/i', '', trim($m[1])));
        if (mb_strlen($query) < 3) return null;

        $cands = (new \App\Services\Bot\CatalogueMatcher())->search($query, $this->tenantCatalogue($tenant));
        if (! $cands) return null;

        $p    = $cands[0]['product'];
        $cur  = $this->currencyFor($tenant);
        $desc = trim((string) ($p['description'] ?? ''));
        $out  = "*{$p['name']}* \u{2014} {$cur} " . number_format((float) $p['price'], \App\Services\Pricing::decimalsForCurrency($cur));
        if ($desc !== '') $out .= "\n{$desc}";
        return $out . "\n\nWant me to add it? Say *add {$p['name']}*.";
    }


    protected function instructionReply(Tenant $tenant, Conversation $convo, string $lc): ?string
    {
        if (! \App\Support\OrderInstructions::isInstructionOnly($lc)) return null;
        $note = \App\Support\OrderInstructions::note($lc);
        if ($note === '') return null;

        $cart = is_array($convo->cart) ? $convo->cart : [];
        if ($cart) {
            $i = count($cart) - 1;
            $prev = trim((string) ($cart[$i]['note'] ?? ''));
            $cart[$i]['note'] = $prev !== '' ? ($prev . '; ' . $note) : $note;
            $convo->cart = $cart;
            $convo->save();
            $name = (string) ($cart[$i]['name'] ?? 'your item');
            return "\u{1F44C} Noted \u{2014} *{$note}* for {$name}. Add anything else, or say *checkout* to place the order.";
        }

        $st = is_array($convo->state) ? $convo->state : [];
        $prev = trim((string) ($st['order_note'] ?? ''));
        $st['order_note'] = $prev !== '' ? ($prev . '; ' . $note) : $note;
        $convo->state = $st;
        $convo->save();
        return "\u{1F44C} Noted \u{2014} *{$note}*. Tell me what you'd like to order and I'll add it.";
    }

    protected function runShoppingEngine(Tenant $tenant, Conversation $convo, string $text, array $catalogue): array
    {
        $engine = new ShoppingEngine(
            $this->parser, new CatalogueMatcher(), $this->clarify,
            $this->currencyFor($tenant),
            $this->tenantDefaults($tenant),
            $this->defaultStrategy($tenant),
            // Restaurant ordering behaviour now follows the tenant's vertical. Vertical::of()
            // infers 'restaurant' from a legacy restaurant_mode=true, so this is byte-compatible
            // for existing tenants and grocery stays a no-op.
            \App\Support\Vertical::of($tenant) === \App\Support\Vertical::RESTAURANT,
        );
        $cart  = is_array($convo->cart) ? $convo->cart : [];
        $state = is_array($convo->state) ? $convo->state : [];

        // Peel a trailing cooking instruction off ("biryani extra spicy") so it doesn't
        // pollute the product search, then re-attach it as a kitchen note on the line(s)
        // this message adds. Conservative split — never truncates a real dish name.
        [$dish, $note] = \App\Support\OrderInstructions::split($text);
        $searchText = $dish !== '' ? $dish : $text;

        $before = [];
        foreach ($cart as $l) { $before[(int) ($l['product_id'] ?? 0)] = (int) ($l['qty'] ?? 0); }

        $result = $engine->handle($searchText, $catalogue, $cart, $state);

        if ($note !== '' && ! empty($result['handled']) && is_array($result['cart'] ?? null)) {
            foreach ($result['cart'] as &$line) {
                $pid = (int) ($line['product_id'] ?? 0);
                $grew = ! array_key_exists($pid, $before) || (int) ($line['qty'] ?? 0) > $before[$pid];
                if ($grew) {
                    $prev = trim((string) ($line['note'] ?? ''));
                    $line['note'] = $prev !== '' ? ($prev . '; ' . $note) : $note;
                }
            }
            unset($line);
        }

        return $result;
    }

    /**
     * A customer shared a WhatsApp location pin (static or live). We build a Google Maps link
     * (so customer, shop and rider can all tap it), snap to a delivery zone for the fee, store
     * the pin, and either place the order (if mid-checkout) or save it and prompt to checkout.
     */
    public function handleLocationPin(Tenant $tenant, Conversation $convo, float $lat, float $lng, ?string $name = null, ?string $address = null): string
    {
        $link  = self::mapsLink($lat, $lng);
        $label = trim((string) ($name ?: $address ?: ''));
        $cart  = is_array($convo->cart) ? $convo->cart : [];

        // Snap to a zone (by pin) to name the area / quote the fee.
        $subtotal = 0; foreach ($cart as $l) $subtotal += $l['price'] * $l['qty'];
        $quote    = $this->deliveryQuote($tenant, (int) round($subtotal), $label, $lat, $lng);
        $zoneName = $quote['zone']['name'] ?? null;
        $area     = $zoneName ?: ($label !== '' ? $label : 'your pinned location');

        // Persist the pin on the conversation for checkout / rider.
        $st = is_array($convo->state) ? $convo->state : [];
        $st['delivery_area'] = $area;
        $st['delivery_lat']  = $lat;
        $st['delivery_lng']  = $lng;
        $st['delivery_maps'] = $link;
        $st['delivery_text'] = $label !== '' ? $label : $area;
        $st['last_activity'] = time();
        $convo->state = $st;
        $convo->save();

        // Mid-checkout: place the order straight away using the pin.
        if (($st['step'] ?? null) === 'awaiting_location' && $cart) {
            return $this->placeOrder($tenant, $convo, $area, $lat, $lng, $link);
        }

        $cur = $this->currencyFor($tenant);
        $line = "\u{1F4CD} Got your location: {$link}";
        if ($zoneName) {
            $fee = (int) $quote['fee']; $eta = $quote['zone']['eta_minutes'] ?? 45;
            $line .= "\nThat's in *{$zoneName}* — delivery " . ($fee > 0 ? "{$cur} " . number_format($fee, \App\Services\Pricing::decimalsForCurrency($cur)) : 'free') . " (~{$eta} min).";
        }
        if ($cart) {
            $fee   = (int) ($quote['fee'] ?? 0);
            $grand = round($subtotal, \App\Services\Pricing::decimalsForCurrency($cur)) + $fee;
            $st = is_array($convo->state) ? $convo->state : [];
            $st['step'] = 'awaiting_confirm';      // a plain "yes" now places the order
            $convo->state = $st;
            $convo->save();
            return $line
                . "\n\n" . $this->cartSummary($tenant, $cart)
                . "\n\u{1F4B0} *Total with delivery: {$cur} " . number_format($grand, \App\Services\Pricing::decimalsForCurrency($cur)) . "*"
                . "\n\nReply *confirm* to place this order, or add more items.";
        }
        return $line . "\n\nSaved \u{1F642} Tell me what you'd like to order and I'll deliver here.";
    }

    /** A customer volunteered a delivery location (outside checkout): store it, never search. */
    protected function captureLocation(Tenant $tenant, Conversation $convo, string $text): string
    {
        $det  = \App\Services\Bot\LocationDictionary::detect($text);
        $area = $det['area'] ?? \App\Services\Bot\LocationDictionary::canonicalize($text);
        $city = $det['city'] ?? null;

        $st = is_array($convo->state) ? $convo->state : [];
        $st['delivery_area'] = $area;
        $st['delivery_text'] = trim($text);
        $convo->state = $st;
        $convo->save();

        $where = $city ? "{$area}, {$city}" : $area;
        $cart  = is_array($convo->cart) ? $convo->cart : [];

        if ($cart) {
            $subtotal = 0; foreach ($cart as $l) $subtotal += $l['price'] * $l['qty'];
            $q   = $this->deliveryQuote($tenant, (int) round($subtotal), $area);
            $fee = (int) $q['fee'];
            $eta = $q['zone']['eta_minutes'] ?? 45;
            $cur = $this->currencyFor($tenant);
            $feeLine = $fee > 0
                ? "Delivery is about *{$cur} " . number_format($fee, \App\Services\Pricing::decimalsForCurrency($cur)) . "* (~{$eta} min)."
                : "Delivery ~{$eta} min.";
            return "\u{1F4CD} Got it — delivering to *{$where}*. {$feeLine}\n\nSay *checkout* to place your order, or add more items.";
        }

        return "\u{1F4CD} Got it — I'll deliver to *{$where}*. Tell me what you'd like to order, then say *checkout* when ready.";
    }

    /** Server-authoritative zone/fee/ETA for a location text (canonicalised for matching). */
    protected function deliveryQuote(Tenant $tenant, int $subtotal, string $locationText, ?float $lat = null, ?float $lng = null): array
    {
        $sset = $tenant->settings ?? [];
        $originLat = isset($sset['lat']) && is_numeric($sset['lat']) ? (float) $sset['lat'] : null;
        $originLng = isset($sset['lng']) && is_numeric($sset['lng']) ? (float) $sset['lng'] : null;
        return (new \App\Services\Delivery\ZoneResolver())->quote(
            \App\Services\Bot\LocationDictionary::canonicalize($locationText), $lat, $lng, $subtotal,
            [
                'base'      => (int) ($sset['base'] ?? 0),
                'per_km'    => (int) ($sset['perKm'] ?? 0),
                'min'       => (int) ($sset['min'] ?? 0),
                'free_over' => (int) ($sset['freeOver'] ?? 0),
            ],
            $originLat, $originLng
        );
    }

    /** Build a tappable Google Maps link from a customer pin. */
    public static function mapsLink(float $lat, float $lng): string
    {
        return 'https://maps.google.com/?q=' . rtrim(rtrim(number_format($lat, 6, '.', ''), '0'), '.')
             . ',' . rtrim(rtrim(number_format($lng, 6, '.', ''), '0'), '.');
    }

    /**
     * Category intent ("spirits", "snacks"): list members of that category as selectable
     * options, excluding false-friends ("surgical spirit"). Never a raw product search.
     */
    protected function categoryResponse(Tenant $tenant, Conversation $convo, string $text): string
    {
        $def = \App\Services\Bot\CategoryDictionary::match($text);
        if (! $def) return $this->catalogResponse($tenant);

        $cat = $this->tenantCatalogue($tenant);
        $inc = $def['include']; $exc = $def['exclude']; $terms = $def['terms'];
        $matches = [];
        foreach ($cat as $p) {
            $hay  = mb_strtolower(((string) ($p['name'] ?? '')) . ' ' . ((string) ($p['keywords'] ?? '')) . ' ' . ((string) ($p['category'] ?? '')));
            $pcat = mb_strtolower((string) ($p['category'] ?? ''));
            $bad = false;
            foreach ($exc as $x) { if ($x !== '' && str_contains($hay, $x)) { $bad = true; break; } }
            if ($bad) continue;
            $ok = false;
            foreach ($terms as $t) { if ($pcat !== '' && str_contains($pcat, $t)) { $ok = true; break; } }
            if (! $ok) { foreach ($inc as $k) { if (str_contains($hay, $k)) { $ok = true; break; } } }
            if ($ok) $matches[] = $p;
        }

        if (! $matches) {
            return "We don't have any *{$def['name']}* listed right now \u{1F642} Tell me a product name and I'll check.";
        }
        $matches = array_slice($matches, 0, 12);
        $cur = $this->currencyFor($tenant);
        $built = $this->clarify->buildOptions(
            [['label' => $def['name'], 'qty' => 1, 'products' => $matches]],
            fn ($a) => $cur . ' ' . number_format((float) $a, \App\Services\Pricing::decimalsForCurrency($cur))
        );
        $st = is_array($convo->state) ? $convo->state : [];
        $st['options']    = $built['flat'];
        $st['last_query'] = $def['name'];
        $st['last_kind']  = 'category';
        $convo->state     = $st;
        $convo->save();

        return "\u{1F6D2} *{$def['name']}* — here's what we have:\n" . $built['text'] . "\n\nReply with the *number(s)* you want.";
    }

    /**
     * Follow-up within the active context ("more brands", "larger size", "cheaper one").
     * Re-lists the last search/category, applying the modifier. Null if not a follow-up
     * or there is no active context to continue.
     */
    protected function tryFollowUp(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        $mod = \App\Services\Bot\FollowUp::parse($text);
        if ($mod === null) return null;

        $st   = is_array($convo->state) ? $convo->state : [];
        $q    = (string) ($st['last_query'] ?? '');
        $kind = (string) ($st['last_kind'] ?? '');
        if ($q === '') return null;   // no context yet — let normal handling deal with it

        if ($kind === 'category') {
            return $this->categoryResponse($tenant, $convo, $q);
        }

        $cat   = $this->tenantCatalogue($tenant);
        $cands = (new \App\Services\Bot\CatalogueMatcher())->search($q, $cat);
        if (! $cands) {
            return "I don't have other options for *{$q}* right now \u{1F642} Tell me another product.";
        }
        $prods = array_map(fn ($c) => $c['product'], $cands);
        $prods = $this->applyModifier($prods, $mod);
        $prods = array_slice($prods, 0, 12);

        // "more" with nothing new to show (e.g. only 3 Haldiram items, all already listed) ->
        // say so instead of re-printing the same list.
        if ($mod === 'more') {
            $prevIds = array_filter(array_map(fn ($o) => $o['product_id'] ?? null, (array) ($st['options'] ?? [])));
            $newIds  = array_filter(array_map(fn ($p) => $p['id'] ?? null, $prods));
            if ($prevIds && $newIds && ! array_diff($newIds, $prevIds)) {
                return "That's everything we have for *{$q}* \u{1F642} Say *menu* to browse other categories, or tell me another product.";
            }
        }

        $cur   = $this->currencyFor($tenant);
        $built = $this->clarify->buildOptions(
            [['label' => $q, 'qty' => 1, 'products' => $prods]],
            fn ($a) => $cur . ' ' . number_format((float) $a, \App\Services\Pricing::decimalsForCurrency($cur))
        );
        $st['options']    = $built['flat'];
        $st['last_query'] = $q;
        $st['last_kind']  = 'search';
        $convo->state     = $st;
        $convo->save();

        $head = [
            'more'    => "More *{$q}* options:",
            'cheaper' => "Cheaper *{$q}* options (lowest price first):",
            'premium' => "Premium *{$q}* options (highest first):",
            'larger'  => "Larger *{$q}* sizes:",
            'smaller' => "Smaller *{$q}* sizes:",
        ][$mod] ?? "More *{$q}* options:";

        return $head . "\n" . $built['text'] . "\n\nReply with the *number(s)* you want.";
    }

    /** Sort a candidate product list for a follow-up modifier. */
    protected function applyModifier(array $prods, string $mod): array
    {
        $price = fn ($p) => (float) ($p['price'] ?? 0);
        $size  = fn ($p) => \App\Services\Bot\CatalogueMatcher::sizeMagnitude((string) ($p['name'] ?? '')) ?? -1;
        switch ($mod) {
            case 'cheaper': usort($prods, fn ($a, $b) => $price($a) <=> $price($b)); break;
            case 'premium': usort($prods, fn ($a, $b) => $price($b) <=> $price($a)); break;
            case 'larger':  usort($prods, fn ($a, $b) => $size($b) <=> $size($a)); break;
            case 'smaller': usort($prods, fn ($a, $b) => $size($a) <=> $size($b)); break;
            // 'more' / default: keep relevance order from the matcher
        }
        return $prods;
    }

    /** Business inquiry answer ("are you open?", "delivering today?"). Never a product search. */
    protected function businessResponse(Tenant $tenant, string $kind, string $text = ''): string
    {
        $hours = trim((string) $tenant->setting('business_hours', ''));
        switch ($kind) {
            case 'status':
                return "\u{1F69A} Your order is being handled. The moment it's out for delivery we'll message you "
                     . "the rider's *name and number*, so you'll know exactly who is bringing it. "
                     . "If you've only just ordered, please allow a little time for it to be packed \u{1F642}";
            case 'delivery':
                $area = \App\Services\Bot\IntentClassifier::deliveryArea(mb_strtolower($text));
                if ($area !== null && $area !== '') {
                    $q   = $this->deliveryQuote($tenant, 0, $area);
                    $cur = $this->currencyFor($tenant);
                    if (! empty($q['zone'])) {
                        $zn  = $q['zone']['name'] ?? ucfirst($area);
                        $fee = (int) $q['fee'];
                        $eta = $q['zone']['eta_minutes'] ?? 45;
                        return "\u{1F6F5} Delivery to *{$zn}* is " . ($fee > 0 ? "*{$cur} " . number_format($fee, \App\Services\Pricing::decimalsForCurrency($cur)) . "*" : '*free*')
                             . " (~{$eta} min). Tell me what you'd like to order \u{1F642}";
                    }
                    return "\u{1F6F5} Yes, we deliver to *" . ucfirst($area) . "*! The exact fee depends on the spot — "
                         . "drop your *location pin* (tap \u{1F4CE} \u{2192} Location) and I'll calculate it. You can start adding items meanwhile.";
                }
                return "\u{1F6F5} Yes \u{1F60A} we deliver across Kampala and the surrounding areas.\n"
                     . "The delivery fee depends on your location — share your *location* or drop a WhatsApp *location pin* "
                     . "and I'll check the charge for you. You can keep adding items meanwhile.";
            case 'location':
                $addr = trim((string) $tenant->setting('address', ''));
                return $addr !== ''
                    ? "\u{1F4CD} We're at {$addr}. We're open and taking orders — tell me what you'd like."
                    : "We're open and taking orders \u{1F642} Tell me what you'd like and where to deliver.";
            case 'open':
            case 'general':
            default:
                $h = $hours !== '' ? " Our hours: {$hours}." : '';
                return "\u{2705} Yes, we're open and accepting orders!{$h} Tell me what you'd like, or say *menu* to browse.";
        }
    }

    /** Catalog/menu intent: show what the shop sells without running a product search. */
    protected function catalogResponse(Tenant $tenant): string
    {
        $cat = $this->tenantCatalogue($tenant);
        if (! $cat) return "Tell me what you're looking for and I'll check if we have it \u{1F642}";

        $byCat = [];
        foreach ($cat as $p) {
            $c = trim((string) ($p['category'] ?? '')) ?: 'Other';
            $byCat[$c][] = (string) ($p['name'] ?? '');
        }
        $total = count($cat);
        $cur   = $this->currencyFor($tenant);

        // Large catalogue with real categories -> list categories (counts); else list items.
        if (count($byCat) > 1 && $total > 25) {
            $cats = array_keys($byCat);
            sort($cats);
            $lines = array_map(fn ($c) => '• ' . $c . ' (' . count($byCat[$c]) . ')', $cats);
            return "\u{1F4D6} *Our menu* — {$total} products in " . count($cats) . " categories:\n"
                 . implode("\n", $lines)
                 . "\n\nReply with a *category* or a *product name* to see prices and order."
                 . $this->websiteNudge($tenant);
        }

        $lines = [];
        foreach ($cat as $p) {
            $nm = (string) ($p['name'] ?? '');
            if ($nm === '') continue;
            $pr = isset($p['price']) ? ' — ' . $cur . ' ' . number_format((float) $p['price'], \App\Services\Pricing::decimalsForCurrency($cur)) : '';
            $lines[] = '• ' . $nm . $pr;
            if (count($lines) >= 30) { $lines[] = '…and more — just ask for a product.'; break; }
        }
        return "\u{1F4D6} *Our menu:*\n" . implode("\n", $lines) . "\n\nReply with a *product name* to order." . $this->websiteNudge($tenant);
    }

    /**
     * Apply a cart command (remove / clear / change quantity). Returns a confirmation reply,
     * or null if it isn't actually a cart edit (so normal handling proceeds).
     */
    protected function tryCartEdit(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        $cart = is_array($convo->cart) ? array_values($convo->cart) : [];
        if (! $cart) {
            return "Your basket is empty — add a product first, then you can remove or change items \u{1F642}";
        }
        $res = \App\Services\Bot\CartEditor::apply($cart, $text);
        if ($res === null) {
            return "I couldn't find that in your basket. Say *cart* to see it with line numbers, then e.g. *remove item 2* or *make Beer 3*.";
        }

        $convo->cart = $res['cart'];
        $st = is_array($convo->state) ? $convo->state : [];
        unset($st['options'], $st['pending_resolved'], $st['pending_order'], $st['last_recommended'], $st['discovery']);   // editing the cart ends any pending clarification
        $convo->state = $st;
        $convo->save();

        if ($res['cleared']) {
            return "\u{2705} Cart cleared. Your basket is empty — tell me what you'd like to order.";
        }

        $parts = [];
        if (! empty($res['removed'])) {
            $parts[] = "\u{2705} Removed: " . implode(', ', array_map(fn ($n) => "*{$n}*", $res['removed']));
        }
        if (! empty($res['changed'])) {
            $parts[] = "\u{2705} Updated: " . implode(', ', array_map(fn ($c) => "*{$c['name']}* → {$c['qty']}", $res['changed']));
        }
        if (! $res['cart']) {
            $parts[] = 'Your basket is now empty — tell me what you\'d like to order.';
            return implode("\n", $parts);
        }
        return implode("\n", $parts) . "\n\n" . $this->cartSummary($tenant, $res['cart']) . "\n\nAdd more, or say *checkout*.";
    }

    /**
     * Public catalogue probe for image search: the products a query matches.
     * Used to reject a vision result that doesn't exist in this shop before we
     * reply, so a hallucinated brand never reaches the customer.
     */
    public function searchCatalogue(Tenant $tenant, string $query): array
    {
        $query = trim($query);
        if ($query === '') return [];

        return (new \App\Services\Bot\CatalogueMatcher())->search($query, $this->tenantCatalogue($tenant));
    }

    /** Tenant catalogue as plain rows (net prices applied) for the matcher. */
    protected function tenantCatalogue(Tenant $tenant): array
    {
        return Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->get()
            ->map(fn ($p) => [
                'id'           => $p->id,
                'name'         => (string) $p->name,
                'category'     => (string) ($p->category ?? ''),
                'keywords'     => (string) ($p->keywords ?? ''),
                'product_type' => (string) ($p->product_type ?? ''),   // drives matcher +250 tier
                'price'        => Pricing::net($tenant, (float) $p->price),
                'stock'        => $p->stock ?? 1,
            ])->all();
    }

    protected function currencyFor(Tenant $tenant): string
    {
        $c = (string) $tenant->setting('currency', 'UGX');
        return $c !== '' ? $c : 'UGX';
    }

    /** Owner-set default SKUs as term => product_id for this tenant. */
    protected function tenantDefaults(Tenant $tenant): array
    {
        return \App\Models\ProductDefault::query()
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->pluck('product_id', 'term')
            ->all();
    }

    /** 'off' | 'explicit' | 'explicit_then_auto' — platform default is 'explicit'. */
    protected function defaultStrategy(Tenant $tenant): string
    {
        $s = (string) $tenant->setting('default_strategy', 'explicit');
        return in_array($s, ['off', 'explicit', 'explicit_then_auto'], true) ? $s : 'explicit';
    }

    // ---------------- cart + order helpers ----------------

    protected function addToCart(array $cart, int $id, string $name, float $price, int $qty): array
    {
        foreach ($cart as &$line) {
            if ($line['product_id'] === $id) { $line['qty'] += $qty; return $cart; }
        }
        $cart[] = ['product_id' => $id, 'name' => $name, 'price' => $price, 'qty' => $qty];
        return $cart;
    }

    protected function cartSummary(Tenant $tenant, array $cart): string
    {
        if (! $cart) return '';
        $total = 0; $lines = []; $i = 0;
        foreach ($cart as $l) {
            $sub = $l['price'] * $l['qty']; $total += $sub; $i++;
            $lines[] = "{$i}. " . $this->lineLabel($l) . " x{$l['qty']} — " . Pricing::money($tenant, $sub);
            $note = trim((string) ($l['note'] ?? ''));
            if ($note !== '' && stripos((string) $l['name'], 'note:') === false) {
                $lines[] = "   \u{21B3} _note: {$note}_";
            }
        }
        return "\u{1F6D2} *Your basket*\n" . implode("\n", $lines) . "\n*Total: " . Pricing::money($tenant, $total) . "*";
    }

    /** Display label for a cart line, with any chosen accompaniment/add-ons folded in ("Butter Chicken + Naan"). */
    protected function lineLabel(array $l): string
    {
        $name = (string) ($l['name'] ?? '');
        if (! empty($l['modifiers']) && is_array($l['modifiers'])) {
            $mods = array_values(array_filter(array_map(fn ($m) => trim((string) ($m['name'] ?? '')), $l['modifiers'])));
            if ($mods) $name .= ' + ' . implode(', ', $mods);
        }
        return $name;
    }

    protected function placeOrder(Tenant $tenant, Conversation $convo, string $location, ?float $lat = null, ?float $lng = null, ?string $mapsLink = null): string
    {
        $cart = is_array($convo->cart) ? $convo->cart : [];
        if (! $cart) { $convo->state = []; $convo->save(); return "Your basket is empty. Add a product to start a new order."; }

        // Record the actual dishes + any customer change-note on the thali line for the kitchen.
        $stateNote = trim((string) ($convo->state['thali_note'] ?? ''));
        if ($stateNote !== '') {
            foreach ($cart as &$cl) {
                if (stripos((string) ($cl['name'] ?? ''), 'thali') !== false) {
                    $prev = trim((string) ($cl['note'] ?? ''));
                    $cl['note'] = $prev !== '' ? ($prev . '; ' . $stateNote) : $stateNote;
                }
            }
            unset($cl);
        }
        $cart = $this->stampThaliDishes($tenant, $cart);

        if ($tenant->effectivePlan() === 'free' && $tenant->overOrderCap()) {
            $convo->state = []; $convo->save();
            return "Thank you! \u{1F64F} Please hold on — someone from the shop will confirm your order shortly.";
        }

        // Carry a pin volunteered earlier (e.g. before the cart was full) into this checkout.
        if ($lat === null && isset($convo->state['delivery_lat'], $convo->state['delivery_lng'])) {
            $lat = (float) $convo->state['delivery_lat'];
            $lng = (float) $convo->state['delivery_lng'];
            $mapsLink = $mapsLink ?: (string) ($convo->state['delivery_maps'] ?? '');
        }

        $total = 0; foreach ($cart as $l) $total += $l['price'] * $l['qty'];
        $itemsText = collect($cart)->map(fn ($l) => "{$l['qty']}x " . $this->lineLabel($l))->implode(', ');

        // D1 — server-authoritative delivery fee + ETA. Prefer the pin (lat/lng) when present;
        // otherwise canonicalise the location text. If the reply names no area but one was
        // volunteered earlier, use that.
        $subtotal = (int) round($total);
        $zoneText = $location;
        if ($lat === null
            && \App\Services\Bot\LocationDictionary::detect($location) === null
            && ! empty($convo->state['delivery_area'])) {
            $zoneText = (string) $convo->state['delivery_area'];
        }
        $quote = $this->deliveryQuote($tenant, $subtotal, $zoneText, $lat, $lng);
        $deliveryFee = (int) $quote['fee'];
        $zoneId      = $quote['zone']['id'] ?? null;
        $zoneName    = $quote['zone']['name'] ?? null;
        $etaMins     = $quote['zone']['eta_minutes'] ?? 45;
        $etaAt       = now()->addMinutes((int) $etaMins);
        $grand       = round($total, \App\Services\Pricing::decimalsForCurrency($this->currencyFor($tenant))) + $deliveryFee;

        // What we store + show as the delivery location: a tappable maps link when we have a pin
        // (so the shop and rider just click to navigate), with the area label alongside.
        $locStored = $location;
        if ($mapsLink) {
            $locStored = ($zoneName ?: ($location !== '' ? $location : 'Pinned location')) . ' — ' . $mapsLink;
        }
        // Carry the customer's requested delivery time (captured earlier in chat) onto the order
        // so the shop and rider can see it — no schema change, just appended to the location line.
        $dtime = trim((string) data_get($convo->state, 'delivery_time', ''));
        if ($dtime !== '') {
            $locStored .= ' · Deliver ~' . $dtime;
        }

        // 2C — Order idempotency.
        $token = (string) (data_get($convo->state, 'checkout_token') ?: ('cart:' . md5(json_encode($cart))));
        $key   = \App\Support\Idempotency::orderKey($tenant->id, $convo->id, $token);

        $order = \Illuminate\Support\Facades\Cache::lock(
            \App\Support\Idempotency::checkoutLock($tenant->id, $convo->id), 10
        )->block(5, function () use ($key, $convo, $itemsText, $cart, $grand, $locStored, $deliveryFee, $zoneId, $etaAt) {
            return Order::firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'customer_phone'   => $convo->customer_phone,
                    'items_text'       => $itemsText,
                    'items_json'       => $cart,
                    'total'            => $grand,            // items + delivery
                    'delivery_fee'     => $deliveryFee,
                    'delivery_zone_id' => $zoneId,
                    'eta_at'           => $etaAt,
                    'location'         => $locStored,
                    'status'           => 'New',
                    'channel'          => 'whatsapp',
                ]
            );
        });

        if ($order->wasRecentlyCreated) {
            foreach ($cart as $l) {
                $mods = ! empty($l['modifiers']) && is_array($l['modifiers'])
                    ? array_values(array_filter(array_map(fn ($m) => trim((string) ($m['name'] ?? '')), $l['modifiers'])))
                    : [];
                OrderItem::create([
                    'order_id' => $order->id, 'product_id' => $l['product_id'],
                    'name' => $l['name'] . ($mods ? ' + ' . implode(', ', $mods) : ''),
                    'price' => $l['price'], 'qty' => $l['qty'],
                    'notes' => trim((string) ($l['note'] ?? '')) ?: null,
                    'modifiers' => $mods ? $l['modifiers'] : null,
                ]);
            }
            // Roll any line notes + a general order note up onto the order, for the kitchen.
            $noteBits = [];
            $general  = trim((string) data_get($convo->state, 'order_note', ''));
            if ($general !== '') $noteBits[] = $general;
            foreach ($cart as $l) {
                $ln = trim((string) ($l['note'] ?? ''));
                if ($ln !== '') $noteBits[] = $l['name'] . ': ' . $ln;
            }
            if ($noteBits) { $order->notes = implode(' | ', $noteBits); $order->save(); }
        }

        $convo->cart = []; $convo->state = []; $convo->save();

        $cur = $this->currencyFor($tenant);
        $feeLine = $deliveryFee > 0
            ? ($zoneName ? "\u{1F6F5} {$zoneName} · delivery {$cur} " . number_format($deliveryFee, \App\Services\Pricing::decimalsForCurrency($cur)) . " · ETA ~{$etaMins} min"
                         : "\u{1F6F5} Delivery {$cur} " . number_format($deliveryFee, \App\Services\Pricing::decimalsForCurrency($cur)) . " · ETA ~{$etaMins} min")
            : "\u{1F6F5} Free delivery · ETA ~{$etaMins} min";

        $deliverTo = $mapsLink ? ($zoneName ? "{$zoneName}\n{$mapsLink}" : $mapsLink) : $location;

        return "\u{2705} Order *{$order->order_no}* received!\n" . $this->cartSummary($tenant, $cart)
             . "\n\u{1F4CD} Deliver to: {$deliverTo}\n{$feeLine}"
             . "\n\u{1F4B0} Total: {$cur} " . number_format($grand, \App\Services\Pricing::decimalsForCurrency($cur))
             . "\n\nWe'll confirm and dispatch shortly. Thank you!";
    }
}
