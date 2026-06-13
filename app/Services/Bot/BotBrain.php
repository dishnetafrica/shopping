<?php
namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;
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

    // ---------------- keyword fallback (used when NLU is off/unavailable) ----------------

    protected function keywordRespond(Tenant $tenant, Conversation $convo, string $text): string
    {
        $lc = mb_strtolower($text);
        if (in_array($lc, ['hi','hello','hey','start','menu','hola'], true)) return $this->execute($tenant, $convo, 'greet', []);
        if (in_array($lc, ['cart','basket','my order'], true))               return $this->execute($tenant, $convo, 'view_cart', []);
        if (in_array($lc, ['clear','empty','reset'], true))                  return $this->execute($tenant, $convo, 'clear', []);
        if (in_array($lc, ['checkout','done','confirm','order','place order'], true)) return $this->execute($tenant, $convo, 'checkout', []);

        [$qty, $term] = $this->parseQtyAndTerm($lc, $text);
        $isAdd = Str::startsWith($lc, ['add ', 'i want ', 'buy ']) || $qty > 1 || preg_match('/\b(x|qty|pcs|kg)\b/', $lc);
        return $this->execute($tenant, $convo, $isAdd ? 'add' : 'search', [['query' => $term, 'qty' => $qty]]);
    }

    protected function parseQtyAndTerm(string $lc, string $original): array
    {
        $s = preg_replace('/^(add|i want|buy)\s+/i', '', $original);
        $qty = 1;
        if (preg_match('/(\d+)\s*x?\s*/i', $s, $m)) {
            $qty = max(1, (int) $m[1]);
            $s = trim(preg_replace('/(\d+)\s*x?\s*/i', '', $s, 1));
        }
        return [$qty, trim($s) ?: $original];
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
