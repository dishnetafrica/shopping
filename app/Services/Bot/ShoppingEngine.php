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
        private array $defaults = [],        // term => product_id (tenant defaults)
        private string $strategy = 'explicit', // 'off' | 'explicit' | 'explicit_then_auto'
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
        $added = []; $groups = []; $notFound = []; $sizeNotes = [];
        $defaultUsed = false; $hintVariants = [];
        foreach ($items as $item) {
            $res = $this->resolveItem($item, $products, $parsed['browse']);
            if ($res['status'] === 'none') { $notFound[] = $item['query']; continue; }
            if ($res['status'] === 'clarify') {
                // qty for a clarification is an explicit COUNT only — a size token (e.g. "200g")
                // is the thing being clarified, never a quantity. Selection adds 1 unless the
                // customer gave a real count ("2 sikandar peanuts").
                $groups[] = ['label' => $item['query'], 'qty' => (int) ($item['count'] ?? 1), 'products' => $res['products']];
                continue;
            }
            if ($res['status'] === 'size_unavailable') {
                $sizeNotes[] = '*' . $res['requested'] . '* isn\'t available — we have *' . implode('*, *', $res['available']) . '*';
                $groups[] = ['label' => $item['query'], 'qty' => (int) ($item['count'] ?? 1), 'products' => $res['products']];
                continue;
            }
            $p = $res['product'];
            $useQty = $res['qty'] ?? (int) $item['qty'];
            $autoAdd = $addIntent || in_array($res['via'] ?? '', ['default', 'size', 'auto'], true);
            if ($autoAdd) {
                $cart = $this->addToCart($cart, $p['id'] ?? null, $p['name'], (float) $p['price'], (int) $useQty);
                $added[] = $useQty . ' x ' . $p['name'];
                if (($res['via'] ?? '') === 'default') {
                    $defaultUsed = true;
                    $hintVariants = array_merge($hintVariants, $res['siblings'] ?? []);
                }
            } else {
                $groups[] = ['label' => $item['query'], 'qty' => (int) ($item['count'] ?? 1), 'products' => [$p]];
            }
        }

        $flat = [];
        if ($groups) {
            $built = $this->clarify->buildOptions($groups, fn ($a) => $this->money($a));
            $flat = $built['flat'];
            $state['options'] = $flat;
        } elseif ($added) {
            unset($state['options']);   // a completed add clears any pending clarification
        }
        // else: no new groups and nothing added -> LEAVE any pending options in place so a
        // stray reply doesn't lose the active clarification (selection state must survive).

        // Nothing matched the catalogue at all -> not a shopping message we can act on.
        // Defer to BotBrain (friendly "I can help you shop" redirect) instead of a dead-end.
        if (!$added && !$groups) {
            return $this->res(false, null, $cart, $state, [], [], $notFound);
        }

        $parts = [];
        if ($added) $parts[] = 'Added *' . implode('*, *', $added) . '*.';
        if ($sizeNotes) $parts[] = "\u{1F4CF} " . implode('. ', $sizeNotes) . '.';
        if ($groups) {
            $head = $parsed['browse'] ? "Yes \u{1F44D} here's what we have:" : "Here's what we have:";
            $parts[] = $head . "\n" . $built['text'] . "\n\nReply with the *number(s)* you want (e.g. 1, 3).";
        }
        if ($notFound) $parts[] = "I couldn't find: " . implode(', ', $notFound) . '.';
        if ($added && !$groups) $parts[] = $this->cartSummary($cart) . "\n\nAdd more, or say *checkout*.";

        // size hint: shown only ONCE per conversation, when a default was auto-applied
        if ($defaultUsed && $hintVariants && empty($state['size_hint_shown'])) {
            $eg = array_slice(array_values(array_unique($hintVariants)), 0, 2);
            if ($eg) {
                $parts[] = 'Want a different size? Just say e.g. *' . implode('* or *', $eg) . '*.';
                $state['size_hint_shown'] = true;
            }
        }

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
            $out[] = ['query' => $w, 'qty' => $item['qty'], 'count' => $item['count'] ?? null, 'size' => null, 'unit' => null];
        }
        return $out;
    }

    /**
     * Resolve one parsed item -> single | clarify | none, applying size + default rules.
     * Precedence (multiple candidates):
     *   1. stated size matches exactly one SKU -> that SKU (size wins)
     *   2. stated size matches 0 or >1 SKUs   -> CLARIFY (size conflict)
     *   3. no size, owner default valid+in-stock -> default
     *   4. no size, strategy=explicit_then_auto  -> auto-pick (future ranking; cheapest/smallest for now)
     *   5. otherwise                              -> CLARIFY
     * A single candidate always resolves directly (size acts as a count -> preserves "2kg sugar"=2).
     */
    public function resolveItem(array $item, array $products, bool $browse = false): array
    {
        $query = $item['query'];
        $qty = (int) ($item['qty'] ?? 1);
        $size = $item['size'] ?? null;
        $count = $item['count'] ?? null;

        $cands = $this->matcher->search($query, $products);
        if (!$cands) return ['status' => 'none'];

        // single candidate: resolve directly; a size token here just means count (Cat 3 behaviour)
        if (count($cands) === 1) {
            return ['status' => 'single', 'product' => $cands[0]['product'], 'qty' => $qty];
        }

        // explicit browse ("show me rice" / "which rice") -> always list all, never auto-pick
        if ($browse) {
            $opts = $this->matcher->clarifyCheck($query, $products);
            if ($opts !== null) return ['status' => 'clarify', 'products' => $opts];
            return ['status' => 'clarify', 'products' => array_map(fn ($c) => $c['product'], array_slice($cands, 0, 5))];
        }

        // multiple candidates ---------------------------------------------------
        if ($size !== null) {
            $sized = array_values(array_filter($cands, fn ($c) => CatalogueMatcher::skuSize($c['product']['name'] ?? '') === $size));
            if (count($sized) === 1) {
                return ['status' => 'single', 'product' => $sized[0]['product'], 'qty' => $count ?? 1, 'via' => 'size'];
            }
            if (count($sized) > 1) {
                // several SKUs share that exact size -> let them pick which one
                return ['status' => 'clarify', 'products' => array_map(fn ($c) => $c['product'], array_slice($sized, 0, 5))];
            }
            // requested size matches NO SKU -> tell the customer which sizes ARE available
            $avail = [];
            foreach ($cands as $c) {
                $sz = CatalogueMatcher::skuSize($c['product']['name'] ?? '');
                if ($sz) $avail[$sz] = true;
            }
            if ($avail) {
                return ['status' => 'size_unavailable', 'requested' => $size, 'available' => array_keys($avail),
                        'products' => array_map(fn ($c) => $c['product'], array_slice($cands, 0, 5))];
            }
            return ['status' => 'clarify', 'products' => array_map(fn ($c) => $c['product'], array_slice($cands, 0, 5))];
        }

        // no size: try the owner's default for this term
        $term = implode(' ', $this->matcher->tokens($query));
        if ($this->strategy !== 'off' && $term !== '' && isset($this->defaults[$term])) {
            $pid = $this->defaults[$term];
            foreach ($cands as $c) {
                $p = $c['product'];
                if (($p['id'] ?? null) == $pid && ($p['stock'] ?? 1) > 0) {
                    $siblings = [];
                    foreach ($cands as $cc) {
                        $sz = CatalogueMatcher::skuSize($cc['product']['name'] ?? '');
                        if ($sz) $siblings[] = $term . ' ' . $sz;
                    }
                    return ['status' => 'single', 'product' => $p, 'qty' => $qty, 'via' => 'default', 'siblings' => $siblings];
                }
            }
        }

        // optional auto-pick when no default (future ranking: best-seller -> recent -> cheapest -> smallest)
        if ($this->strategy === 'explicit_then_auto') {
            // Pick among the MOST relevant candidates only (top search score), then cheapest —
            // so a cheap off-noun product can't win on price alone (e.g. yoghurt for "milk").
            $top = $cands[0]['score'];
            $pick = array_values(array_filter($cands, fn ($c) => $c['score'] >= $top - 0.001));
            usort($pick, function ($a, $b) {
                $pa = (float) ($a['product']['price'] ?? 0); $pb = (float) ($b['product']['price'] ?? 0);
                if ($pa !== $pb) return $pa <=> $pb;                 // cheapest
                return strlen($a['product']['name']) <=> strlen($b['product']['name']);
            });
            return ['status' => 'single', 'product' => $pick[0]['product'], 'qty' => $qty, 'via' => 'auto'];
        }

        // otherwise: clarify (price-spread groups first, else top candidates)
        $opts = $this->matcher->clarifyCheck($query, $products);
        if ($opts !== null) return ['status' => 'clarify', 'products' => $opts];
        return ['status' => 'clarify', 'products' => array_map(fn ($c) => $c['product'], array_slice($cands, 0, 5))];
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
