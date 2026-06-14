<?php
/**
 * Order-review / confidence-clarification regression suite.
 *
 * Locks in the "good shop attendant" behaviour:
 *   - a wholesale (newline) list is READ BACK and only committed on *OK*
 *   - a MIXED order (confident + ambiguous lines) never partial-commits and never
 *     silently drops a line: the confident lines are stashed and committed together
 *     with the customer's pick
 *   - same-size SKUs disambiguated by name tokens ("exclusive") resolve confidently
 * Pure engine test (no DB), mirrors how BotBrain feeds the engine.
 */
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingParser.php';
require dirname(__DIR__).'/app/Services/Bot/ClarificationFlow.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingEngine.php';
use App\Services\Bot\{CatalogueMatcher, ShoppingParser, ClarificationFlow, ShoppingEngine};

function cat(){ $i=0; $mk=function($n,$p,$kw='') use(&$i){return ['id'=>++$i,'name'=>$n,'price'=>$p,'stock'=>50,'keywords'=>$kw,'category'=>''];};
  return [
    $mk('Redbull Energy Drink 250ml',5000,'energy'),
    $mk('Club Beer 500ml',6000,'beer'), $mk('Nile Beer 500ml',5000,'beer'),
    $mk('Splash Juice 1L',3000,'juice'), $mk('Splash Juice 500ml',2000,'juice'),
    $mk('Kooksy Ice Cream Exclusive 1L',12000,'ice cream'),
    $mk('Kooksy Ice Cream Strawberry 1L',12000,'ice cream'),
    $mk('Kinyara Sugar 1kg',4500,'sakar'), $mk('Superloaf Bread',4000,'bread'),
  ];
}
function engine(){ return new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX'); }
function cm($c){ $m=[]; foreach($c as $l) $m[$l['name']]=$l['qty']; return $m; }

$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails; if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

$C=cat(); $e=engine();

echo "\n[A] Same-size SKUs disambiguated by name tokens resolve confidently\n";
$res=$e->resolveItem(['query'=>'kooksy ice cream exclusive','qty'=>5,'count'=>5,'size'=>'1l','unit'=>null],$C,false,true);
ck('"kooksy ice cream exclusive 1ltr" -> Exclusive 1L (not a clarify)', ($res['status']??'')==='single' && stripos($res['product']['name'],'Exclusive')!==false, ($res['status']??'').' '.($res['product']['name']??''));

echo "\n[B] Wholesale list is read back, not auto-committed\n";
$msg="I want\nRedbull 4\nKooksy ice cream exclusive 1ltr 5\nSugar 2kg";
$r=$e->handle($msg,$C,[],[]);
ck('nothing added before confirmation', count($r['cart'])===0);
ck('pending_order set', !empty($r['state']['pending_order']));
ck('read-back asks for OK', stripos($r['reply'],'OK')!==false);
$ok=$e->handle('ok',$C,$r['cart'],$r['state']); $m=cm($ok['cart']);
ck('OK commits all three lines', count($ok['cart'])===3);
ck('quantities preserved (redbull 4, kooksy 5)', ($m['Redbull Energy Drink 250ml']??0)===4 && ($m['Kooksy Ice Cream Exclusive 1L']??0)===5);
$no=$e->handle('no',$C,$r['cart'],$r['state']);
ck('NO adds nothing and clears proposal', count($no['cart'])===0 && empty($no['state']['pending_order']));
$chg=$e->handle('add milk instead',$C,$r['cart'],$r['state']);
ck('a new request drops the proposal for reprocessing', $chg['handled']===false && empty($chg['state']['pending_order']));

echo "\n[C] MIXED order: never partial-commit, never silently drop a line\n";
$mix="I want\nRedbull 4\nBeer 4\nSplash juice 1ltr 4\nKooksy ice cream exclusive 1ltr 5";
$r=$e->handle($mix,$C,[],[]);
ck('nothing committed yet (no silent partial add)', count($r['cart'])===0);
ck('only the genuinely ambiguous line (beer) is asked', count($r['options'])===2, count($r['options']).' opts');
ck('confident lines stashed (redbull, splash, kooksy)', count($r['state']['pending_resolved']??[])===3);
$pick=$e->handle('1',$C,$r['cart'],$r['state']); $m=cm($pick['cart']);
ck('one pick commits the beer AND all stashed lines together', count($pick['cart'])===4, implode('|',array_keys($m)));
ck('ice cream qty is 5, not 6 (the original bug)', ($m['Kooksy Ice Cream Exclusive 1L']??0)===5);
ck('beer qty preserved (4)', (($m['Club Beer 500ml']??0)+($m['Nile Beer 500ml']??0))===4);
ck('nothing left pending', empty($pick['state']['pending_resolved']) && empty($pick['state']['options']));

echo "\n[D] Inline, fully-confident order still commits instantly (>90% fast path)\n";
$r=$e->handle('2kg sugar and bread',$C,[],[]); $m=cm($r['cart']);
ck('inline confident order commits immediately', ($m['Kinyara Sugar 1kg']??0)===2 && ($m['Superloaf Bread']??0)===1);
ck('no read-back for inline confident order', empty($r['state']['pending_order']));

echo "\n========= RESULT =========\nPASS $pass  FAIL $fail\n";
if($fails){ echo "Failures:\n"; foreach($fails as $f) echo "  - $f\n"; exit(1);} 
