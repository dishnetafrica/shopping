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
    public function __construct(
        protected ProductSearch $search,
        protected BotNlu $nlu,
        protected ShoppingParser $parser,
        protected CatalogueMatcher $matcher,
        protected ClarificationFlow $clarify,
    ) {}

    public function respond(Tenant $tenant, Conversation $convo, string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';

        // Mid-checkout: the next message is always the delivery location.
        if (data_get($convo->state, 'step') === 'awaiting_location') {
            return $this->placeOrder($tenant, $convo, $text);
        }

        // Try the LLM first; fall back to keywords.
        $action = $this->nlu->parse($tenant, $convo, $text);
        if ($action) {
            return $this->execute($tenant, $convo, $action['intent'], $action['items'] ?? [], $action['note'] ?? '');
        }
        return $this->keywordRespond($tenant, $convo, $text);
    }

    // ---------------- shared executor ----------------

    protected function execute(Tenant $tenant, Conversation $convo, string $intent, array $items, string $note = ''): string
    {
        $cart = is_array($convo->cart) ? $convo->cart : [];

        switch ($intent) {
            case 'greet':
                $custom = trim((string) $tenant->setting('bot_greeting', ''));
                if ($custom !== '') return $custom;
                return "Hello \u{1F44B} Welcome to {$tenant->name}! Tell me what you'd like and I'll add it up. "
                     . "Say *cart* to see your basket or *checkout* when ready.";

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
                $convo->state = array_merge($convo->state ?? [], [
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

        // ---- Cart management (remove / clear / change quantity, by number or name) ----
        // Explicit cart commands take priority and must never product-search.
        if (\App\Services\Bot\CartEditor::isEditIntent($lc)) {
            if (($edit = $this->tryCartEdit($tenant, $convo, $text)) !== null) {
                return $edit;
            }
        }

        // ---- Follow-up within the active product/category context ----
        // "more brands", "other options", "larger size", "cheaper one" continue the last
        // list rather than searching for the literal words. Must run before the pending
        // selection check (a follow-up phrase is never a number), but a numeric reply (a
        // real selection) is left untouched.
        if (($fu = $this->tryFollowUp($tenant, $convo, $text)) !== null) {
            return $fu;
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
            // Reply neither resolved the selection nor started a new product. Keep the
            // pending options (state survives) and re-prompt — never add on an ambiguous reply.
            return "Please reply with the *number* you want from the list above (e.g. *1*, or *1 2 3*) — or type a product name to search again.";
        }

        // command words win before shopping parsing
        if (\App\Services\Bot\GreetingDictionary::isGreeting($lc)) return $this->greetingReply($tenant, $text);
        if (in_array($lc, ['cart','basket','my order','my cart','view cart'], true))           return $this->execute($tenant, $convo, 'view_cart', []);
        if (in_array($lc, ['clear','empty','reset','clear cart','empty cart'], true))           return $this->execute($tenant, $convo, 'clear', []);
        if (in_array($lc, ['checkout','done','confirm','order','place order','proceed to checkout','proceed','finish'], true)) return $this->execute($tenant, $convo, 'checkout', []);
        // affirmations: a bare "good/ok/yes" is chat, not a product search
        if (in_array($lc, ['ok','okay','yes','yeah','yep','good','nice','cool','great','thanks','thank you','thx','sure','fine'], true)) {
            return "\u{1F44D} Great! Tell me what you'd like, say *cart* to review, or *checkout* when ready.";
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

        // ---- Intent classification (runs BEFORE any catalogue search) ----
        // The bot is a shop assistant, not a search engine: conversational messages
        // (feedback / greeting / thanks / questions / gibberish) must never search.
        $catalogue = $this->tenantCatalogue($tenant);
        $intent = IntentClassifier::classify($text, IntentClassifier::tokenSetFromProducts($catalogue));

        switch ($intent) {
            case IntentClassifier::FEEDBACK:
                return "\u{1F64F} Thank you for the feedback! Glad it's working well for you. Whenever you're ready, just tell me what you'd like to order.";
            case IntentClassifier::THANKS:
                return "\u{1F60A} You're welcome! Anything else you'd like to order? Say *cart* to review or *checkout* when ready.";
            case IntentClassifier::GREETING:
                return $this->greetingReply($tenant, $text);
            case IntentClassifier::QUESTION:
                return "\u{1F642} Happy to help! Tell me a product to order, say *cart* to review, or *checkout* to finish — and for anything else I'll connect you to the shop.";
            case IntentClassifier::HUMAN_AGENT:
                $convo->agent_active = true;
                $convo->save();
                \App\Jobs\NotifyOwner::dispatch($tenant->id, "\u{1F64B} +{$convo->customer_phone} asked to speak with a person. Open Chats to take over.");
                return "\u{1F642} Sure — I'm letting the shop know. Someone will reply here shortly. Meanwhile you can keep typing your order if you like.";
            case IntentClassifier::CATALOG:
                return $this->catalogResponse($tenant);
            case IntentClassifier::BUSINESS:
                return $this->businessResponse($tenant, IntentClassifier::businessKind($lc));
            case IntentClassifier::CATEGORY:
                return $this->categoryResponse($tenant, $convo, $text);
            case IntentClassifier::PRICE:
                return $this->priceResponse($tenant, IntentClassifier::priceQuery($lc) ?? $text);
            case IntentClassifier::LOCATION:
                return $this->captureLocation($tenant, $convo, $text);
            case IntentClassifier::UNKNOWN:
                return "I didn't quite catch that \u{1F642} Tell me a product to add, say *cart* to review, or *checkout* when ready.";
            // CART / CHECKOUT / DECLINE are already handled above; anything else is SHOPPING.
        }

        // hand off to the deterministic shopping engine
        $result = $this->runShoppingEngine($tenant, $convo, $text, $catalogue);

        if ($result['handled']) {
            $convo->cart  = $result['cart'];
            $convo->state = $result['state'];
            $convo->save();
            return $result['reply'];
        }

        // No catalogue match. If the customer clearly named a product, say we don't stock it
        // (instead of a vague "didn't catch that").
        $want = trim(preg_replace('/\b(do you (have|sell|stock)|have you got|got any|any|looking for|i (want|need)|please|pls)\b/i', ' ', mb_strtolower($text)));
        $want = trim(preg_replace('/\s+/', ' ', $want));
        if ($want !== '' && mb_strlen($want) >= 3 && preg_match('/[a-z]/i', $want)) {
            return "Sorry, we don't stock *{$want}* right now \u{1F642} Tell me another product, or say *menu* to see what we have.";
        }

        return $this->execute($tenant, $convo, 'unknown', []);
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
            return "Sorry, I couldn't find *{$query}* \u{1F642} We may not stock it. Tell me another product, or say *menu* to browse.";
        }

        $prods = array_slice(array_map(fn ($c) => $c['product'], $cands), 0, 6);

        if (count($prods) === 1) {
            $p = $prods[0];
            return "*{$p['name']}* is {$cur} " . number_format((float) $p['price'])
                 . ".\n\nWant me to add it? Just say *add {$p['name']}* or tell me the quantity.";
        }

        $lines = [];
        foreach ($prods as $p) {
            $lines[] = "• {$p['name']} — {$cur} " . number_format((float) $p['price']);
        }
        return "Here are the prices for *{$query}*:\n" . implode("\n", $lines)
             . "\n\nTell me which one you'd like, or the quantity.";
    }

    /**
     * Localised greeting reply based on the detected language of the customer's greeting.
     * Never product-searches. Falls back to the tenant's custom greeting for English.
     */
    protected function greetingReply(Tenant $tenant, string $text): string
    {
        $shop = $tenant->name;
        $d    = \App\Services\Bot\GreetingDictionary::detect($text) ?? ['lang' => 'en', 'kind' => 'greet'];

        if ($d['kind'] === 'smalltalk') {
            return "\u{1F642} I'm here and ready! What can I get for you today at {$shop}?";
        }

        switch ($d['lang']) {
            case 'sw':
                return "Habari \u{1F60A} Karibu {$shop}.\nWhat would you like today?";
            case 'lg':
                return "Bulungi \u{1F60A}\nWhat can I get for you today?";
            case 'ar':
                return "Salaam \u{1F44B}\nWelcome to {$shop}.\nHow can I help you today?";
            case 'in':
                if (preg_match('/krishna/i', $text)) {
                    return "\u{1F64F} Jai Shree Krishna! Welcome to {$shop} — what would you like today?";
                }
                return "Namaste \u{1F64F}\nWelcome to {$shop}! What would you like today?";
            case 'en':
            default:
                $custom = trim((string) $tenant->setting('bot_greeting', ''));
                if ($custom !== '') return $custom;
                return "Hello \u{1F44B} Welcome to {$shop}! Tell me what you'd like and I'll add it up. "
                     . "Say *cart* to see your basket or *checkout* when ready.";
        }
    }

    /** Build a fresh engine (request-scoped token cache) and handle one message. */
    protected function runShoppingEngine(Tenant $tenant, Conversation $convo, string $text, array $catalogue): array
    {
        $engine = new ShoppingEngine(
            $this->parser, new CatalogueMatcher(), $this->clarify,
            $this->currencyFor($tenant),
            $this->tenantDefaults($tenant),
            $this->defaultStrategy($tenant),
        );
        $cart  = is_array($convo->cart) ? $convo->cart : [];
        $state = is_array($convo->state) ? $convo->state : [];
        return $engine->handle($text, $catalogue, $cart, $state);
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
                ? "Delivery is about *{$cur} " . number_format($fee) . "* (~{$eta} min)."
                : "Delivery ~{$eta} min.";
            return "\u{1F4CD} Got it — delivering to *{$where}*. {$feeLine}\n\nSay *checkout* to place your order, or add more items.";
        }

        return "\u{1F4CD} Got it — I'll deliver to *{$where}*. Tell me what you'd like to order, then say *checkout* when ready.";
    }

    /** Server-authoritative zone/fee/ETA for a location text (canonicalised for matching). */
    protected function deliveryQuote(Tenant $tenant, int $subtotal, string $locationText): array
    {
        $sset = $tenant->settings ?? [];
        return (new \App\Services\Delivery\ZoneResolver())->quote(
            \App\Services\Bot\LocationDictionary::canonicalize($locationText), null, null, $subtotal,
            [
                'base'      => (int) ($sset['base'] ?? 0),
                'per_km'    => (int) ($sset['perKm'] ?? 0),
                'min'       => (int) ($sset['min'] ?? 0),
                'free_over' => (int) ($sset['freeOver'] ?? 0),
            ]
        );
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
            fn ($a) => $cur . ' ' . number_format((float) $a)
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
            fn ($a) => $cur . ' ' . number_format((float) $a)
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
    protected function businessResponse(Tenant $tenant, string $kind): string
    {
        $hours = trim((string) $tenant->setting('business_hours', ''));
        switch ($kind) {
            case 'delivery':
                return "\u{1F6F5} Yes — we're delivering today! Tell me what you'd like and your area, and I'll arrange delivery.";
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
                 . "\n\nReply with a *category* or a *product name* to see prices and order.";
        }

        $lines = [];
        foreach ($cat as $p) {
            $nm = (string) ($p['name'] ?? '');
            if ($nm === '') continue;
            $pr = isset($p['price']) ? ' — ' . $cur . ' ' . number_format((float) $p['price']) : '';
            $lines[] = '• ' . $nm . $pr;
            if (count($lines) >= 30) { $lines[] = '…and more — just ask for a product.'; break; }
        }
        return "\u{1F4D6} *Our menu:*\n" . implode("\n", $lines) . "\n\nReply with a *product name* to order.";
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
        unset($st['options']);            // editing the cart ends any pending clarification
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

    /** Tenant catalogue as plain rows (net prices applied) for the matcher. */
    protected function tenantCatalogue(Tenant $tenant): array
    {
        return Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->get()
            ->map(fn ($p) => [
                'id'       => $p->id,
                'name'     => (string) $p->name,
                'category' => (string) ($p->category ?? ''),
                'keywords' => (string) ($p->keywords ?? ''),
                'price'    => Pricing::net($tenant, (float) $p->price),
                'stock'    => $p->stock ?? 1,
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
            $lines[] = "{$i}. {$l['name']} x{$l['qty']} — " . Pricing::money($tenant, $sub);
        }
        return "\u{1F6D2} *Your basket*\n" . implode("\n", $lines) . "\n*Total: " . Pricing::money($tenant, $total) . "*";
    }

    protected function placeOrder(Tenant $tenant, Conversation $convo, string $location): string
    {
        $cart = is_array($convo->cart) ? $convo->cart : [];
        if (! $cart) { $convo->state = []; $convo->save(); return "Your basket is empty. Add a product to start a new order."; }

        if ($tenant->effectivePlan() === 'free' && $tenant->overOrderCap()) {
            $convo->state = []; $convo->save();
            return "Thank you! \u{1F64F} Please hold on — someone from the shop will confirm your order shortly.";
        }

        $total = 0; foreach ($cart as $l) $total += $l['price'] * $l['qty'];
        $itemsText = collect($cart)->map(fn ($l) => "{$l['qty']}x {$l['name']}")->implode(', ');

        // D1 — server-authoritative delivery fee + ETA from the matched zone (no extra
        // confirmation step). Location text is canonicalised (misspellings -> canonical
        // area) so zone keyword matching is reliable; if the checkout reply names no area
        // but one was volunteered earlier, use that.
        $subtotal = (int) round($total);
        $zoneText = $location;
        if (\App\Services\Bot\LocationDictionary::detect($location) === null
            && ! empty($convo->state['delivery_area'])) {
            $zoneText = (string) $convo->state['delivery_area'];
        }
        $quote = $this->deliveryQuote($tenant, $subtotal, $zoneText);
        $deliveryFee = (int) $quote['fee'];
        $zoneId      = $quote['zone']['id'] ?? null;
        $zoneName    = $quote['zone']['name'] ?? null;
        $etaMins     = $quote['zone']['eta_minutes'] ?? 45;
        $etaAt       = now()->addMinutes((int) $etaMins);
        $grand       = $subtotal + $deliveryFee;

        // 2C — Order idempotency. Same checkout (token) retried => same key => one
        // order. The lock serialises concurrent attempts; the unique key is the
        // ultimate guard. firstOrCreate returns the existing order on a repeat.
        $token = (string) (data_get($convo->state, 'checkout_token') ?: ('cart:' . md5(json_encode($cart))));
        $key   = \App\Support\Idempotency::orderKey($tenant->id, $convo->id, $token);

        $order = \Illuminate\Support\Facades\Cache::lock(
            \App\Support\Idempotency::checkoutLock($tenant->id, $convo->id), 10
        )->block(5, function () use ($key, $convo, $itemsText, $cart, $grand, $location, $deliveryFee, $zoneId, $etaAt) {
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
                    'location'         => $location,
                    'status'           => 'New',
                    'channel'          => 'whatsapp',
                ]
            );
        });

        if ($order->wasRecentlyCreated) {
            foreach ($cart as $l) {
                OrderItem::create([
                    'order_id' => $order->id, 'product_id' => $l['product_id'],
                    'name' => $l['name'], 'price' => $l['price'], 'qty' => $l['qty'],
                ]);
            }
        }

        $convo->cart = []; $convo->state = []; $convo->save();

        $cur = $this->currencyFor($tenant);
        $feeLine = $deliveryFee > 0
            ? ($zoneName ? "\u{1F6F5} {$zoneName} · delivery {$cur} " . number_format($deliveryFee) . " · ETA ~{$etaMins} min"
                         : "\u{1F6F5} Delivery {$cur} " . number_format($deliveryFee) . " · ETA ~{$etaMins} min")
            : "\u{1F6F5} Free delivery · ETA ~{$etaMins} min";

        return "\u{2705} Order *{$order->order_no}* received!\n" . $this->cartSummary($tenant, $cart)
             . "\n\u{1F4CD} Deliver to: {$location}\n{$feeLine}"
             . "\n\u{1F4B0} Total: {$cur} " . number_format($grand)
             . "\n\nWe'll confirm and dispatch shortly. Thank you!";
    }
}
