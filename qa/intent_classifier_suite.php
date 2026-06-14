<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/IntentClassifier.php';
use App\Services\Bot\IntentClassifier as I;

// sample catalogue tokens (what a grocery would stock)
$products = array_map(fn($n)=>['name'=>$n,'keywords'=>'','category'=>''], [
  'Rice 1KG','Numa Rice 5KG','Sugar 1KG','Cooking Oil 1L','Milk 500ML','Bread','Soap','Salt','Flour 2KG','Eggs Tray',
]);
$set = I::tokenSetFromProducts($products);

$pass=0;$fail=0;$fails=[];
function ck($l,$got,$want){global $pass,$fail,$fails; if($got===$want){$pass++;echo "  PASS  [$want] $l\n";}else{$fail++;$fails[]="$l (got $got want $want)";echo "  FAIL  $l -> got '$got' want '$want'\n";}}
$c=fn($t)=>I::classify($t,$GLOBALS['set']);

echo "\n[the reported bug]\n";
ck('"hi now we have updated our platform you will reply less than 10 sec you may try"',
   $c('hi now we have updated our platform you will reply less than 10 sec you may try'), I::UNKNOWN);

echo "\n[feedback -> no search]\n";
foreach (['speed is good now','response is fast','great improvement','much better now','very nice','works perfectly now','the bot is much faster'] as $t) ck($t,$c($t),I::FEEDBACK);

echo "\n[greeting -> no search]\n";
foreach (['hi','hello','good morning','how are you','hey there'] as $t) ck($t,$c($t),I::GREETING);

echo "\n[thanks]\n";
foreach (['thanks','thank you','thank you so much','asante sana'] as $t) ck($t,$c($t),I::THANKS);

echo "\n[decline / cancel -> exit shopping]\n";
foreach (['no thanks','i don\'t want anything','nothing','cancel','forget it','never mind'] as $t) ck($t,$c($t),I::DECLINE);

echo "\n[human agent]\n";
foreach (['talk to a person','i want to speak to a human','customer care','call me','can I talk to an agent'] as $t) ck($t,$c($t),I::HUMAN_AGENT);

echo "\n[question -> no search]\n";
foreach (['are you open','do you deliver?','what time do you close','how long for delivery'] as $t) ck($t,$c($t),I::QUESTION);

echo "\n[checkout / cart]\n";
ck('checkout',$c('checkout'),I::CHECKOUT);
ck('cart',$c('cart'),I::CART);

echo "\n[unknown / gibberish -> no feedback/greeting, friendly]\n";
foreach (['asdfghjkl','qwertyuiop'] as $t) {
  $got=$c($t);
  // gibberish may be treated as a short product attempt (SHOPPING -> engine finds nothing -> friendly)
  // or UNKNOWN; either way it must NOT be a conversational mislabel.
  ck("$t not mislabeled conversational", in_array($got,[I::SHOPPING,I::UNKNOWN],true)?'ok':'bad','ok');
}

echo "\n[genuine shopping MUST still search]\n";
foreach (['rice','2kg sugar','i want rice','do you have milk','sugar and oil','bread','add 3 eggs'] as $t) ck($t,$c($t),I::SHOPPING);

echo "\n[short typo still routed to shopping (engine fuzzy can fix)]\n";
ck('sugr (typo)',$c('sugr'),I::SHOPPING);
ck('brade (typo)',$c('brade'),I::SHOPPING);

echo "\n[mixed: greeting + product -> shopping wins]\n";
ck('hello, i need rice',$c('hello, i need rice'),I::SHOPPING);
ck('good morning do you have sugar',$c('good morning do you have sugar'),I::SHOPPING);

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
