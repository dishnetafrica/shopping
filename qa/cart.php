<?php
require dirname(__DIR__).'/app/Services/Bot/CartCorrection.php';
require dirname(__DIR__).'/app/Services/Bot/CartEditor.php';
use App\Services\Bot\CartEditor as CE;

$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails;if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

function cart(){ $i=0; $mk=function($n,$q)use(&$i){return ['id'=>++$i,'name'=>$n,'price'=>1000,'qty'=>$q];};
  return [$mk('3D Beads',1),$mk('Stickers Room Decor 3D',1),$mk('Wall Chart 3D Picture',1),
          $mk('Redbull',4),$mk('Beer',4),$mk('Splash Juice',4),$mk('Kooksy Ice Cream',5)]; }
function names($c){ return array_map(fn($l)=>$l['name'],$c); }
function qtyOf($c,$name){ foreach($c as $l) if($l['name']===$name) return $l['qty']; return null; }

echo "\n[INTENT] edit vs non-edit\n";
foreach(['remove item 1','remove item 1,2,3','delete item 4','remove Redbull','clear cart','remove everything',
         'change item 2 to 5','make Redbull 10','reduce Beer to 2','make it 3','only 2'] as $t){
  ck("isEditIntent(\"$t\")", CE::isEditIntent($t)===true, var_export(CE::isEditIntent($t),true));
}
foreach(['redbull','add beer','2','more brands','make me chapati','do you have milk','1,2,3'] as $t){
  ck("NOT edit \"$t\"", CE::isEditIntent($t)===false, var_export(CE::isEditIntent($t),true));
}

echo "\n[REMOVE by number]\n";
$r=CE::apply(cart(),'remove item 1');
ck('remove item 1 -> drops 3D Beads', $r && !in_array('3D Beads',names($r['cart'])) && count($r['cart'])===6, implode('|',$r['removed']??[]));
$r=CE::apply(cart(),'Remove item 1,2,3');
ck('remove item 1,2,3 -> drops first three', $r && count($r['cart'])===4 && names($r['cart'])===['Redbull','Beer','Splash Juice','Kooksy Ice Cream'], implode(' | ',names($r['cart']??[])));
ck('removed list = the three', $r && $r['removed']===['3D Beads','Stickers Room Decor 3D','Wall Chart 3D Picture'], implode(',',$r['removed']??[]));
$r=CE::apply(cart(),'delete item 4');
ck('delete item 4 -> drops Redbull', $r && !in_array('Redbull',names($r['cart'])) && count($r['cart'])===6);
$r=CE::apply(cart(),'remove 1,3');
ck('remove 1,3 -> drops lines 1 and 3', $r && names($r['cart'])===['Stickers Room Decor 3D','Redbull','Beer','Splash Juice','Kooksy Ice Cream']);

echo "\n[REMOVE by name]\n";
$r=CE::apply(cart(),'Remove Redbull');
ck('remove Redbull', $r && !in_array('Redbull',names($r['cart'])) && count($r['cart'])===6);
$r=CE::apply(cart(),'remove beer');
ck('remove beer (case-insensitive)', $r && !in_array('Beer',names($r['cart'])));
$r=CE::apply(cart(),'Delete Splash Juice');
ck('delete Splash Juice', $r && !in_array('Splash Juice',names($r['cart'])));

echo "\n[CLEAR]\n";
foreach(['clear cart','remove everything','empty','delete all'] as $t){ $r=CE::apply(cart(),$t); ck("\"$t\" clears", $r && $r['cleared']===true && $r['cart']===[]); }

echo "\n[QUANTITY]\n";
$r=CE::apply(cart(),'change item 2 to 5');
ck('change item 2 to 5', $r && qtyOf($r['cart'],'Stickers Room Decor 3D')===5);
$r=CE::apply(cart(),'make Redbull 10');
ck('make Redbull 10', $r && qtyOf($r['cart'],'Redbull')===10);
$r=CE::apply(cart(),'reduce Beer to 2');
ck('reduce Beer to 2', $r && qtyOf($r['cart'],'Beer')===2);
$r=CE::apply(cart(),'increase Splash Juice to 6');
ck('increase Splash Juice to 6', $r && qtyOf($r['cart'],'Splash Juice')===6);
$r=CE::apply(cart(),'make it 3');
ck('make it 3 -> last item (Kooksy) = 3', $r && qtyOf($r['cart'],'Kooksy Ice Cream')===3);
$r=CE::apply(cart(),'change 5 to 9');
ck('change 5 to 9 -> line 5 (Beer) = 9', $r && qtyOf($r['cart'],'Beer')===9);

echo "\n[REQUIRED] add 4 -> remove item 1 / 1,2,3 / Redbull / clear / change Beer to 2\n";
$c=[['id'=>1,'name'=>'Redbull','price'=>1000,'qty'=>4],['id'=>2,'name'=>'Beer','price'=>1000,'qty'=>4],
    ['id'=>3,'name'=>'Splash Juice','price'=>1000,'qty'=>4],['id'=>4,'name'=>'Kooksy Ice Cream','price'=>1000,'qty'=>5]];
ck('remove item 1', (CE::apply($c,'remove item 1')['cart'][0]['name']??'')==='Beer');
ck('remove item 1,2,3', count(CE::apply($c,'remove item 1,2,3')['cart'])===1);
ck('remove Redbull', !in_array('Redbull',names(CE::apply($c,'remove Redbull')['cart'])));
ck('clear cart', CE::apply($c,'clear cart')['cart']===[]);
ck('change Beer to 2', qtyOf(CE::apply($c,'change Beer to 2')['cart'],'Beer')===2);

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
