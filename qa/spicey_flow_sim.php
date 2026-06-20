<?php
/**
 * qa/spicey_flow_sim.php — offline simulation of multi-message WhatsApp flows,
 * running the REAL production classes in RESTAURANT MODE (auto-add best match, never drop).
 * Verifies order construction; does not touch DB/notifications/placeOrder.
 * Run: php qa/spicey_flow_sim.php
 */
require __DIR__ . '/../app/Services/Pricing.php';
$base = __DIR__ . '/../app/Services/Bot/';
foreach (['ShoppingParser','CatalogueMatcher','ClarificationFlow','ShoppingEngine','CartCorrection','CartEditor','IntentClassifier'] as $c) require $base.$c.'.php';
require __DIR__ . '/../app/Support/OrderInstructions.php';

use App\Services\Bot\{ShoppingParser, CatalogueMatcher, ClarificationFlow, ShoppingEngine, CartEditor, IntentClassifier};
use App\Support\OrderInstructions;

$DEC = App\Services\Pricing::decimalsForCurrency('USD');

// catalogue from menu CSV (active only)
$rows=[]; $id=0; $fh=fopen(__DIR__.'/spicey-herbs-menu.csv','r');
$h=array_flip(array_map(fn($x)=>strtolower(trim($x)), fgetcsv($fh)));
while(($c=fgetcsv($fh))!==false){ if(count(array_filter($c,fn($x)=>trim((string)$x)!==''))===0)continue;
  if(strtoupper(trim($c[$h['active']]??'TRUE'))!=='TRUE')continue;
  $rows[]=['id'=>++$id,'name'=>trim($c[$h['name']]),'description'=>trim($c[$h['description']]??''),
    'category'=>trim($c[$h['category']]??''),'keywords'=>'','product_type'=>'','price'=>(float)($c[$h['price']]??0),'stock'=>1]; }
fclose($fh);

// RESTAURANT MODE engine (7th arg = true)
$engine = new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'USD', [], 'explicit', true);

function step($engine,$rows,&$cart,&$state,&$checkout,$text){
  $lc=mb_strtolower(trim($text));
  if(OrderInstructions::isInstructionOnly($lc)){
    $note=OrderInstructions::note($lc);
    if($cart){$i=count($cart)-1;$p=trim((string)($cart[$i]['note']??''));$cart[$i]['note']=$p!==''?"$p; $note":$note;return "NOTE->{$cart[$i]['name']}";}
    $state['order_note']=trim(($state['order_note']??'').'; '.$note,'; ');return "NOTE(order)";
  }
  if(CartEditor::isEditIntent($lc)){$r=CartEditor::apply($cart,$text);if($r!==null){$cart=array_values($r['cart']);return 'EDIT removed:'.(($r['removed']??[])?implode(',',$r['removed']):'none');}}
  if(IntentClassifier::looksLikeCheckout($lc)){$checkout++;return 'CHECKOUT';}
  [$dish,$note]=OrderInstructions::split($text); $search=$dish!==''?$dish:$text;
  $before=[];foreach($cart as $l)$before[(int)($l['product_id']??0)]=(int)($l['qty']??0);
  $r=$engine->handle($search,$rows,$cart,$state);$cart=$r['cart'];$state=$r['state'];
  if($note!==''&&!empty($r['handled'])){foreach($cart as &$ln){$pid=(int)($ln['product_id']??0);
    if(!array_key_exists($pid,$before)||(int)($ln['qty']??0)>$before[$pid]){$p=trim((string)($ln['note']??''));$ln['note']=$p!==''?"$p; $note":$note;}}unset($ln);}
  return (!empty($r['handled'])?'ADD':'NO-MATCH').($note!==''?" (+note)":'');
}

function cartNames($cart){return array_map(fn($l)=>$l['name'],$cart);}
function hasItem($cart,$sub){foreach($cart as $l)if(stripos($l['name'],$sub)!==false)return true;return false;}

$flows=[
 ['Test 1', ['Chicken Biryani','Add 2 Garlic Naan','Checkout'], ['Chicken Biryani','Naan'], []],
 ['Test 2', ['Butter Chicken','Add Garlic Naan','Checkout'], ['Butter Chicken','Naan'], []],
 ['Test 3', ['2 Butter Chicken','1 Paneer Tikka','1 Veg Burger','Checkout'], ['Butter Chicken','Paneer Tikka','Veg Burger'], []],
 ['Test 4', ['Butter Chicken','Add Coke','Remove Coke','Checkout'], ['Butter Chicken'], ['Coke','Coca']],
 ['Test 5 (5-item)', ['Chicken Biryani','Butter Chicken','2 Garlic Naan','Paneer Tikka','Veg Burger','Checkout'],
    ['Chicken Biryani','Butter Chicken','Naan','Paneer Tikka','Veg Burger'], []],
 ['Test 6 (10-item)', ['Chicken Biryani','Butter Chicken','Garlic Naan','Paneer Tikka','Veg Burger','Mutton Rogan Josh','Fish Curry','Cheese Naan','Gulab Jamun','Chicken Fried Rice','Checkout'],
    ['Chicken Biryani','Butter Chicken','Garlic','Paneer Tikka','Veg Burger','Rogan Josh','Fish Curry','Cheese Naan','Gulab Jamun','Fried Rice'], []],
];

$allPass=true; $reqTotal=0; $reqHit=0;
foreach($flows as [$title,$msgs,$expect,$forbid]){
  echo "\n=== $title ===\n";
  $cart=[];$state=[];$checkout=0;
  foreach($msgs as $m){$r=step($engine,$rows,$cart,$state,$checkout,$m);echo "  > \"$m\"  => $r\n";}
  $names=cartNames($cart);
  echo "  cart: ".($names?implode(' | ',$names):'(empty)')."\n";
  $missing=[];foreach($expect as $e){$reqTotal++;if(hasItem($cart,$e)){$reqHit++;}else{$missing[]=$e;}}
  $leaked=[];foreach($forbid as $f)if(hasItem($cart,$f))$leaked[]=$f;
  $pass=!$missing && !$leaked && $checkout===1;
  $allPass=$allPass&&$pass;
  echo "  checkout: {$checkout}x | ".($pass?'PASS':'FAIL').
       ($missing?(' | MISSING: '.implode(', ',$missing)):'').
       ($leaked?(' | LEAKED: '.implode(', ',$leaked)):'')."\n";
}
echo "\n".str_repeat('-',50)."\n";
printf("ORDER ACCURACY: %d/%d requested items landed (%.1f%%)\n",$reqHit,$reqTotal,100*$reqHit/$reqTotal);
echo ($allPass?"ALL FLOWS PASS — no item silently dropped.":"SOME FLOWS FAILED.")."\n";
exit($allPass?0:1);
