<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/LocationDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/CategoryDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/GreetingDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/IntentClassifier.php';
use App\Services\Bot\IntentClassifier as IC;
$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails;if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

$cat=['rice'=>1,'sugar'=>1,'cous'=>1,'shan'=>1,'paste'=>1];

echo "\n[override intents are classifiable while a list is pending]\n";
ck('"Do you do deliveries" -> BUSINESS', IC::classify('Do you do deliveries',$cat)===IC::BUSINESS, IC::classify('Do you do deliveries',$cat));
ck('"do you deliver to ntinda" -> BUSINESS', IC::classify('do you deliver to ntinda',$cat)===IC::BUSINESS);
ck('"how much is rice" -> PRICE', IC::classify('how much is rice',$cat)===IC::PRICE);
ck('"are you open" -> BUSINESS', IC::classify('are you open',$cat)===IC::BUSINESS);
ck('"thanks" -> THANKS', IC::classify('thanks',$cat)===IC::THANKS);
ck('"no" -> DECLINE', IC::classify('no',$cat)===IC::DECLINE);
ck('"checkout" -> CHECKOUT', IC::classify('checkout',$cat)===IC::CHECKOUT);
ck('"hello" -> GREETING', IC::classify('hello',$cat)===IC::GREETING);
ck('"you don\'t have cous cous" -> SHOPPING (fresh search)', IC::classify("you don't have cous cous",$cat)===IC::SHOPPING, IC::classify("you don't have cous cous",$cat));

echo "\n[asksIfListComplete detector (mirror of BotBrain)]\n";
function listComplete($lc){
  $t=trim(preg_replace('/[^a-z\s]/',' ',mb_strtolower($lc))); $t=trim(preg_replace('/\s+/',' ',$t));
  $ph=['only those','only these','only those ones','only these ones','only that','only those ones you have','only what you have','is that all','is this all','that all','thats all you have','is that all you have','are those all','are these all','those are the only ones','is that the only ones','only those you have','just those'];
  if(in_array($t,$ph,true))return true;
  return (bool)preg_match('/^(is|are)\b.*\b(all|only)\b.*\b(you have|in stock|available)\b/',$t) || (bool)preg_match('/^only\b.*\byou have\b/',$t);
}
foreach(['Only those ones you have','only these','is that all','is that all you have','only those you have','just those'] as $t) ck("\"$t\" -> complete?", listComplete($t)===true, var_export(listComplete($t),true));
foreach(['1','rice','do you have milk','more brands'] as $t) ck("\"$t\" -> NOT complete-question", listComplete($t)===false);

echo "\n[delivery-to-area + location help]\n";
foreach(['How much delivery to Ntinda','How much to Kisaasi','How much to Bugolobi','How much to Mukono','delivery fee to Mukono'] as $t)
  ck("\"$t\" -> BUSINESS(delivery)", IC::classify($t,$cat)===IC::BUSINESS && IC::deliveryArea(strtolower($t))!==null, IC::classify($t,$cat));
foreach(['Can I send a location pin','share location','send my location'] as $t)
  ck("\"$t\" -> location help", IC::isLocationHelp(strtolower($t))===true);
ck('"ntinda" is not location-help', IC::isLocationHelp('ntinda')===false);
ck('"how much is rice" stays PRICE', IC::classify('how much is rice',$cat)===IC::PRICE);

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
