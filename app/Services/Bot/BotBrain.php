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
 * Conversational ordering. Keyword logic is the reliable default; OpenAI NLU
 * is an optional enhancement (Phase 2) layered on top of the same cart model.
 */
class BotBrain
{
    public function __construct(protected ProductSearch $search) {}

    public function respond(Tenant $tenant, Conversation $convo, string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';
        $lc = mb_strtolower($text);
        $cart = is_array($convo->cart) ? $convo->cart : [];
        $step = data_get($convo->state, 'step');

        // --- mid-checkout: capturing the delivery location ---
        if ($step === 'awaiting_location') {
            return $this->placeOrder($tenant, $convo, $text);
        }

        // --- intents ---
        if (in_array($lc, ['hi','hello','hey','start','menu','hola'], true)) {
            return "Hello \u{1F44B} Welcome to {$tenant->name}! Tell me what you'd like and I'll find it. "
                 . "Type *cart* to see your basket or *checkout* when you're ready.";
        }
        if (in_array($lc, ['cart','basket','my order'], true)) {
            return $this->cartSummary($tenant, $cart) ?: "Your basket is empty. Tell me a product to add.";
        }
        if (in_array($lc, ['clear','empty','reset'], true)) {
            $convo->cart = []; $convo->save();
            return "Basket cleared. What would you like to order?";
        }
        if (in_array($lc, ['checkout','done','confirm','order','place order'], true)) {
            if (!$cart) return "Your basket is empty. Add a product first, then type *checkout*.";
            $convo->state = array_merge($convo->state ?? [], ['step' => 'awaiting_location']);
            $convo->save();
            return $this->cartSummary($tenant, $cart)."\n\n\u{1F4CD} Please send your *delivery location* (area / landmark) to place the order.";
        }

        // --- "add ..." or "<qty> <product>" => add to cart ---
        [$qty, $term] = $this->parseQtyAndTerm($lc, $text);
        $results = $this->search->find($term);

        if ($results->isEmpty()) {
            return "I couldn't find \"{$term}\". Try another name, or type *cart* / *checkout*.";
        }

        // explicit add (or a quantity was given) => add the top match
        if (Str::startsWith($lc, ['add ', 'i want ', 'buy ']) || $qty > 1 || preg_match('/\b(x|qty|pcs|kg)\b/', $lc)) {
            $p = $results->first();
            $net = Pricing::net($tenant, (float) $p->price);
            $cart = $this->addToCart($cart, $p->id, $p->name, $net, $qty);
            $convo->cart = $cart; $convo->save();
            return "Added *{$qty} x {$p->name}*.\n\n".$this->cartSummary($tenant, $cart)
                 . "\n\nAdd more, or type *checkout*.";
        }

        // otherwise show matches
        $cur = $tenant->setting('currency', 'UGX');
        $lines = $results->take(5)->map(function ($p) use ($tenant) {
            return "• {$p->name} — ".Pricing::money($tenant, Pricing::net($tenant, (float) $p->price));
        })->implode("\n");
        return "Here's what I found:\n{$lines}\n\nReply *add <item>* (e.g. \"add 2 ".$results->first()->name."\").";
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
        if (!$cart) return '';
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
        if (!$cart) {
            $convo->state = []; $convo->save();
            return "Your basket is empty. Add a product to start a new order.";
        }
        $total = 0;
        foreach ($cart as $l) $total += $l['price'] * $l['qty'];
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
