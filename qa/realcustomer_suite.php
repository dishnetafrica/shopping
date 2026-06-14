<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/LocationDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/CartCorrection.php';
require dirname(__DIR__).'/app/Services/Bot/IntentClassifier.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingParser.php';
require dirname(__DIR__).'/app/Services/Bot/ClarificationFlow.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingEngine.php';
use App\Services\Bot\{CatalogueMatcher, ShoppingParser, ClarificationFlow, ShoppingEngine, IntentClassifier as IC, CartCorrection};

function eng($s='explicit'){ return new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX', [], $s); }
function names($c){ return array_map(fn($l)=>$l['name'],$c); }
function qtys($c){ return array_map(fn($l)=>$l['qty'],$c); }

$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails;if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

$mk=function($n,$p,$kw=''){static $i=0;return ['id'=>++$i,'name'=>$n,'price'=>$p,'stock'=>50,'keywords'=>$kw,'category'=>'']; };
$C=[
  $mk('Sikandar Peanuts 500g',8000), $mk('Sikandar Peanuts 1kg',15000), $mk('Sikandar Masala Peanuts',9000),
  $mk('Salted Bread 500g',4000), $mk('Salted Bread 1kg',7000),
  $mk('Rice 1kg',5000), $mk('Rice 5kg',23000),
];
$e=eng('explicit');

echo "\n[BUG 1] selection is NEVER quantity (size in query must not become 200 items)\n";
$a=$e->handle('sikandar peanuts 200g',$C,[],[]);            // 200g not stocked
$opts=$a['state']['options']??[];
ck('"sikandar peanuts 200g" shows options', count($opts)>0, count($opts).' opts');
ck('every option qty is 1 (not 200)', $opts && max(array_map(fn($o)=>$o['qty'],$opts))===1, 'qtys='.implode(',',array_map(fn($o)=>$o['qty'],$opts)));
$b=$e->handle('2',$C,$a['cart'],$a['state']);
ck('reply "2" adds exactly ONE item', count($b['cart'])===1, implode('|',names($b['cart'])));
ck('that item qty is 1, never 200', qtys($b['cart'])===[1], 'qty='.implode(',',qtys($b['cart'])));

echo "\n[BUG 3] size availability — requested size absent -> list available sizes\n";
$s=$e->handle('salted bread 250gm',$C,[],[]);
ck('nothing added', count($s['cart'])===0);
ck('reply explains size availability', stripos($s['reply'],'available')!==false || stripos($s['reply'],"isn't available")!==false, mb_substr($s['reply'],0,60));
ck('lists available 500g', strpos($s['reply'],'500g')!==false);
ck('lists available 1kg', strpos($s['reply'],'1kg')!==false);
ck('options still offered for the bread', !empty($s['state']['options']));

echo "\n[BUG 2] quantity-correction detection (pure)\n";
$corr=['make it 1'=>1,'change to 1'=>1,'only 1 pkt'=>1,'I want only 1 pkt'=>1,'one packet only'=>1,'make it 3'=>3,'set to 2'=>2];
foreach($corr as $t=>$exp){ ck("\"$t\" -> qty $exp", CartCorrection::newQuantity($t)===$exp, var_export(CartCorrection::newQuantity($t),true)); }
$notCorr=['rice','I want 2 milk','2 milk','just water','sikandar peanuts','add sugar'];
foreach($notCorr as $t){ ck("\"$t\" -> not a correction", CartCorrection::newQuantity($t)===null, var_export(CartCorrection::newQuantity($t),true)); }

echo "\n[BUG 4] catalog intent (never product search)\n";
$TS=IC::tokenSetFromProducts($C);
foreach(['send me menu','whole menu','catalog','catalogue','price list','pricelist','menu','what do you sell'] as $t){
  ck("classify \"$t\" = CATALOG", IC::classify($t,$TS)===IC::CATALOG, IC::classify($t,$TS));
}
// real products still shop, not catalog
foreach(['rice','sikandar peanuts','salted bread'] as $t){
  ck("classify \"$t\" = SHOPPING", IC::classify($t,$TS)===IC::SHOPPING, IC::classify($t,$TS));
}

echo "\n[REQUIRED] Sikandar peanuts -> option 2 adds ONE item, never 200\n";
$r1=$e->handle('sikandar peanuts',$C,[],[]);     // no size: plain clarify
$r2=$e->handle('2',$C,$r1['cart'],$r1['state']);
ck('select option 2 -> 1 item', count($r2['cart'])===1 && qtys($r2['cart'])===[1], 'qty='.implode(',',qtys($r2['cart'])));

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
