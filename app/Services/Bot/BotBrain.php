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

        // command words win before shopping parsing
        if (in_array($lc, ['hi','hello','hey','start','menu','hola','good morning','good afternoon','good evening','jai shree krishna','jsk','namaste','namaskar','salaam','salam'], true)) return $this->execute($tenant, $convo, 'greet', []);
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
                return $this->execute($tenant, $convo, 'greet', []);
            case IntentClassifier::QUESTION:
                return "\u{1F642} Happy to help! Tell me a product to order, say *cart* to review, or *checkout* to finish — and for anything else I'll connect you to the shop.";
            case IntentClassifier::HUMAN_AGENT:
                $convo->agent_active = true;
                $convo->save();
                \App\Jobs\NotifyOwner::dispatch($tenant->id, "\u{1F64B} +{$convo->customer_phone} asked to speak with a person. Open Chats to take over.");
                return "\u{1F642} Sure — I'm letting the shop know. Someone will reply here shortly. Meanwhile you can keep typing your order if you like.";
            case IntentClassifier::UNKNOWN:
                return "I didn't quite catch that \u{1F642} Tell me a product to add, say *cart* to review, or *checkout* when ready.";
            // CART / CHECKOUT / DECLINE are already handled above; anything else is SHOPPING.
        }

        // hand off to the deterministic shopping engine (fresh matcher per message:
        // its token cache is request-scoped, so nothing carries between tenants)
        $engine  = new ShoppingEngine(
            $this->parser, new CatalogueMatcher(), $this->clarify,
            $this->currencyFor($tenant),
            $this->tenantDefaults($tenant),
            $this->defaultStrategy($tenant),
        );
        $cart    = is_array($convo->cart) ? $convo->cart : [];
        $state   = is_array($convo->state) ? $convo->state : [];
        $result  = $engine->handle($text, $catalogue, $cart, $state);

        if ($result['handled']) {
            $convo->cart  = $result['cart'];
            $convo->state = $result['state'];
            $convo->save();
            return $result['reply'];
        }

        return $this->execute($tenant, $convo, 'unknown', []);
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
        $total = 0; $lines = [];
        foreach ($cart as $l) {
            $sub = $l['price'] * $l['qty']; $total += $sub;
            $lines[] = "• {$l['qty']} x {$l['name']} — ".Pricing::money($tenant, $sub);
        }
        return "\u{1F6D2} *Your basket*\n".implode("\n", $lines)."\n*Total: ".Pricing::money($tenant, $total)."*";
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
        // confirmation step). Text-location match for now; per-km needs a shared pin.
        $subtotal = (int) round($total);
        $sset = $tenant->settings ?? [];
        $quote = (new \App\Services\Delivery\ZoneResolver())->quote(
            $location, null, null, $subtotal,
            [
                'base'      => (int) ($sset['base'] ?? 0),
                'per_km'    => (int) ($sset['perKm'] ?? 0),
                'min'       => (int) ($sset['min'] ?? 0),
                'free_over' => (int) ($sset['freeOver'] ?? 0),
            ]
        );
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
