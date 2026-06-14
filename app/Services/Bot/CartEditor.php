<?php

namespace App\Services\Bot;

/**
 * Cart management: remove / clear / change-quantity by line number OR product name.
 * Pure & static — operates on plain cart arrays ([['name','qty','price','product_id'], ...]).
 *
 * isEditIntent() decides whether a message is a cart command at all (so non-edit messages
 * fall through to normal shopping). apply() performs it and reports what changed, or returns
 * null if the target couldn't be resolved.
 *
 * Supports:
 *   remove item 1            delete item 4         remove 1,3
 *   remove item 1,2,3        remove Redbull        delete Splash Juice
 *   remove everything        clear cart            empty
 *   change item 2 to 5       make Redbull 10       reduce Beer to 2
 *   increase Splash Juice to 6   make it 5   only 1 (last item)
 */
class CartEditor
{
    public static function isEditIntent(string $text): bool
    {
        $lc = self::norm($text);
        if ($lc === '') return false;
        if (self::isClear($lc)) return true;
        if (preg_match('/\b(remove|delete|take out)\b/', $lc)) return true;
        if (preg_match('/^(change|make|set|reduce|increase|update)\b.*\d/', $lc)) return true;
        // "update" / "update order" with a quantity anywhere — not only at the start
        // ("i need 10 pcs update order" is a quantity update, not a product search).
        if (preg_match('/\bupdate\b/', $lc) && preg_match('/\d/', $lc)) return true;
        if (CartCorrection::newQuantity($text) !== null) return true;
        return false;
    }

    /** @return array{cart:array,removed:array,changed:array,cleared:bool}|null */
    public static function apply(array $cart, string $text): ?array
    {
        $lc   = self::norm($text);
        $cart = array_values($cart);

        if (self::isClear($lc)) {
            return ['cart' => [], 'removed' => array_map(fn ($l) => (string) ($l['name'] ?? ''), $cart),
                    'changed' => [], 'cleared' => true];
        }

        if (preg_match('/\b(remove|delete|take out)\b/', $lc)) {
            return self::doRemove($cart, $lc);
        }

        if (preg_match('/^(change|make|set|reduce|increase|update)\b/', $lc)) {
            $r = self::doQty($cart, $lc);
            if ($r !== null) return $r;
        }

        // pronoun / "only N" — last item
        $n = CartCorrection::newQuantity($text);
        if ($n !== null && $cart) {
            $i = count($cart) - 1;
            $cart[$i]['qty'] = $n;
            return ['cart' => $cart, 'removed' => [],
                    'changed' => [['name' => (string) ($cart[$i]['name'] ?? ''), 'qty' => $n]], 'cleared' => false];
        }

        return null;
    }

    private static function doRemove(array $cart, string $lc): ?array
    {
        $body = preg_replace('/\b(remove|delete|take out)\b/', ' ', $lc);
        $body = preg_replace('/\b(item|items|line|lines|number|no|the|from cart|from my cart|please|pls|from basket)\b/', ' ', $body);
        $body = trim(preg_replace('/\s+/', ' ', $body));

        // by line number(s): "1,2,3" / "1 3" / "4"
        if ($body !== '' && self::isNumbersOnly($body) && preg_match_all('/\d+/', $body, $m)) {
            $nums = array_values(array_unique(array_map('intval', $m[0])));
            rsort($nums); // remove from the end so earlier indices stay valid
            $removed = [];
            foreach ($nums as $n) {
                $idx = $n - 1;
                if (isset($cart[$idx])) { $removed[] = (string) ($cart[$idx]['name'] ?? ''); unset($cart[$idx]); }
            }
            if (! $removed) return null;
            return ['cart' => array_values($cart), 'removed' => array_reverse($removed), 'changed' => [], 'cleared' => false];
        }

        // by name (substring, case-insensitive)
        $q = trim($body);
        if ($q === '') return null;
        $removed = []; $kept = [];
        foreach ($cart as $l) {
            if (stripos((string) ($l['name'] ?? ''), $q) !== false) $removed[] = (string) ($l['name'] ?? '');
            else $kept[] = $l;
        }
        if (! $removed) return null;
        return ['cart' => array_values($kept), 'removed' => $removed, 'changed' => [], 'cleared' => false];
    }

    private static function doQty(array $cart, string $lc): ?array
    {
        $rest = preg_replace('/^(?:change|make|set|reduce|increase|update)\s+/', '', $lc);
        if (! preg_match('/(\d{1,3})\s*(?:pcs|pkts|packs|units|items)?$/', $rest, $qm)) return null;
        $qty    = max(1, (int) $qm[1]);
        $target = trim(preg_replace('/\s*(?:to\s+)?\d{1,3}\s*(?:pcs|pkts|packs|units|items)?$/', '', $rest));
        if ($target === '') return null;

        $idx = null;
        if (preg_match('/^(?:item|line|no|number|#)?\s*(\d+)$/', $target, $mm)) {
            $idx = ((int) $mm[1]) - 1;
        } elseif (in_array($target, ['it', 'this', 'that', 'this one', 'that one'], true)) {
            $idx = count($cart) - 1;
        } else {
            foreach ($cart as $i => $l) {
                if (stripos((string) ($l['name'] ?? ''), $target) !== false) { $idx = $i; break; }
            }
        }
        if ($idx === null || ! isset($cart[$idx])) return null;
        $cart[$idx]['qty'] = $qty;
        return ['cart' => array_values($cart), 'removed' => [],
                'changed' => [['name' => (string) ($cart[$idx]['name'] ?? ''), 'qty' => $qty]], 'cleared' => false];
    }

    private static function isClear(string $lc): bool
    {
        if (in_array($lc, ['clear', 'empty', 'reset', 'clear cart', 'empty cart', 'reset cart',
            'clear my cart', 'empty my cart', 'clear basket', 'empty basket', 'start over',
            'remove everything', 'delete all', 'remove all'], true)) {
            return true;
        }
        return (bool) preg_match('/\b(remove|delete|clear|empty)\s+(everything|all|all items|the cart|my cart|the basket)\b/', $lc)
            || (bool) preg_match('/\bcancel\s+(order|everything|the order|my order)\b/', $lc);
    }

    private static function isNumbersOnly(string $s): bool
    {
        $t = preg_replace('/\band\b/', ' ', $s);
        return preg_match('/\d/', $t) === 1 && preg_match('/[a-z]/', $t) === 0;
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
