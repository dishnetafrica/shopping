<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
use App\Services\Bot\CatalogueMatcher as M;

$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails;if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

/* ---- session decision: mirror of BotBrain::sessionDecision (pure) ---- */
$IDLE=600;$TTL=86400;
function decide($last,$now,$n,$IDLE,$TTL){
  $idle=$last>0?($now-$last):0; $expired=$last>0&&$idle>$IDLE; $tooOld=$last>0&&$idle>$TTL;
  return ['expire_context'=>$expired,'discard_cart'=>$expired&&$tooOld&&$n>0,'ask_recovery'=>$expired&&!$tooOld&&$n>0];
}
$now=1_000_000;
echo "\n[session expiry]\n";
$d=decide($now-60,$now,2,$IDLE,$TTL);   ck('1 min idle -> no expiry', !$d['expire_context'] && !$d['ask_recovery'], json_encode($d));
$d=decide($now-1200,$now,0,$IDLE,$TTL);  ck('20 min idle, empty cart -> expire context, no recovery', $d['expire_context'] && !$d['ask_recovery'] && !$d['discard_cart']);
$d=decide($now-1200,$now,3,$IDLE,$TTL);  ck('20 min idle, cart=3 -> expire + recovery prompt', $d['expire_context'] && $d['ask_recovery'] && !$d['discard_cart']);
$d=decide($now-90000,$now,3,$IDLE,$TTL); ck('>24h idle, cart=3 -> discard cart, no recovery', $d['expire_context'] && $d['discard_cart'] && !$d['ask_recovery']);
$d=decide(0,$now,3,$IDLE,$TTL);          ck('no prior activity -> nothing expires', !$d['expire_context'] && !$d['ask_recovery']);

/* ---- cart choice parsing (mirror) ---- */
function choice($text){
  $t=trim(mb_strtolower(preg_replace('/[^a-z0-9\s]+/i',' ',$text))); $t=trim(preg_replace('/\s+/',' ',$t));
  $c=['1','continue','continue previous cart','keep','previous','old','resume','yes','yeah','yep'];
  $n=['2','new','new cart','start new','start a new cart','fresh','start over','start fresh','clear','no','restart'];
  if(in_array($t,$c,true))return 'continue'; if(in_array($t,$n,true))return 'new'; return null;
}
echo "\n[cart choice]\n";
foreach(['1'=>'continue','continue'=>'continue','keep'=>'continue','2'=>'new','start new'=>'new','fresh'=>'new','milk'=>null,'rice 2kg'=>null] as $t=>$exp){
  ck("\"$t\" -> ".var_export($exp,true), choice($t)===$exp, var_export(choice($t),true));
}

/* ---- CRITICAL: fuzzy must NOT cross-match unrelated products ---- */
echo "\n[fuzzy cross-match guard]\n";
$m=new M();
$hello=[
 ['id'=>1,'name'=>'Hello Sanitary Pads','keywords'=>'pads sanitary','category'=>'Hygiene','price'=>3000,'stock'=>5],
 ['id'=>2,'name'=>'Hello Chocolate','keywords'=>'chocolate sweet','category'=>'Snacks','price'=>2000,'stock'=>5],
 ['id'=>3,'name'=>'Hello Kitty','keywords'=>'toy kids','category'=>'Toys','price'=>5000,'stock'=>5],
 ['id'=>4,'name'=>'Rice 1KG','keywords'=>'rice','category'=>'Grains','price'=>6000,'stock'=>5],
];
foreach(['shell gas','shell regulator','shell burner','shell'] as $q){
  $r=$m->search($q,$hello);
  $names=implode('|',array_map(fn($x)=>$x['product']['name'],$r));
  ck("\"$q\" never matches Hello/Rice", $r===[] , $names?:'(no match)');
}
ck('"hello" still matches Hello products', count($m->search('hello',$hello))>=1);

// other look-alike pairs that must NOT cross-match when the query word isn't in catalogue
$cat2=[['id'=>1,'name'=>'Race Car Toy','keywords'=>'race car','category'=>'Toys','price'=>1000,'stock'=>5],
       ['id'=>2,'name'=>'Soup Maggi','keywords'=>'soup','category'=>'Food','price'=>500,'stock'=>5],
       ['id'=>3,'name'=>'Silk Cloth','keywords'=>'silk','category'=>'Fabric','price'=>9000,'stock'=>5]];
ck('"rice" does not pull "race"', $m->search('rice',$cat2)===[], implode('|',array_map(fn($x)=>$x['product']['name'],$m->search('rice',$cat2))));
ck('"soap" does not pull "soup"', $m->search('soap',$cat2)===[], implode('|',array_map(fn($x)=>$x['product']['name'],$m->search('soap',$cat2))));
ck('"milk" does not pull "silk"', $m->search('milk',$cat2)===[], implode('|',array_map(fn($x)=>$x['product']['name'],$m->search('milk',$cat2))));

// genuine typo still works (fuzzy fallback intact) — fresh matcher (per-request in prod)
$cat3=[['id'=>91,'name'=>'Rice 1KG','keywords'=>'rice','category'=>'Grains','price'=>6000,'stock'=>5]];
ck('"rcie" (typo) still finds Rice', count((new M())->search('rcie',$cat3))>=1, 'damerau fallback');

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
