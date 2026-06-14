<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingParser.php';
require dirname(__DIR__).'/app/Services/Bot/ClarificationFlow.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingEngine.php';
use App\Services\Bot\{CatalogueMatcher, ShoppingParser, ClarificationFlow, ShoppingEngine};

function cat(){ $i=0; $mk=function($n,$p,$kw='') use(&$i){return ['id'=>++$i,'name'=>$n,'price'=>$p,'stock'=>50,'keywords'=>$kw,'category'=>''];};
  return [
    $mk('Fresh Milk 500ml',2500,'dairy'),
    $mk('Fresh Milk 1L',4000,'dairy'),
    $mk('Jallen Yoghurt 2ltr',9000,'milk dairy drink'),   // the bug: "milk" in keywords
    $mk('Beer',5000), $mk('Beer Empty',1000), $mk('Beer With Bottle',6000),
    $mk('Jesa Milk 500ml',2000,'dairy'), $mk('Jesa Milk 1L',3500,'dairy'), $mk('Jesa Milk 2L',6500,'dairy'),
    $mk('Club Pilsner',6000,'beer'), $mk('Club Lager',6200,'beer'),
    $mk('Rice 1kg',5000), $mk('Rice 5kg',23000),
  ];
}
function engine($strategy='explicit'){ return new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX', [], $strategy); }
function names($cart){ return array_map(fn($l)=>$l['name'], $cart); }

$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails; if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

$C=cat();

echo "\n[BUG 1] 'milk' must rank Milk products above a milk-keyworded yoghurt\n";
$m=new CatalogueMatcher();
$top=$m->search('milk',$C);
ck('search("milk") top is a Milk product', stripos($top[0]['product']['name'],'milk')!==false, $top[0]['product']['name']);
$yoIdx=null; foreach($top as $k=>$r){ if(stripos($r['product']['name'],'yoghurt')!==false){$yoIdx=$k;break;} }
ck('Yoghurt does not outrank Milk', $yoIdx===null||$yoIdx>0, 'yoghurt rank='.var_export($yoIdx,true));
// auto-pick strategy must also pick milk, not cheap yoghurt
$ea=engine('explicit_then_auto');
$r=$ea->handle('milk 2ltr',$C,[],[]);
ck('auto-pick "milk 2ltr" never adds Yoghurt', !in_array('Jallen Yoghurt 2ltr', names($r['cart'])), implode('|',names($r['cart'])));

echo "\n[BUG 2] 'Club 3' is ambiguous -> clarify, do NOT auto-add\n";
$e=engine('explicit');
$r=$e->handle('club 3',$C,[],[]);
ck('"club 3" adds nothing', count($r['cart'])===0);
ck('"club 3" sets options (asks)', !empty($r['state']['options']));

echo "\n[BUG 3] Beer -> options -> '1' resolves to item 1\n";
$r1=$e->handle('beer',$C,[],[]);
ck('"beer" shows options', !empty($r1['state']['options']), count($r1['state']['options']??[]).' opts');
$r2=$e->handle('1',$C,$r1['cart'],$r1['state']);
ck('reply "1" adds option 1', $r2['handled'] && count($r2['cart'])===1, implode('|',names($r2['cart'])));
ck('option 1 is the first listed', names($r2['cart'])[0] === $r1['state']['options'][0]['name']);

echo "\n[BUG 4] Jesa milk -> options -> '1 2 3' multi-select\n";
$j1=$e->handle('jesa milk',$C,[],[]);
ck('"jesa milk" shows options', count($j1['state']['options']??[])>=2, count($j1['state']['options']??[]).' opts');
$j2=$e->handle('1 2 3',$C,$j1['cart'],$j1['state']);
ck('"1 2 3" adds the listed jesa options', $j2['handled'] && count($j2['cart'])===min(3,count($j1['state']['options'])), implode('|',names($j2['cart'])));

echo "\n[REQUIRED] query -> numeric reply resolves correct product\n";
function pick($e,$C,$query,$num){ $a=$e->handle($query,$C,[],[]); if(empty($a['state']['options']))return ['__no_options__']; $b=$e->handle((string)$num,$C,$a['cart'],$a['state']); return names($b['cart']); }
$cases=[['beer',1],['jesa milk',3],['rice',2],['club',1],['milk',2]];
foreach($cases as [$q,$n]){
  $a=$e->handle($q,$C,[],[]);
  $opts=$a['state']['options']??[];
  $expected = isset($opts[$n-1]) ? $opts[$n-1]['name'] : null;
  $b=$e->handle((string)$n,$C,$a['cart'],$a['state']);
  $got=names($b['cart']);
  ck("\"$q\" -> $n", $expected!==null && count($got)===1 && $got[0]===$expected, ($expected? "expect $expected, got ".implode('|',$got) : 'no option '.$n));
}

echo "\n[STATE SURVIVES] ambiguous reply during pending selection keeps options\n";
$s1=$e->handle('beer',$C,[],[]);
$s2=$e->handle('zzzz',$C,$s1['cart'],$s1['state']);   // gibberish, not a selection or product
ck('options preserved after unresolved reply', !empty($s2['state']['options']) && $s2['handled']===false, 'handled='.var_export($s2['handled'],true));

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
