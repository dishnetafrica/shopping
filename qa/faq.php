<?php
require dirname(__DIR__).'/app/Services/Bot/FaqDictionary.php';
use App\Services\Bot\FaqDictionary as F;
$pass=0;$fail=0;$fails=[];
$ctx=["currency"=>"UGX","payments"=>["MTN MoMo","Airtel MoMo","Cash on delivery"],"hours"=>"Mon-Sat 8am-9pm","deliver_areas"=>["Ntinda","Kisaasi"]];
function has($a,$needle){ return $a!==null && stripos($a,$needle)!==false; }
function ck($n,$c,&$p,&$f,&$fl){ if($c){$p++;}else{$f++;$fl[]=$n;} }
// questions that MUST get an FAQ answer (topic check)
$want=[
 ["How do I pay?","pay by"], ["do you take cash on delivery","cash"], ["how long for delivery","deliver"],
 ["do you deliver to bugolobi","deliver"], ["what time do you open","hours"], ["how do I order","type what"],
 ["minimum order?","minimum"], ["any discount?","prices"], ["is the milk fresh","fresh"],
 ["I received the wrong item","wrong"], ["is this legit or a scam","official"], ["can I talk to someone","shop know"],
];
foreach($want as [$q,$needle]) ck("\"$q\" answered ($needle)", has(F::match($q,$ctx),$needle), $pass,$fail,$fails);
// things that must NOT be treated as FAQ (so they can be product searches / other intents)
foreach(["fresh milk","2kg rice","rice","add 2 sugar","checkout"] as $q)
  ck("\"$q\" is NOT an FAQ", F::match($q,$ctx)===null, $pass,$fail,$fails);
echo "RESULT: PASS $pass  FAIL $fail\n";
if($fails) echo "Fails:\n - ".implode("\n - ",$fails)."\n";
