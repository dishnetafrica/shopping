<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/LocationDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/CategoryDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/GreetingDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/IntentClassifier.php';
use App\Services\Bot\IntentClassifier as IC;
use App\Services\Bot\GreetingDictionary as G;

$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails;if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

// 'habari' IS a catalogue token here (Habari Salt) — greeting must still win for the bare word.
$cat=['habari'=>1,'salt'=>1,'rice'=>1,'milk'=>1,'sugar'=>1];

echo "\n[REQUIRED greetings -> GREETING, no search]\n";
$req=['Habari'=>'sw','Mambo'=>'sw','Poa'=>'sw','Oli otya'=>'lg','Gyebale'=>'lg','Salaam'=>'ar','Marhaba'=>'ar'];
foreach($req as $t=>$lang){
  ck("\"$t\" -> GREETING", IC::classify($t,$cat)===IC::GREETING, IC::classify($t,$cat));
  $d=G::detect($t); ck("\"$t\" lang=$lang", $d && $d['lang']===$lang, $d?$d['lang']:'-');
}

echo "\n[more multilingual openers]\n";
foreach(['hello','hi','good morning','hujambo','shikamoo','karibu','wasuze otya','gyebale ko','assalamu alaikum',
         'sabah al khair','namaste','jai shree krishna','oli otya ssebo','hi there','hello sir'] as $t){
  ck("\"$t\" -> GREETING", IC::classify($t,$cat)===IC::GREETING, IC::classify($t,$cat));
}

echo "\n[small talk / thanks]\n";
ck('"how are you" -> GREETING', IC::classify('how are you',$cat)===IC::GREETING);
ck('"are you there" -> GREETING', IC::classify('are you there',$cat)===IC::GREETING);
ck('"asante" -> THANKS', IC::classify('asante',$cat)===IC::THANKS);
ck('"webale" -> THANKS', IC::classify('webale',$cat)===IC::THANKS);
ck('"shukran" -> THANKS', IC::classify('shukran',$cat)===IC::THANKS);

echo "\n[NOT greetings — real product messages must still shop]\n";
foreach(['Habari Salt','habari salt 1kg','rice','2kg sugar','do you have milk','i want salt'] as $t){
  ck("\"$t\" -> SHOPPING", IC::classify($t,$cat)===IC::SHOPPING, IC::classify($t,$cat));
}
ck('"how much is salt" -> PRICE', IC::classify('how much is salt',$cat)===IC::PRICE, IC::classify('how much is salt',$cat));

echo "\n[Arabic script + regional]\n";
foreach(['مساء الخير','صباح الخير','السلام عليكم','مرحبا','سلام','كيفك','أهلا','Umeze ute?','umeze ute'] as $t){
  ck("\"$t\" detect -> greeting", G::detect($t)!==null, ($d=G::detect($t))?$d['lang'].'/'.$d['kind']:'null');
}
ck('"مساء الخير" lang=ar', ($d=G::detect('مساء الخير')) && $d['lang']==='ar');

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
