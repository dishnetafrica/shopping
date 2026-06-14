<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/LocationDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/CategoryDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/GreetingDictionary.php';
require dirname(__DIR__).'/app/Services/Bot/IntentClassifier.php';
require dirname(__DIR__).'/app/Services/Delivery/ZoneResolver.php';
use App\Services\Bot\{LocationDictionary as L, IntentClassifier as IC, CatalogueMatcher};
use App\Services\Delivery\ZoneResolver;

$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails; if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

// catalogue token set so real products classify as SHOPPING
$products=[['name'=>'Rice 1kg','keywords'=>''],['name'=>'Sugar 2kg','keywords'=>''],['name'=>'Fresh Milk 1L','keywords'=>'dairy'],['name'=>'Cooking Oil 1L','keywords'=>'']];
$TS=IC::tokenSetFromProducts($products);

echo "\n[DETECT] canonical + misspellings, Kampala + Juba\n";
$d=[
  ['Am in Kisaasi','Kisaasi','Kampala'],
  ['kisasi','Kisaasi','Kampala'],
  ['upper mawanda','Upper Mawanda','Kampala'],
  ['Near Total Ntinda','Ntinda','Kampala'],
  ['konyo konyo','Konyo Konyo','Juba'],
  ['Deliver to Jebel','Jebel','Juba'],
  ['tongping','Thongpiny','Juba'],
  ['bweyogereree','Bweyogerere','Kampala'],
  ['Munyonyo','Munyonyo','Kampala'],
];
foreach($d as [$txt,$area,$city]){ $r=L::detect($txt); ck("detect \"$txt\"", $r && $r['area']===$area && $r['city']===$city, $r?($r['area'].'/'.$r['city']):'null'); }
ck('detect "rice" -> null (not a place)', L::detect('rice')===null);
ck('detect "give me sugar" -> null', L::detect('give me sugar')===null);

echo "\n[INTENT] location messages must NOT be product-searched\n";
foreach(['Am in Kisaasi','Upper Mawanda','Near Total Ntinda','Munyonyo','Deliver to Jebel',"I'm at Bweyogerere",'Kisasi','konyo konyo'] as $t){
  ck("classify \"$t\" = LOCATION", IC::classify($t,$TS)===IC::LOCATION, IC::classify($t,$TS));
}

echo "\n[INTENT] real products still SHOP (strong signal wins)\n";
foreach(['rice','sugar','2kg sugar','add milk','cooking oil','rice and sugar'] as $t){
  ck("classify \"$t\" = SHOPPING", IC::classify($t,$TS)===IC::SHOPPING, IC::classify($t,$TS));
}

echo "\n[INTENT] conversational unaffected\n";
ck('"thank you" = THANKS', IC::classify('thank you',$TS)===IC::THANKS);
ck('"hi" = GREETING', IC::classify('hi',$TS)===IC::GREETING);
ck('"checkout" = CHECKOUT', IC::classify('checkout',$TS)===IC::CHECKOUT);

echo "\n[CANONICALIZE]\n";
ck('"Kisasi" -> Kisaasi', L::canonicalize('Kisasi')==='Kisaasi', L::canonicalize('Kisasi'));
ck('"near total ntinda" -> Ntinda', L::canonicalize('near total ntinda')==='Ntinda', L::canonicalize('near total ntinda'));
ck('"tongping" -> Thongpiny', L::canonicalize('tongping')==='Thongpiny');

echo "\n[ZONE MATCHING] misspelling matches the zone after canonicalisation\n";
$zones=[['id'=>1,'name'=>'Inner Kampala','match_keywords'=>['kisaasi','ntinda','bukoto'],'flat_fee'=>3000,'min_fee'=>3000,'eta_minutes'=>30,
         'center_lat'=>null,'center_lng'=>null,'radius_m'=>null,'per_km_fee'=>0,'free_over'=>0]];
$raw  = ZoneResolver::matchZone('Kisasi', null, null, $zones);             // typo, no canonicalisation
$canon= ZoneResolver::matchZone(L::canonicalize('Kisasi'), null, null, $zones);  // canonicalised
ck('raw misspelling "Kisasi" misses the zone', $raw===null);
ck('canonicalised "Kisaasi" matches the zone', $canon!==null && $canon['id']===1, $canon['name']??'null');
$fee = ZoneResolver::computeFee($canon, 20000, null, ['base'=>5000,'per_km'=>0,'min'=>5000,'free_over'=>0]);
ck('zone fee used (3000) not fallback (5000)', $fee===3000, "fee=$fee");

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
