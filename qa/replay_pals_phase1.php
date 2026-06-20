<?php
// Live replay of the Phase-1 Intent Router against the REAL Pal's chat history.
// Drives the actual shipped code (OrderIntentRouter + ProductAlias). No framework, no LLM.
require __DIR__ . '/../app/Services/Bot/ProductAlias.php';
require __DIR__ . '/../app/Services/Bot/OrderIntentRouter.php';
use App\Services\Bot\OrderIntentRouter as R;
use App\Services\Bot\ProductAlias;

function show($t){ $r=R::classify($t); return sprintf("%-14s product=%-14s qty=%s", $r['intent'], $r['product']??'-', $r['qty']??'-'); }

echo "============ PART 1: SIX NAMED SCENARIOS ============\n";
$scen = [
  ['Kaju Katri 500gm',                          'quantity binding -> qty 500 bound to Kaju Katri'],
  ['Paneer Bring khakhra also',                 'addition -> Paneer + Khakhra'],
  ['Paneer Nathi lavanu',                       'removal -> Paneer removed'],
  ['Kem cho Need 1kg paneer and 2kg mavo',      'gujlish mixed -> products+qty, NOT greeting'],
  ['I made my list Please confirm',             'confirmation -> cart summary / checkout'],
  ['Will you be coming tomorrow?',              'delivery intent, NOT product search'],
];
foreach ($scen as [$msg,$exp]) {
    printf("  %-42s => %s\n      expect: %s\n", '"'.$msg.'"', show($msg), $exp);
}

echo "\n============ PART 2: REPLAY 100 REAL CUSTOMER MESSAGES ============\n";
$fh = fopen('/mnt/user-data/uploads/pals_chats.csv','r');
$hdr = fgetcsv($fh); $ci = array_flip($hdr);
$cust = [];
while (($row = fgetcsv($fh)) !== false) {
    if (($row[$ci['sender']] ?? '') === 'customer') {
        $b = trim((string)($row[$ci['body']] ?? ''));
        if ($b !== '') $cust[] = $b;
    }
}
fclose($fh);
$total = count($cust);
// Deterministic, representative sample: even stride across the whole history.
$stride = (int) floor($total/100);
$sample = [];
for ($i=0; $i<$total && count($sample)<100; $i+=$stride) $sample[] = $cust[$i];
$sample = array_slice($sample,0,100);

// Independent reference labeller (separate lexicon) to grade greeting/qty/non-product confusion.
function refIsGreetingSocial($s){ return (bool)preg_match('/\b(jai swaminarayan|jsk|kem ch?o|kaise ho|namaste|ram ram|hello|^hi+|^hey|^hlo|heloo|bhabhi|bhaiya|thank|sorry|congratulat|welcome)\b/iu',$s); }
function refIsBareQty($s){ return (bool)preg_match('/^\s*\d+\s*(kgs?|gm|gram|grams|pcs?|pieces?|pkts?|packets?|dish|plate|nos?)?\s*$/iu',$s) || (bool)preg_match('/^\s*(one|two|three|five|ten)\s*$/iu',$s); }

$dist=[]; $psearch=[]; $forwarded=0; $errors=[];
$confuse_greet=0; $confuse_qty=0;
foreach ($sample as $m) {
    $r = R::classify($m); $in = $r['intent'];
    $dist[$in] = ($dist[$in]??0)+1;
    if (in_array($in,[R::HUMAN,R::DELIVERY,R::UNKNOWN],true)) $forwarded++;
    if ($in === R::PRODUCT_SEARCH) {
        $psearch[] = [$m, $r['product']];
        // success-criteria checks: a greeting/social or bare-qty must NOT land in product_search
        if (refIsGreetingSocial($m)) { $confuse_greet++; $errors[]="GREETING->product_search: $m"; }
        if (refIsBareQty($m))        { $confuse_qty++;   $errors[]="QTY->product_search: $m"; }
    }
}

echo "Sampled {$total} customer msgs -> replayed " . count($sample) . " (even stride {$stride}).\n\n";
echo "Intent distribution:\n";
arsort($dist);
foreach ($dist as $k=>$v) printf("  %-15s %3d  %4.0f%%\n",$k,$v,100*$v/count($sample));

$ps = count($psearch);
echo "\n--- Success-criteria measurements ---\n";
printf("  Reached PRODUCT_SEARCH (only fallback-eligible path): %d/%d = %.0f%%\n",$ps,count($sample),100*$ps/count($sample));
$resolved=0; foreach($psearch as [$m,$p]) if($p && ProductAlias::canonical($p)) $resolved++;
printf("  Greeting/Social -> product_search confusion: %d  (target 0)\n",$confuse_greet);
printf("  Bare-quantity   -> product_search confusion: %d  (target 0)\n",$confuse_qty);
printf("  Human/forward (delivery+human+unknown) takeover rate: %d/%d = %.0f%%\n",$forwarded,count($sample),100*$forwarded/count($sample));

echo "\nEvery message that reached PRODUCT_SEARCH (manual-verify these are real product attempts):\n";
foreach ($psearch as [$m,$p]) printf("   • %-48s -> %s\n", mb_substr($m,0,46), $p ?? '?');

if ($errors){ echo "\nCONFUSION ERRORS:\n"; foreach($errors as $e) echo "   ! $e\n"; }
else echo "\nNo greeting/product and no quantity/product confusion detected.\n";
