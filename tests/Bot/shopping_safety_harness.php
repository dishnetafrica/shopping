<?php

declare(strict_types=1);

/**
 * Phase-0 Shopping-Safety harness (pure PHP, no framework boot — runs in the sandbox).
 *
 * Proves the unit/quantity/weight invariant on the DETERMINISTIC path by driving the REAL
 * ShoppingEngine over an in-memory catalogue, plus direct UnitGuard checks that also cover the
 * post-NLU/LLM guard (a size folded into qty). Companion PHPUnit test: tests/Unit/Bot/UnitGuardTest.php.
 *
 * THE INVARIANT UNDER TEST: a weight/volume size ("250g") must never become the cart quantity.
 *
 * Run:  php tests/Bot/shopping_safety_harness.php
 */

$base = dirname(__DIR__, 2) . '/app/Services/Bot/';
require __DIR__ . '/pricing_stub.php';   // stand-in for App\Services\Pricing (sandbox only)
require $base . 'CatalogueMatcher.php';
require $base . 'ShoppingParser.php';
require $base . 'ClarificationFlow.php';
require $base . 'UnitGuard.php';
require $base . 'ShoppingEngine.php';

use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ClarificationFlow;
use App\Services\Bot\ShoppingEngine;
use App\Services\Bot\ShoppingParser;
use App\Services\Bot\UnitGuard;

$pass = 0;
$fail = 0;
function G(string $g): void { echo "\n\033[1m$g\033[0m\n"; }
function ok(string $n, bool $c): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32m✓\033[0m $n\n"; }
    else    { $fail++; echo "  \033[31m✗ $n\033[0m\n"; }
}

// Distinct search terms so each probe hits a SINGLE candidate (the path that carried the bug).
$cat = [
    ['id' => 1, 'name' => 'Kabras Sugar',      'category' => 'Sugar',       'keywords' => 'sakar',   'price' => 4500,  'stock' => 100, 'sold_by_weight' => false], // sizeless, not weight
    ['id' => 2, 'name' => 'Basmati Rice 1kg',  'category' => 'Rice',        'keywords' => 'basmati', 'price' => 12000, 'stock' => 100, 'sold_by_weight' => false], // sized 1kg pack
    ['id' => 3, 'name' => 'Fafda',             'category' => 'Snacks',      'keywords' => 'fafda',   'price' => 2000,  'stock' => 100, 'sold_by_weight' => true,  'reference_price' => 2000, 'reference_weight_grams' => 1000], // sold by weight
    ['id' => 4, 'name' => 'Sunflower Oil 5L',  'category' => 'Cooking Oil', 'keywords' => 'oil tel', 'price' => 45000, 'stock' => 100, 'sold_by_weight' => false], // volume pack
    ['id' => 5, 'name' => 'Superloaf Bread',   'category' => 'Bakery',      'keywords' => 'bread',   'price' => 4000,  'stock' => 100, 'sold_by_weight' => false],
];

$engine = new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX');

/** qty of a product in the resulting cart (0 if absent). */
function cartQty(array $cart, string $needle): int
{
    foreach ($cart as $l) {
        if (stripos((string) ($l['name'] ?? ''), $needle) !== false) {
            return (int) ($l['qty'] ?? 0);
        }
    }
    return 0;
}
function cartCount(array $cart): int { return count($cart); }

// ---------------------------------------------------------------------------
G('THE REPRODUCED FAILURE — "add 250g sugar" must NOT become qty 250');
$r = $engine->handle('add 250g sugar', $cat, [], []);
ok('cart has no 250-qty explosion (audit case)', cartQty($r['cart'], 'Kabras') !== 250);
ok('sizeless SKU + sub-unit size is NOT auto-added (clarify instead)', cartCount($r['cart']) === 0);

// Show the before/after for the record.
$oldParse = (new ShoppingParser())->parse('add 250g sugar');
$new = $oldParse['items'][0] ?? [];
$oldQty = $new['count'] ?? ($new['size'] !== null ? (int) preg_replace('/\D+/', '', (string) $new['size']) : 1); // count ?? sizeNum ?? 1 (old)
echo "    before(parser old rule count??sizeNum??1): qty {$oldQty}  →  after(parser): qty " . ($new['qty'] ?? '?') . " (size " . ($new['size'] ?? 'null') . ")\n";
ok('parser: "250g" yields qty 1, size preserved as 250g', ($new['qty'] ?? null) === 1 && ($new['size'] ?? null) === '250g');

// ---------------------------------------------------------------------------
G('FULL MATRIX on a sizeless, non-weight SKU (Kabras Sugar) — weight/volume never becomes qty');
foreach (['250g' => 250, '500g' => 500, '1kg' => 1, '2kg' => 2, '250ml' => 250, '500ml' => 500, '1l' => 1, '2l' => 2] as $sz => $badQty) {
    $rr = $engine->handle("add {$sz} sugar", $cat, [], []);
    $q  = cartQty($rr['cart'], 'Kabras');
    ok("\"{$sz} sugar\" → never adds qty {$badQty} (got cart qty {$q}, clarify)", $q !== $badQty && cartCount($rr['cart']) === 0);
}

G('FULL MATRIX — count units ARE quantities (pieces / cartons / boxes / packs)');
foreach (['5 pieces sugar', '5 cartons sugar', '5 boxes sugar', '5 packs sugar'] as $msg) {
    $rr = $engine->handle($msg, $cat, [], []);
    ok("\"{$msg}\" → qty 5", cartQty($rr['cart'], 'Kabras') === 5);
}

// ---------------------------------------------------------------------------
G('LEGITIMATE pack composition is preserved (safe auto-resolve)');
$r = $engine->handle('2kg rice', $cat, [], []);          // 2 x 1kg pack
ok('"2kg rice" (1kg pack) → qty 2 via composition', cartQty($r['cart'], 'Basmati') === 2);
$r = $engine->handle('1kg rice', $cat, [], []);
ok('"1kg rice" (exact pack) → qty 1', cartQty($r['cart'], 'Basmati') === 1);
$r = $engine->handle('3kg rice', $cat, [], []);
ok('"3kg rice" → qty 3 via composition', cartQty($r['cart'], 'Basmati') === 3);

G('AMBIGUOUS sizes clarify instead of guessing');
$r = $engine->handle('250g rice', $cat, [], []);          // can't make 250g from 1kg packs
ok('"250g rice" (1kg pack) → clarify, no add, never qty 250', cartCount($r['cart']) === 0);
$r = $engine->handle('500g rice', $cat, [], []);          // half a pack
ok('"500g rice" (1kg pack) → clarify, no add', cartCount($r['cart']) === 0);
$r = $engine->handle('2kg oil', $cat, [], []);            // 2kg (weight) vs 5L (volume) — different family
ok('"2kg oil" (5L pack) → clarify, never qty 2 (C4 fixed)', cartQty($r['cart'], 'Oil') !== 2 && cartCount($r['cart']) === 0);

G('SOLD-BY-WEIGHT on the deterministic path defers (never mis-prices, never explodes)');
$r = $engine->handle('add 250g fafda', $cat, [], []);
ok('"250g fafda" → not qty 250', cartQty($r['cart'], 'Fafda') !== 250);
ok('"250g fafda" → not silently added (asks / defers to weight path)', cartCount($r['cart']) === 0);

G('Existing behaviour preserved (regression guard)');
$r = $engine->handle('give me 3 bread', $cat, [], []);
ok('"3 bread" → qty 3', cartQty($r['cart'], 'Bread') === 3);
$r = $engine->handle('sugar 2', $cat, [], []);
ok('"sugar 2" (trailing count) → qty 2', cartQty($r['cart'], 'Kabras') === 2);

// ---------------------------------------------------------------------------
G('UnitGuard::decide — direct unit checks');
ok('sizeless + 250g → clarify', UnitGuard::decide(['name' => 'Kabras Sugar', 'sold_by_weight' => false, 'size' => '250g', 'count' => null])['mode'] === 'clarify');
ok('sold-by-weight + 250g → weight 250g', UnitGuard::decide(['name' => 'Fafda', 'sold_by_weight' => true, 'size' => '250g', 'count' => null]) === ['mode' => 'weight', 'grams' => 250, 'qty' => 1]);
ok('1kg pack + 2kg → count 2 (compose)', UnitGuard::decide(['name' => 'Basmati Rice 1kg', 'sold_by_weight' => false, 'size' => '2kg', 'count' => null]) === ['mode' => 'count', 'qty' => 2]);
ok('1kg pack + 1kg → count 1 (exact)', UnitGuard::decide(['name' => 'Basmati Rice 1kg', 'sold_by_weight' => false, 'size' => '1kg', 'count' => null]) === ['mode' => 'count', 'qty' => 1]);
ok('1kg pack + 250g → clarify', UnitGuard::decide(['name' => 'Basmati Rice 1kg', 'sold_by_weight' => false, 'size' => '250g', 'count' => null])['mode'] === 'clarify');
ok('no size + count 5 → count 5', UnitGuard::decide(['name' => 'Kabras Sugar', 'sold_by_weight' => false, 'size' => null, 'count' => 5]) === ['mode' => 'count', 'qty' => 5]);
ok('no size + no count → count 1', UnitGuard::decide(['name' => 'Kabras Sugar', 'sold_by_weight' => false, 'size' => null, 'count' => null]) === ['mode' => 'count', 'qty' => 1]);
ok('cross-family "2kg" vs "5L" pack does not compose', UnitGuard::packMultiple('2kg', '5l') === null);

G('LLM-path guard — a size folded into qty is caught (BotNlu returns only {query,qty})');
ok('query "sugar", qty 250, text "add 250g sugar" → recovers size 250g', UnitGuard::sizeForItem('sugar', 250, 'add 250g sugar') === '250g');
ok('query "250g sugar", qty 1 → size 250g from the query', UnitGuard::sizeForItem('250g sugar', 1, 'add 250g sugar') === '250g');
ok('query "sugar", qty 5, text "5 sugar" → no false size (legit count)', UnitGuard::sizeForItem('sugar', 5, '5 sugar') === null);
ok('query "sugar", qty 2, text "2kg sugar" → recovers 2kg (catches C4 on LLM path)', UnitGuard::sizeForItem('sugar', 2, '2kg sugar') === '2kg');
ok('qty 1 is never treated as an inflated size', UnitGuard::sizeFoldedIntoQty('add 1kg rice', 1) === null);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('─', 60) . "\n";
echo $fail === 0
    ? "\033[32mALL {$pass} CHECKS PASSED.\033[0m\n"
    : "\033[31m{$fail} FAILED\033[0m, {$pass} passed.\n";
exit($fail === 0 ? 0 : 1);
