<?php
/* Stage-2 proof: curry → bot asks accompaniment → pick → priced. Real ShoppingEngine + ModifierFlow. */
require __DIR__.'/../app/Services/Pricing.php';
$b=__DIR__.'/../app/Services/Bot/';
foreach (['ShoppingParser','CatalogueMatcher','ClarificationFlow','ShoppingEngine'] as $c) require $b.$c.'.php';
require __DIR__.'/../app/Support/OrderInstructions.php';
require __DIR__.'/../app/Support/ModifierFlow.php';
require __DIR__.'/../app/Support/ModifierCalc.php';
use App\Services\Bot\{ShoppingParser,CatalogueMatcher,ClarificationFlow,ShoppingEngine};
use App\Support\{OrderInstructions,ModifierFlow,ModifierCalc};

$rows=[
 ['id'=>1,'name'=>'Butter Chicken','category'=>'Main Course','keywords'=>'','product_type'=>'','price'=>9.50,'stock'=>null],
 ['id'=>2,'name'=>'Paneer Makhani','category'=>'Main Course','keywords'=>'','product_type'=>'','price'=>7.50,'stock'=>null],
 ['id'=>3,'name'=>'Garlic Naan','category'=>'Breads','keywords'=>'','product_type'=>'','price'=>2.50,'stock'=>null],
 ['id'=>4,'name'=>'Jeera Rice','category'=>'Rice & Noodles','keywords'=>'','product_type'=>'','price'=>4.00,'stock'=>null],
];
// the required accompaniment group, attached to Main Course dishes
$ACC=['id'=>1,'name'=>'accompaniment','required'=>true,'min_select'=>1,'max_select'=>1,'options'=>[
  ['id'=>11,'name'=>'Rice','price_delta'=>0],['id'=>12,'name'=>'Naan','price_delta'=>0],['id'=>13,'name'=>'Chapati','price_delta'=>0]]];
function groupsFor($name,$rows,$ACC){foreach($rows as $r)if($r['name']===$name)return $r['category']==='Main Course'?[$ACC]:[];return [];}

$engine=new ShoppingEngine(new ShoppingParser(),new CatalogueMatcher(),new ClarificationFlow(),'USD',[],'explicit',true);

function bot($engine,$rows,$ACC,&$cart,&$state,&$pending,$text){
  // 1) resolve a pending accompaniment choice first
  if($pending){
    $opt=ModifierFlow::resolve($text,$pending['group']);
    if($opt){ $cart[$pending['i']]['modifiers']=[['group'=>$pending['group']['name'],'name'=>$opt['name'],'price_delta'=>(float)$opt['price_delta']]];
      $base=$cart[$pending['i']]['price']; $cart[$pending['i']]['price']=ModifierCalc::unitPrice($base,$cart[$pending['i']]['modifiers']);
      $nm=$cart[$pending['i']]['name']; $on=$opt['name']; $pending=null; return "ADDED: {$nm} + {$on}"; }
    return "REASK: ".ModifierFlow::prompt($pending['group']);
  }
  // 2) pre-strip "with <accompaniment>" so it's the choice, not a separate Rice/Naan line
  $accNames=array_map(fn($o)=>preg_quote(mb_strtolower($o['name']),'/'),$ACC['options']);
  $hint='';
  if(preg_match('/\\bwith\\s+('.implode('|',$accNames).')\\b/i',$text,$mm)){
    $hint=$mm[1]; $text=trim(preg_replace('/\\bwith\\s+'.preg_quote($mm[1],'/').'\\b/i','',$text));
  }
  [$dish,$note]=OrderInstructions::split($text); $search=$dish!==''?$dish:$text;
  $before=count($cart);
  $r=$engine->handle($search,$rows,$cart,$state); $cart=$r['cart']; $state=$r['state'];
  // 3) if a curry line was added and needs an accompaniment, resolve inline hint/note or ask
  for($i=$before;$i<count($cart);$i++){
    $grp=groupsFor($cart[$i]['name'],$rows,$ACC); $need=ModifierFlow::nextRequired($grp,[]);
    if($need){
      $pick = $hint!=='' ? ModifierFlow::resolve($hint,$need) : ($note!==''?ModifierFlow::resolve($note,$need):null);
      if($pick){
        $cart[$i]['modifiers']=[['group'=>$need['name'],'name'=>$pick['name'],'price_delta'=>(float)$pick['price_delta']]];
        $cart[$i]['price']=ModifierCalc::unitPrice($cart[$i]['price'],$cart[$i]['modifiers']);
        return "ADDED: {$cart[$i]['name']} + {$pick['name']} (from your message)"; }
      $pending=['i'=>$i,'group'=>$need]; return "ASK: ".ModifierFlow::prompt($need,$cart[$i]['name']);
    }
  }
  return (!empty($r['handled'])?'ADDED line(s)':'no-match');
}

function lineStr($l){$m=isset($l['modifiers'])?(' + '.implode(',',array_map(fn($x)=>$x['name'],$l['modifiers']))):'';return $l['qty'].'x '.$l['name'].$m.' @ '.number_format($l['price'],2);}
$pass=0;$fail=0; function ok($l,$c){global $pass,$fail;echo ($c?"PASS":"FAIL")." | $l\n";$c?$pass++:$fail++;}

echo "=== Flow A: ask then pick ===\n";
$cart=[];$state=[];$pending=null;
foreach(['Butter Chicken','Naan'] as $m){echo "  > \"$m\"  ".bot($engine,$rows,$ACC,$cart,$state,$pending,$m)."\n";}
echo "  cart: ".implode(' | ',array_map('lineStr',$cart))."\n";
ok('A: butter chicken has Naan accompaniment @9.50', count($cart)===1 && ($cart[0]['modifiers'][0]['name']??'')==='Naan' && abs($cart[0]['price']-9.50)<0.001);

echo "=== Flow B: inline 'with rice' (no question) ===\n";
$cart=[];$state=[];$pending=null;
echo "  > \"Butter Chicken with rice\"  ".bot($engine,$rows,$ACC,$cart,$state,$pending,'Butter Chicken with rice')."\n";
echo "  cart: ".implode(' | ',array_map('lineStr',$cart))."\n";
ok('B: inline resolved to Rice, no pending', $pending===null && ($cart[0]['modifiers'][0]['name']??'')==='Rice');

echo "=== Flow C: numeric pick + extra bread line ===\n";
$cart=[];$state=[];$pending=null;
foreach(['Paneer Makhani','2','2 Garlic Naan'] as $m){echo "  > \"$m\"  ".bot($engine,$rows,$ACC,$cart,$state,$pending,$m)."\n";}
echo "  cart: ".implode(' | ',array_map('lineStr',$cart))."\n";
ok('C: paneer+Naan(by #2) and separate 2x Garlic Naan',
   count($cart)===2 && ($cart[0]['modifiers'][0]['name']??'')==='Naan' && $cart[1]['name']==='Garlic Naan' && (int)$cart[1]['qty']===2);

echo "=== Flow D: bread alone never asks ===\n";
$cart=[];$state=[];$pending=null;
echo "  > \"Garlic Naan\"  ".bot($engine,$rows,$ACC,$cart,$state,$pending,'Garlic Naan')."\n";
ok('D: bread added with no accompaniment question', $pending===null && count($cart)===1 && !isset($cart[0]['modifiers']));

echo "\n".($fail===0?"ALL GREEN":"FAILED").": {$pass} passed, {$fail} failed.\n";
exit($fail?1:0);
