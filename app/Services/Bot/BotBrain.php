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
                $convo->state = array_merge($convo->state ?? [], ['step' => 'awaiting_location']);
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
        $result  = $engine->handle($text, $this->tenantCatalogue($tenant), $cart, $state);

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

        $order = Order::create([
            'customer_phone' => $convo->customer_phone,
            'items_text'     => $itemsText,
            'items_json'     => $cart,
            'total'          => $total,
            'location'       => $location,
            'status'         => 'New',
            'channel'        => 'whatsapp',
        ]);
        foreach ($cart as $l) {
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $l['product_id'],
                'name' => $l['name'], 'price' => $l['price'], 'qty' => $l['qty'],
            ]);
        }

        $convo->cart = []; $convo->state = []; $convo->save();

        return "\u{2705} Order *{$order->order_no}* received!\n".$this->cartSummary($tenant, $cart)
             . "\n\u{1F4CD} Deliver to: {$location}\n\nWe'll confirm and dispatch shortly. Thank you!";
    }
}
