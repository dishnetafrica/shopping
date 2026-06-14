<?php
require dirname(__DIR__) . '/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__) . '/app/Services/Bot/ShoppingParser.php';
require dirname(__DIR__) . '/app/Services/Bot/ClarificationFlow.php';
require dirname(__DIR__) . '/app/Services/Bot/ShoppingEngine.php';

use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ShoppingParser;
use App\Services\Bot\ClarificationFlow;
use App\Services\Bot\ShoppingEngine;

// ---- Store A ----
$CAT_A = [
    ['id'=>1,'name'=>'Local Rice 1kg','category'=>'Rice','keywords'=>'chawal','price'=>6300,'stock'=>20],
    ['id'=>2,'name'=>'Pearl Rice 2kg','category'=>'Rice','keywords'=>'chawal','price'=>12000,'stock'=>20],
    ['id'=>3,'name'=>'Pakistan Rice 5kg','category'=>'Rice','keywords'=>'chawal','price'=>38000,'stock'=>20],
    ['id'=>4,'name'=>'Kinyara Sugar 1kg','category'=>'Sugar','keywords'=>'sakar','price'=>4500,'stock'=>20],
    ['id'=>5,'name'=>'Kinyara Sugar 2kg','category'=>'Sugar','keywords'=>'sakar','price'=>8500,'stock'=>20],
    ['id'=>6,'name'=>'Kinyara Sugar 5kg','category'=>'Sugar','keywords'=>'sakar','price'=>20000,'stock'=>20],
];
$DEF_A = ['rice'=>3, 'sugar'=>4];   // Rice 5kg, Sugar 1kg

// ---- Store B (separate tenant, distinct product ids) ----
$CAT_B = [
    ['id'=>101,'name'=>'Local Rice 1kg','category'=>'Rice','keywords'=>'chawal','price'=>6300,'stock'=>20],
    ['id'=>102,'name'=>'Pearl Rice 2kg','category'=>'Rice','keywords'=>'chawal','price'=>12000,'stock'=>20],
    ['id'=>103,'name'=>'Pakistan Rice 5kg','category'=>'Rice','keywords'=>'chawal','price'=>38000,'stock'=>20],
];
$DEF_B = ['rice'=>101];             // Rice 1kg

function E(array $defaults): ShoppingEngine {
    // fresh matcher per engine == per-message in production (no cross-tenant cache)
    return new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX', $defaults, 'explicit');
}
function nameOf(array $r, string $kw): ?string { foreach ($r['cart'] as $l) if (stripos($l['name'],$kw)!==false) return $l['name']; return null; }
function qtyOf(array $r, string $kw): int { foreach ($r['cart'] as $l) if (stripos($l['name'],$kw)!==false) return $l['qty']; return 0; }
function lines(array $r): int { return count($r['cart']); }
function opts(array $r): int { return count($r['state']['options'] ?? []); }

$pass=0;$fail=0;$fails=[];
function ck(string $l, bool $ok){ global $pass,$fail,$fails; if($ok){$pass++;echo "  PASS  $l\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l\n";} }

echo "\n============ FINAL PRE-PRODUCTION REGRESSION ============\n";
global $CAT_A,$DEF_A,$CAT_B,$DEF_B;

echo "\n-- DEFAULT SKU TESTS (Rice default = Rice 5kg) --\n";
$r = E($DEF_A)->handle('add rice', $CAT_A, [], []);
ck('add rice -> Rice 5kg added', nameOf($r,'Rice')==='Pakistan Rice 5kg' && qtyOf($r,'Rice')===1);
$r = E($DEF_A)->handle('5 rice', $CAT_A, [], []);
ck('5 rice -> 5 x Rice 5kg', nameOf($r,'Rice')==='Pakistan Rice 5kg' && qtyOf($r,'Rice')===5);
$r = E($DEF_A)->handle('Rice 2kg', $CAT_A, [], []);
ck('Rice 2kg -> Rice 2kg added', nameOf($r,'Rice')==='Pearl Rice 2kg' && qtyOf($r,'Rice')===1);
$r = E($DEF_A)->handle('Show me rice', $CAT_A, [], []);
ck('Show me rice -> lists all rice, none added', lines($r)===0 && opts($r)===3);
$r = E($DEF_A)->handle('Which rice do you have?', $CAT_A, [], []);
ck('Which rice do you have? -> lists all rice, none added', lines($r)===0 && opts($r)===3);

echo "\n-- MULTI PRODUCT DEFAULTS (Rice=5kg, Sugar=1kg) --\n";
$r = E($DEF_A)->handle('add rice and sugar', $CAT_A, [], []);
ck('add rice and sugar -> both added (5kg + 1kg)', nameOf($r,'Rice')==='Pakistan Rice 5kg' && nameOf($r,'Sugar')==='Kinyara Sugar 1kg' && lines($r)===2);
$r = E($DEF_A)->handle('2 Rice and 3 Sugar', $CAT_A, [], []);
ck('2 Rice and 3 Sugar -> 2x Rice 5kg, 3x Sugar 1kg', qtyOf($r,'Rice')===2 && nameOf($r,'Rice')==='Pakistan Rice 5kg' && qtyOf($r,'Sugar')===3 && nameOf($r,'Sugar')==='Kinyara Sugar 1kg');

echo "\n-- MIXED SIZE + DEFAULT --\n";
$r = E($DEF_A)->handle('add rice and sugar 2kg', $CAT_A, [], []);
ck('add rice and sugar 2kg -> Rice 5kg default + Sugar 2kg', nameOf($r,'Rice')==='Pakistan Rice 5kg' && nameOf($r,'Sugar')==='Kinyara Sugar 2kg');

echo "\n-- OUT OF STOCK (Rice 5kg OOS) --\n";
$catOOS = $CAT_A; $catOOS[2]['stock'] = 0;          // Rice 5kg default out of stock
$r = E($DEF_A)->handle('add rice', $catOOS, [], []);
ck('Rice (default OOS) -> clarify, nothing added', lines($r)===0 && opts($r)>=2);
ck('Rice (default OOS) -> OOS SKU never added', nameOf($r,'Rice')===null);

echo "\n-- MULTI STORE / TENANT ISOLATION --\n";
$ra = E($DEF_A)->handle('add rice', $CAT_A, [], []);
$rb = E($DEF_B)->handle('add rice', $CAT_B, [], []);
ck('Store A Rice -> Rice 5kg', nameOf($ra,'Rice')==='Pakistan Rice 5kg');
ck('Store B Rice -> Rice 1kg', nameOf($rb,'Rice')==='Local Rice 1kg');
ck('different result per tenant', nameOf($ra,'Rice')!==nameOf($rb,'Rice'));
$idA = $ra['cart'][0]['product_id']; $idB = $rb['cart'][0]['product_id'];
ck('A used A-catalogue id (3), B used B-catalogue id (101) — no cross-catalogue leak', $idA===3 && $idB===101);

echo "\n-- BROWSE OVERRIDE (never auto-add) --\n";
foreach (['Show me Rice','Rice options','Which Rice'] as $msg) {
    $r = E($DEF_A)->handle($msg, $CAT_A, [], []);
    ck("\"$msg\" -> never auto-adds (cart empty, options listed)", lines($r)===0 && opts($r)>=2);
}

echo "\n-- STRESS: 1000 x \"Rice\" (same conversation) --\n";
$cart=[]; $state=[]; $crash=false; $t0=hrtime(true);
try { for ($i=0;$i<1000;$i++){ $res = E($DEF_A)->handle('add rice', $CAT_A, $cart, $state); $cart=$res['cart']; $state=$res['state']; } }
catch (\Throwable $e) { $crash=true; }
$elapsed=(hrtime(true)-$t0)/1e6;
$rfinal=['cart'=>$cart,'state'=>$state];
echo "   1000 msgs in ".number_format($elapsed,1)."ms | cart lines: ".count($cart)." | rice qty: ".qtyOf($rfinal,'Rice')."\n";
ck('no crash', !$crash);
ck('no duplicate cart lines (exactly 1 rice line)', count($cart)===1);
ck('quantity merged to 1000 (idempotent add path)', qtyOf($rfinal,'Rice')===1000);
ck('only the default SKU present', nameOf($rfinal,'Rice')==='Pakistan Rice 5kg');

echo "\n-- STRESS: 500 A + 500 B interleaved (tenant leakage) --\n";
$cartA=[];$stA=[];$cartB=[];$stB=[];$crash2=false;
try {
  for ($i=0;$i<500;$i++){
    $a=E($DEF_A)->handle('add rice',$CAT_A,$cartA,$stA); $cartA=$a['cart']; $stA=$a['state'];
    $b=E($DEF_B)->handle('add rice',$CAT_B,$cartB,$stB); $cartB=$b['cart']; $stB=$b['state'];
  }
} catch (\Throwable $e){ $crash2=true; }
$ra2=['cart'=>$cartA]; $rb2=['cart'=>$cartB];
ck('no crash (interleaved)', !$crash2);
ck('Store A cart only Rice 5kg', count($cartA)===1 && nameOf($ra2,'Rice')==='Pakistan Rice 5kg' && qtyOf($ra2,'Rice')===500);
ck('Store B cart only Rice 1kg', count($cartB)===1 && nameOf($rb2,'Rice')==='Local Rice 1kg' && qtyOf($rb2,'Rice')===500);
ck('no tenant leakage (A has no B id, B has no A id)', $cartA[0]['product_id']===3 && $cartB[0]['product_id']===101);

echo "\n============ RESULT ============\n";
printf("PASS %d   FAIL %d\n", $pass, $fail);
if ($fails){ echo "Fails:\n"; foreach($fails as $f) echo "  - $f\n"; exit(1);}
echo "ALL GREEN \u{2705}\n";
