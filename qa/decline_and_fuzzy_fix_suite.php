<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingParser.php';
require dirname(__DIR__).'/app/Services/Bot/ClarificationFlow.php';
require dirname(__DIR__).'/app/Services/Bot/ShoppingEngine.php';
use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ShoppingParser;
use App\Services\Bot\ClarificationFlow;
use App\Services\Bot\ShoppingEngine;

// exact catalogue from the live transcript
$cat = [];
foreach ([
  ['Rice 1KG',6000],['Race Robot',34000],['D.rice Samosa',1000],['Numa Rice 1KG',8800],['Numa Rice 2KG',16500],
  ['Abc Dent 70GMS',1500],['Donut Cake',1500],['Cockroach Dot Cockroach Gel',8500],['Lavin Donuts Big',4500],
  ['Patanjali Dant Kanti Paste 100G',5800],
] as $i=>$r) $cat[] = ['id'=>$i+1,'name'=>$r[0],'price'=>$r[1],'keywords'=>'','category'=>'','stock'=>5];

$m = new CatalogueMatcher();
$pass=0;$fail=0; function ck($l,$ok){global $pass,$fail; if($ok){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}}

echo "\n[matcher precision]\n";
$names = fn($res)=>array_map(fn($x)=>$x['product']['name'],$res);

$r1 = $m->search('i dont want anything', $cat);
ck('"i dont want anything" -> 0 product matches (was: Dent/Donut/Dot/Dant)', count($r1)===0);

$rDont = $m->search('dont', $cat);
ck('"dont" alone -> 0 matches', count($rDont)===0);

$r2 = $m->search('rice rice riocejkknkjgvjg', $cat);
$n2 = $names($r2);
ck('"rice..." does NOT match Race Robot', !in_array('Race Robot',$n2,true));
ck('"rice..." still matches real rice products', (bool)array_intersect(['Rice 1KG','Numa Rice 1KG','Numa Rice 2KG'],$n2));

$rTypo = $m->search('rcie', $cat);     // genuine typo must still fuzzy-resolve
ck('real typo "rcie" still fuzzy-matches a Rice product', (bool)array_filter($names($rTypo),fn($x)=>stripos($x,'rice')!==false));

echo "\n[engine behaviour — declines must not return a product list]\n";
$eng = new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX', [], 'explicit');
$h1 = $eng->handle('i dont want anything', $cat, [], []);
ck('engine: "i dont want anything" not handled as a product browse', $h1['handled']===false);
$h2 = $eng->handle('i dont want anything', $cat, [], []);
ck('engine: no cart mutation from decline', empty($h2['cart']));

echo "\n[decline phrase predicate — mirrors BotBrain]\n";
$decl = function(string $text):bool{
  $lc=mb_strtolower(trim($text));
  $exact=['no','nope','nah','cancel','stop','nothing','none','not interested','no thanks','no thank you','nothing else','no more','thats all',"that's all",'im good',"i'm good",'nahi','kuch nahi'];
  $norm=preg_replace('/[^a-z\s]/','',$lc);
  return in_array($lc,$exact,true)||in_array($norm,$exact,true)||preg_match('/\b(dont|do not|not)\s+want\b/',$norm)||str_contains($norm,'not interested');
};
foreach (['i dont want anything'=>true,"i don't want anything"=>true,'no thanks'=>true,'cancel'=>true,'nothing'=>true,'not interested'=>true,'rice 2kg'=>false,'sugar'=>false] as $t=>$want){
  ck("decline(\"$t\") == ".($want?'true':'false'), $decl($t)===$want);
}

echo "\nRESULT: PASS $pass  FAIL $fail\n";
exit($fail?1:0);
