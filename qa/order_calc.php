<?php
/** qa/order_calc.php — mirrors OrderCalculator math/render + the total-intent gate. */

// total-intent detection (AiBrain::maybeOrderTotal)
function wantsTotal(string $text): bool {
    $t = mb_strtolower($text);
    foreach (['total','altogether','grand total','how much for','how much is','sum up','add up','invoice','final price'] as $w)
        if (str_contains($t,$w)) return true;
    return false;
}

// pricing math (OrderCalculator::quote) — items pre-matched to {price,name,qty,moq}
function quote(array $items): array {
    $total=0.0; $lines=[];
    foreach ($items as $it) {
        $qty=max(1,(int)$it['qty']);
        if (!isset($it['price'])) { $lines[]=['name'=>$it['name'],'qty'=>$qty,'matched'=>false]; continue; }
        $sum=(float)$it['price']*$qty; $total+=$sum;
        $lines[]=['name'=>$it['name'],'qty'=>$qty,'price'=>(float)$it['price'],'sum'=>$sum,'moq'=>$it['moq']??null,'matched'=>true];
    }
    return ['lines'=>$lines,'total'=>$total,'currency'=>'UGX'];
}
function render(array $q): string {
    if (!array_filter($q['lines'],fn($l)=>$l['matched'])) return '';
    $cur=$q['currency']; $out=["🧮 Order total (from our price list — please confirm):"];
    foreach ($q['lines'] as $l) {
        if (!$l['matched']) { $out[]="• {$l['qty']} × {$l['name']} — not on the list, team will confirm"; continue; }
        $note=($l['moq']&&$l['qty']<$l['moq'])?" (min order {$l['moq']})":"";
        $out[]="• {$l['qty']} × {$l['name']} = {$cur} ".number_format($l['sum']).$note;
    }
    $out[]="————\n*Total: {$cur} ".number_format($q['total'])."*";
    return implode("\n",$out);
}

$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== order_calc QA ===\n";

check('"what is my total" triggers',  wantsTotal('what is my total?')===true);
check('"how much for 5 cartons"',     wantsTotal('how much for 5 cartons')===true);
check('plain greeting no trigger',    wantsTotal('hello there')===false);
check('"do you have napkins" no trig',wantsTotal('do you have napkins')===false);

$q = quote([
  ['name'=>'EuroPearl 300-sheet','qty'=>5,'price'=>45000,'moq'=>3],
  ['name'=>'Angel Soft napkins','qty'=>2,'price'=>30000,'moq'=>3],
]);
check('line sum 5×45000=225000',  $q['lines'][0]['sum']===225000.0);
check('total 225000+60000=285000', $q['total']===285000.0);
$r=render($q);
check('render shows total',        str_contains($r,'Total: UGX 285,000'));
check('render flags below-MOQ',    str_contains($r,'(min order 3)'));   // 2 < 3

$q2 = quote([['name'=>'Unknown item','qty'=>4]]);  // no price → unmatched
check('unmatched → total 0',       $q2['total']===0.0);
check('unmatched render is empty', render($q2)==='');

$q3 = quote([['name'=>'A','qty'=>0,'price'=>1000,'moq'=>null]]); // qty floor 1
check('qty floored to 1',          $q3['lines'][0]['qty']===1 && $q3['total']===1000.0);

echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
