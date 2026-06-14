<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/LocationDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/CategoryDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/GreetingDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/IntentClassifier.php';
use App\Services\Bot\IntentClassifier as IC;
$pass=0;$fail=0;$fails=[];
// Real Family Shoppers catalogue tokens incl. the "noise" words that caused dumps
$T=["out"=>1,"soft"=>1,"receipt"=>1,"delivery"=>1,"note"=>1,"book"=>1,"hope"=>1,"home"=>1,"fast"=>1,
"good"=>1,"copy"=>1,"colour"=>1,"rice"=>1,"bread"=>1,"water"=>1,"will"=>1,"goods"=>1,"sugar"=>1,
"milk"=>1,"coca"=>1,"cola"=>1,"opener"=>1,"can"=>1,"deliver"=>1];
function ck($name,$cond,&$p,&$f,&$fl){ if($cond){$p++;}else{$f++;$fl[]=$name;} }
$NOT_SHOP = ['shopping'];
// conversational / questions must NOT be product searches (the live bug)
foreach([
 'How do I identify ur delivery guy','How fast will I receive my goods','Hope no scums with you',
 'Your not serious','Ready to deliver','when will my order arrive','who is delivering my order',
 'are you serious','is my order coming','how long will it take',
] as $p){
  $g=IC::classify($p,$T);
  ck("\"$p\" not a product search (got $g)", $g!=='shopping', $pass,$fail,$fails);
}
// real product requests / commands must STILL work
foreach([
 ['Check out','checkout'],['rice','shopping'],['2kg sugar','shopping'],['do you have milk','shopping'],
 ['i want rice','shopping'],['coca cola 500ml','shopping'],['can opener','shopping'],
 ['you don\'t have cous cous','shopping'],['how much is rice','price'],['do you deliver','business'],
 ['menu','catalog'],
] as [$p,$exp]){
  $g=IC::classify($p,$T);
  ck("\"$p\" -> $exp (got $g)", $g===$exp, $pass,$fail,$fails);
}
echo "RESULT: PASS $pass  FAIL $fail\n";
if($fails){ echo "Fails:\n - ".implode("\n - ",$fails)."\n"; }
