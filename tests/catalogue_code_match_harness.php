<?php
/**
 * Pure harness for CatalogueMatcher model/SKU-code resolution (no Laravel).
 * Proves "8389E" / "8389" resolve to the product whose SKU is 8389E even though the code is NOT a
 * word in the product name — the gap behind "what is the size of this container?" (8389E) missing.
 *
 * Run:  php tests/catalogue_code_match_harness.php
 */

require __DIR__ . '/../app/Services/Bot/CatalogueMatcher.php';
use App\Services\Bot\CatalogueMatcher;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got) . "\n   want: " . json_encode($want) . "\n"; }
}

// ---- queryCodes(): pull model/part codes, skip plain sizes/qty ----
check('code with letter+digit', CatalogueMatcher::queryCodes('what size is 8389E?'), ['8389e']);
check('4+ digit code', CatalogueMatcher::queryCodes('do you have 8389'), ['8389']);
check('part number', CatalogueMatcher::queryCodes('need A9F74101 breaker'), ['a9f74101']);
check('skip size 500g', CatalogueMatcher::queryCodes('sugar 500g'), []);
check('skip pure qty/word', CatalogueMatcher::queryCodes('2 rice bags'), []);
check('skip dimension 45cm', CatalogueMatcher::queryCodes('cling film 45cm'), []);

// ---- productCodes(): from sku/oem/name ----
check('sku code', CatalogueMatcher::productCodes(['name' => 'Aluminum Foil Container with Lid', 'sku' => '8389E']), ['8389e']);
check('code embedded in name', CatalogueMatcher::productCodes(['name' => 'Foil Container 8389E with Lid', 'sku' => '']), ['8389e']);

// ---- codesIntersect(): exact + leading-stem ----
check('exact code match', CatalogueMatcher::codesIntersect(['8389e'], ['8389e']), true);
check('numeric stem matches full code', CatalogueMatcher::codesIntersect(['8389'], ['8389e']), true);
check('unrelated code no match', CatalogueMatcher::codesIntersect(['1234'], ['8389e']), false);

// ---- search(): the real thing — 8389E resolves though the code is only in SKU ----
$products = [
    ['id' => 1, 'name' => 'Aluminum Foil Container with Lid', 'category' => 'Foil', 'keywords' => 'foil container takeaway', 'sku' => '8389E', 'oem_number' => '', 'price' => 61, 'stock' => 5],
    ['id' => 2, 'name' => 'Cling Film 45cm',                  'category' => 'Wrap', 'keywords' => 'cling film',            'sku' => 'CF45',  'oem_number' => '', 'price' => 30, 'stock' => 5],
    ['id' => 3, 'name' => 'Aluminium Foil 45cm Golden',       'category' => 'Foil', 'keywords' => 'foil roll',             'sku' => '',      'oem_number' => '', 'price' => 80, 'stock' => 5],
];
$m = new CatalogueMatcher();

$r1 = $m->search('what is the size of 8389E?', $products);
check('8389E → top hit is the 8389E product', $r1 ? $r1[0]['product']['id'] : null, 1);
check('8389E hit scored by code (>=900)', $r1 && $r1[0]['score'] >= 900, true);

$r2 = $m->search('8389', $products);
check('bare 8389 → resolves to 8389E product', $r2 ? $r2[0]['product']['id'] : null, 1);

// A plain word query still works and does NOT get a spurious code boost.
$r3 = $m->search('cling film', $products);
check('plain query still resolves normally', $r3 ? $r3[0]['product']['id'] : null, 2);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
