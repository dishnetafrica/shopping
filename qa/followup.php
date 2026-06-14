<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/LocationDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/CategoryDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/FollowUp.php';
require dirname(__DIR__).'/app/Services/Bot/GreetingDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/IntentClassifier.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingParser.php';
require dirname(__DIR__).'/app/Services/Bot/ClarificationFlow.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingEngine.php';
use App\Services\Bot\{CatalogueMatcher, ShoppingParser, ClarificationFlow, ShoppingEngine, IntentClassifier as IC, FollowUp};

$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails;if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

$mk=function($n,$p,$kw=''){static $i=0;return ['id'=>++$i,'name'=>$n,'price'=>$p,'stock'=>50,'keywords'=>$kw,'category'=>'']; };
$C=[
  $mk('Huggies Wipes 80pcs',9000), $mk('Pampers Wipes 64pcs',8000), $mk('Molfix Baby Wipes 60pcs',6000),
  $mk('Fresh Milk 500ml',2500), $mk('Fresh Milk 1L',4000), $mk('Jesa Milk 1L',3800),
  $mk('Kakira Sugar 500g',2500), $mk('Kakira Sugar 1kg',4500), $mk('Kakira Sugar 2kg',8500),
  $mk('Brand X Soap 200g',3000),   // decoy: literal "brand" search would surface this
  $mk('Can Opener',5000), $mk('Wine Opener',7000),   // real products (the original false-positive)
];
$e=new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX', [], 'explicit');
$TS=IC::tokenSetFromProducts($C);

echo "\n[ISSUE 1] business inquiry intent (never product search)\n";
foreach(['are you open','are you open for orders','what time do you close','are you delivering today','do you deliver?','are you working today'] as $t){
  ck("classify \"$t\" = BUSINESS", IC::classify($t,$TS)===IC::BUSINESS, IC::classify($t,$TS));
}
ck('"can opener" is SHOPPING (not business)', IC::classify('can opener',$TS)===IC::SHOPPING, IC::classify('can opener',$TS));
ck('"wine opener" is SHOPPING (not business)', IC::classify('wine opener',$TS)===IC::SHOPPING, IC::classify('wine opener',$TS));

echo "\n[ISSUE 2/3] follow-up phrase detection\n";
$fu=['more brands'=>'more','show more'=>'more','other options'=>'more','any other options'=>'more','what else'=>'more',
     'more brands if u have'=>'more','different size'=>'more','larger size'=>'larger','bigger'=>'larger',
     'cheaper one'=>'cheaper','cheapest'=>'cheaper','premium one'=>'premium','smaller size'=>'smaller',
     'more itmes you have'=>'more','more items'=>'more','more items you have'=>'more','what else do you have'=>'more',
     'more options you have'=>'more','any other brands'=>'more'];
foreach($fu as $t=>$exp){ ck("\"$t\" -> $exp", FollowUp::parse($t)===$exp, var_export(FollowUp::parse($t),true)); }
foreach(['more rice','rice','2','milk','add sugar','do you have more bread','more 2kg rice'] as $t){ ck("\"$t\" -> not a follow-up", FollowUp::parse($t)===null, var_export(FollowUp::parse($t),true)); }
foreach(['you dont have big size'=>'larger','you don t have big size'=>'larger','big size'=>'larger','big'=>'larger',
         'large size'=>'larger','small size'=>'smaller','do you have big size'=>'larger'] as $t=>$exp){
  ck("\"$t\" -> $exp", FollowUp::parse($t)===$exp, var_export(FollowUp::parse($t),true)); }

echo "\n[CONTEXT] engine records last_query when it shows options\n";
$a=$e->handle('do you have wipes',$C,[],[]);
ck('"do you have wipes" shows options', !empty($a['state']['options']));
ck('last_query = "wipes"', ($a['state']['last_query']??null)==='wipes', $a['state']['last_query']??'null');
ck('last_kind = "search"', ($a['state']['last_kind']??null)==='search');

echo "\n[FOLLOW-UP RESULT] 'more brands' re-lists wipes, never the word 'brand'\n";
// mirrors BotBrain::tryFollowUp core (search last_query, apply modifier)
$m=new CatalogueMatcher();
$q=$a['state']['last_query'];
$res=array_map(fn($c)=>$c['product']['name'], $m->search($q,$C));
ck('follow-up returns only wipes', $res && count(array_filter($res,fn($n)=>stripos($n,'wipes')!==false))===count($res), implode(' | ',$res));
ck('decoy "Brand X Soap" NOT returned', !in_array('Brand X Soap 200g',$res));

echo "\n[REQUIRED] milk -> other options -> more milk\n";
$milk=array_map(fn($c)=>$c['product']['name'], $m->search('milk',$C));
ck('"other options" after milk -> milk products', $milk && count(array_filter($milk,fn($n)=>stripos($n,'milk')!==false))===count($milk), implode(' | ',$milk));

echo "\n[REQUIRED] kakira sugar -> larger size -> larger variants first\n";
function magsort($prods,$dir){ usort($prods,function($a,$b)use($dir){ $sa=CatalogueMatcher::sizeMagnitude($a)??-1; $sb=CatalogueMatcher::sizeMagnitude($b)??-1; return $dir==='desc'?($sb<=>$sa):($sa<=>$sb); }); return $prods; }
$sugar=array_map(fn($c)=>$c['product']['name'], $m->search('kakira sugar',$C));
$larger=magsort($sugar,'desc');
ck('larger size -> 2kg first', ($larger[0]??'')==='Kakira Sugar 2kg', implode(' | ',$larger));
$smaller=magsort($sugar,'asc');
ck('smaller size -> 500g first', ($smaller[0]??'')==='Kakira Sugar 500g', implode(' | ',$smaller));

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
