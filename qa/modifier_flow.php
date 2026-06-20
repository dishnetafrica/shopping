<?php
require __DIR__.'/../app/Support/ModifierFlow.php';
use App\Support\ModifierFlow;
$pass=0;$fail=0;
function chk($l,$g,$e){global $pass,$fail;$ok=($g===$e);echo ($ok?"PASS":"FAIL")." | $l\n";if(!$ok)echo "   got=".json_encode($g)." exp=".json_encode($e)."\n";$ok?$pass++:$fail++;}

$acc=['id'=>1,'name'=>'accompaniment','required'=>true,'min_select'=>1,'max_select'=>1,'options'=>[
  ['id'=>11,'name'=>'Rice','price_delta'=>0],
  ['id'=>12,'name'=>'Naan','price_delta'=>0],
  ['id'=>13,'name'=>'Chapati','price_delta'=>0],
  ['id'=>14,'name'=>'Butter Naan','price_delta'=>0.50],
]];

chk('next required when none chosen', ModifierFlow::nextRequired([$acc],[])['id'], 1);
chk('satisfied -> null', ModifierFlow::nextRequired([$acc],[1=>[12]]), null);
chk('prompt lists options + surcharge',
  ModifierFlow::prompt($acc,'Butter Chicken'),
  "For your *Butter Chicken* — choose your accompaniment:\n1. Rice\n2. Naan\n3. Chapati\n4. Butter Naan (+0.5)");
chk('resolve by number',  ModifierFlow::resolve('2',$acc)['name'], 'Naan');
chk('resolve by name',    ModifierFlow::resolve('Naan',$acc)['name'], 'Naan');
chk('resolve fuzzy word', ModifierFlow::resolve('garlic naan please',$acc)['name'], 'Naan');
chk('resolve rice contains', ModifierFlow::resolve('rice',$acc)['name'], 'Rice');
chk('resolve unknown -> null', ModifierFlow::resolve('pizza',$acc), null);

echo "\n".($fail===0?"ALL GREEN":"FAILED").": {$pass} passed, {$fail} failed.\n";
exit($fail?1:0);
