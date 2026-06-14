<?php
namespace App\Services\Bot;

/**
 * ShoppingEngine — deterministic conversational core (framework-free).
 * Resolves a pending selection, otherwise parses + resolves items, ADDs on an
 * explicit verb/quantity, otherwise SHOWS numbered options (browse). Defers
 * unsupported EDIT ops to keep the cart safe.
 */
class ShoppingEngine
{
    public function __construct(
        private ShoppingParser $parser,
        private CatalogueMatcher $matcher,
        private ClarificationFlow $clarify,
        private string $currency = 'UGX',
    ) {}

    private function money(float $a): string { return $this->currency . ' ' . number_format($a); }

    public function handle(string $text, array $products, array $cart, array $state): array
    {
        $pending = $state['options'] ?? [];
        if (is_array($pending) && $pending) {
            $picks = $this->clarify->resolveSelection($text, $pending);
            if ($picks) {
                $added = [];
                foreach ($picks as $opt) {
                    $cart = $this->addToCart($cart, $opt['product_id'], $opt['name'], $opt['price'], (int) ($opt['qty'] ?: 1));
                    $added[] = ($opt['qty'] ?: 1) . ' x ' . $opt['name'];
                }
                unset($state['options']);
                return $this->res(true, 'Added *' . implode('*, *', $added) . "*.\n\n" . $this->cartSummary($cart)
                    . "\n\nAdd more, or say *checkout*.", $cart, $state, $added, [], []);
            }
        }

        $parsed = $this->parser->parse($text);
        if ($parsed['edit'] || !$parsed['items']) {
            // EDIT ops are Phase 2; nothing to add -> let BotBrain handle (greet/unknown). Cart untouched.
            return $this->res(false, null, $cart, $state, [], [], []);
        }

        // catalogue-aware split: "rice sugar oil" -> 3 items, but keep "cooking oil" as one
        $items = [];
        foreach ($parsed['items'] as $it) {
            foreach ($this->maybeSplit($it, $products) as $sub) $items[] = $sub;
        }

        $addIntent = $parsed['add_intent'];
        $added = []; $groups = []; $notFound = [];
        foreach ($items as $item) {
            $res = $this->resolveItem($item['query'], (int) $item['qty'], $products);
            if ($res['status'] === 'none') { $notFound[] = $item['query']; continue; }
            if ($res['status'] === 'clarify') {
                $groups[] = ['label' => $item['query'], 'qty' => (int) $item['qty'], 'products' => $res['products']];
                continue;
            }
            $p = $res['product'];
            if ($addIntent) {
                $cart = $this->addToCart($cart, $p['id'] ?? null, $p['name'], (float) $p['price'], (int) $item['qty']);
                $added[] = $item['qty'] . ' x ' . $p['name'];
            } else {
                $groups[] = ['label' => $item['query'], 'qty' => (int) $item['qty'], 'products' => [$p]];
            }
        }

        $flat = [];
        if ($groups) {
            $built = $this->clarify->buildOptions($groups, fn ($a) => $this->money($a));
            $flat = $built['flat'];
            $state['options'] = $flat;
        } else {
            unset($state['options']);
        }

        // Nothing matched the catalogue at all -> not a shopping message we can act on.
        // Defer to BotBrain (friendly "I can help you shop" redirect) instead of a dead-end.
        if (!$added && !$groups) {
            return $this->res(false, null, $cart, $state, [], [], $notFound);
        }

        $parts = [];
        if ($added) $parts[] = 'Added *' . implode('*, *', $added) . '*.';
        if ($groups) {
            $head = $parsed['browse'] ? "Yes \u{1F44D} here's what we have:" : "Here's what we have:";
            $parts[] = $head . "\n" . $built['text'] . "\n\nReply with the *number(s)* you want (e.g. 1, 3).";
        }
        if ($notFound) $parts[] = "I couldn't find: " . implode(', ', $notFound) . '.';
        if ($added && !$groups) $parts[] = $this->cartSummary($cart) . "\n\nAdd more, or say *checkout*.";

        return $this->res(true, implode("\n\n", $parts), $cart, $state, $added, $flat, $notFound);
    }

    /** Split a multi-word item into separate products only when the whole doesn't cover and each word resolves. */
    private function maybeSplit(array $item, array $products): array
    {
        $qtoks = $this->matcher->tokens($item['query']);
        if (count($qtoks) < 2) return [$item];
        $cands = $this->matcher->search($item['query'], $products);
        $bestHits = $cands ? ($cands[0]['hits'] ?? 0) : 0;
        if ($bestHits >= count($qtoks)) return [$item];      // whole is a real multi-word product (e.g. cooking oil)
        $out = [];
        foreach ($qtoks as $w) {
            if (!$this->matcher->search($w, $products)) return [$item];   // a word doesn't resolve -> don't split
            $out[] = ['query' => $w, 'qty' => $item['qty'], 'unit' => null];
        }
        return $out;
    }

    public function resolveItem(string $query, int $qty, array $products): array
    {
        $cands = $this->matcher->search($query, $products);
        if (!$cands) return ['status' => 'none'];
        $opts = $this->matcher->clarifyCheck($query, $products);
        if ($opts !== null) return ['status' => 'clarify', 'products' => $opts];
        return ['status' => 'single', 'product' => $cands[0]['product']];
    }

    private function addToCart(array $cart, $id, string $name, float $price, int $qty): array
    {
        foreach ($cart as &$line) {
            if ($id !== null && $line['product_id'] === $id) { $line['qty'] += $qty; return $cart; }
            if ($id === null && $line['name'] === $name) { $line['qty'] += $qty; return $cart; }
        }
        $cart[] = ['product_id' => $id, 'name' => $name, 'price' => $price, 'qty' => max(1, $qty)];
        return $cart;
    }

    public function cartSummary(array $cart): string
    {
        if (!$cart) return 'Your basket is empty.';
        $total = 0; $lines = [];
        foreach ($cart as $l) {
            $sub = $l['price'] * $l['qty']; $total += $sub;
            $lines[] = "• {$l['qty']} x {$l['name']} — " . $this->money($sub);
        }
        return "\u{1F6D2} *Your basket*\n" . implode("\n", $lines) . "\n*Total: " . $this->money($total) . '*';
    }

    private function res(bool $handled, ?string $reply, array $cart, array $state, array $added, array $options, array $notFound): array
    {
        return ['handled' => $handled, 'reply' => $reply, 'cart' => $cart, 'state' => $state,
                'added' => $added, 'options' => $options, 'not_found' => $notFound];
    }
}
