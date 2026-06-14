<?php
require dirname(__DIR__) . '/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__) . '/app/Services/Bot/ShoppingParser.php';
require dirname(__DIR__) . '/app/Services/Bot/ClarificationFlow.php';
require dirname(__DIR__) . '/app/Services/Bot/ShoppingEngine.php';

use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ShoppingParser;
use App\Services\Bot\ClarificationFlow;
use App\Services\Bot\ShoppingEngine;

// store: rice in 3 sizes (default 5kg), sugar single, milk in 2 sizes (default 1L)
$CAT = [
    ['id'=>1,'name'=>'Local Rice 1kg','category'=>'Rice','keywords'=>'chawal','price'=>6300,'stock'=>10],
    ['id'=>2,'name'=>'Pearl Rice 2kg','category'=>'Rice','keywords'=>'chawal','price'=>12000,'stock'=>10],
    ['id'=>3,'name'=>'Pakistan Rice 5kg','category'=>'Rice','keywords'=>'chawal','price'=>38000,'stock'=>10],
    ['id'=>4,'name'=>'Kinyara Sugar 1kg','category'=>'Sugar','keywords'=>'sakar','price'=>4500,'stock'=>10],
    ['id'=>5,'name'=>'Jesa Milk 500ml','category'=>'Milk','keywords'=>'doodh','price'=>1800,'stock'=>10],
    ['id'=>6,'name'=>'Fresh Dairy Milk 1L','category'=>'Milk','keywords'=>'doodh','price'=>3500,'stock'=>10],
];
// owner defaults (term => product_id)
$DEF = ['rice' => 3, 'milk' => 6];

function eng(array $defaults = [], string $strategy = 'explicit'): ShoppingEngine {
    return new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX', $defaults, $strategy);
}
function cartQty(array $r, string $kw): int { foreach ($r['cart'] as $l) if (stripos($l['name'],$kw)!==false) return $l['qty']; return 0; }
function cartName(array $r, string $kw): ?string { foreach ($r['cart'] as $l) if (stripos($l['name'],$kw)!==false) return $l['name']; return null; }
function optCount(array $r): int { return count($r['state']['options'] ?? []); }

$pass=0;$fail=0;$fails=[];
function ck(string $l, bool $ok){ global $pass,$fail,$fails; if($ok){$pass++;echo "  PASS  $l\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l\n";} }

echo "\n========= DEFAULT PRODUCT STRATEGY — TEST SUITE =========\n";
global $CAT,$DEF;

echo "\n-- A. Default applied to generic request (fewer questions) --\n";
$r = eng($DEF)->handle('Rice', $CAT, [], []);
ck('A1 "Rice" (bare search) -> CLARIFY, never auto-add', count($r['cart'])===0 && optCount($r)>=2);
$r = eng($DEF)->handle('add rice', $CAT, [], []);
ck('A1b "add rice" -> Added default Rice 5kg', cartName($r,'Rice')==='Pakistan Rice 5kg' && cartQty($r,'Rice')===1);
$r = eng($DEF)->handle('5 rice', $CAT, [], []);
ck('A2 "5 rice" -> 5 x default Rice 5kg', cartName($r,'Rice')==='Pakistan Rice 5kg' && cartQty($r,'Rice')===5);
$r = eng($DEF)->handle('milk', $CAT, [], []);
ck('A3 "milk" (bare search) -> CLARIFY, never auto-add', count($r['cart'])===0 && optCount($r)>=2);
$r = eng($DEF)->handle('add milk', $CAT, [], []);
ck('A3b "add milk" -> Added default Fresh Dairy 1L', cartName($r,'Milk')==='Fresh Dairy Milk 1L');

echo "\n-- B. Size wins over default (order accuracy) --\n";
$r = eng($DEF)->handle('rice 2kg', $CAT, [], []);
ck('B1 "rice 2kg" -> Pearl Rice 2kg (not default), qty 1', cartName($r,'Rice')==='Pearl Rice 2kg' && cartQty($r,'Rice')===1);
$r = eng($DEF)->handle('2 5kg rice', $CAT, [], []);
ck('B2 "2 5kg rice" -> 2 x Rice 5kg', cartName($r,'Rice')==='Pakistan Rice 5kg' && cartQty($r,'Rice')===2);
$r = eng($DEF)->handle('1kg rice', $CAT, [], []);
ck('B3 "1kg rice" -> Local Rice 1kg', cartName($r,'Rice')==='Local Rice 1kg');

echo "\n-- C. Size conflict -> clarify (no wrong order) --\n";
$r = eng($DEF)->handle('rice 3kg', $CAT, [], []);  // no 3kg SKU
ck('C1 "rice 3kg" (not stocked) -> clarify, nothing added', count($r['cart'])===0 && optCount($r)>=2);

echo "\n-- D. No default -> clarify (never guess) --\n";
$r = eng([])->handle('Rice', $CAT, [], []);        // defaults empty
ck('D1 "Rice" with no default -> shows options, nothing added', count($r['cart'])===0 && optCount($r)>=2);
$r = eng(['rice'=>3])->handle('milk', $CAT, [], []); // default for rice only, not milk
ck('D2 "milk" (no milk default) -> shows options, nothing added', count($r['cart'])===0 && optCount($r)===2);

echo "\n-- E. Single SKU unaffected (Cat 3 preserved) --\n";
$r = eng($DEF)->handle('2kg sugar', $CAT, [], []);
ck('E1 "2kg sugar" (single 1kg SKU) -> 2 x Kinyara Sugar 1kg', cartQty($r,'Sugar')===2);
$r = eng($DEF)->handle('sugar', $CAT, [], []);
ck('E2 "sugar" (single SKU) -> shown (browse), nothing added', count($r['cart'])===0 && optCount($r)===1);

echo "\n-- F. Out-of-stock / broken default -> clarify, never add dead SKU --\n";
$cat2 = $CAT; $cat2[2]['stock'] = 0;  // Rice 5kg (default) out of stock
$r = eng($DEF)->handle('rice', $cat2, [], []);
ck('F1 default out of stock -> clarify, nothing added', count($r['cart'])===0 && optCount($r)>=2);

echo "\n-- G. Size hint shown only ONCE per conversation --\n";
$r1 = eng($DEF)->handle('add rice', $CAT, [], []);
$hint1 = stripos($r1['reply'],'different size')!==false;
$r2 = eng($DEF)->handle('add milk', $CAT, $r1['cart'], $r1['state']);   // thread state
$hint2 = stripos($r2['reply'],'different size')!==false;
ck('G1 hint shown on first default use', $hint1 === true);
ck('G2 hint NOT shown again later in same conversation', $hint2 === false);
ck('G3 state flag set', !empty($r1['state']['size_hint_shown']));

echo "\n-- H. Strategy: off (always ask) and explicit_then_auto (always pick) --\n";
$r = eng($DEF, 'off')->handle('rice', $CAT, [], []);
ck('H1 strategy=off -> ignores default, clarifies', count($r['cart'])===0 && optCount($r)>=2);
$r = eng([], 'explicit_then_auto')->handle('rice', $CAT, [], []);
ck('H2a bare "rice" -> CLARIFY even under explicit_then_auto', count($r['cart'])===0 && optCount($r)>=2);
$r = eng([], 'explicit_then_auto')->handle('add rice', $CAT, [], []);
ck('H2b "add rice" -> auto-picks cheapest (Local Rice 1kg)', cartName($r,'Rice')==='Local Rice 1kg');

echo "\n-- I. Browse still overrides default --\n";
$r = eng($DEF)->handle('show me rice', $CAT, [], []);
ck('I1 "show me rice" -> lists all, nothing added', count($r['cart'])===0 && optCount($r)===3);

echo "\n========= RESULT =========\n";
printf("PASS %d  FAIL %d\n", $pass, $fail);
if ($fails){ echo "Fails:\n"; foreach($fails as $f) echo "  - $f\n"; exit(1);}
echo "ALL GREEN ✅\n";
