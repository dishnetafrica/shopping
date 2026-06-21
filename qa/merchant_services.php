<?php
// Pure merchant helpers: directory matching, daily-state reset, price guard, summary.
foreach (['MerchantDirectory','DailyState','PriceGuard','MerchantSummary'] as $c) require __DIR__ . "/../app/Services/Bot/Merchant/$c.php";
require __DIR__ . '/../app/Services/Bot/Pricing/WeightParser.php';
use App\Services\Bot\Merchant\MerchantDirectory as Dir;
use App\Services\Bot\Merchant\DailyState as DS;
use App\Services\Bot\Merchant\PriceGuard as PG;
use App\Services\Bot\Merchant\MerchantSummary as Sum;

$pass=0;$fail=0;
function ok($c,$l){global $pass,$fail; if($c){$pass++;}else{$fail++;echo "  FAIL $l\n";}}

echo "=== MerchantDirectory phone matching ===\n";
ok(Dir::normalize('+256 750 005 555')==='256750005555', "normalize +256 spaces");
ok(Dir::normalize('0256750005555')==='256750005555', "normalize leading 0");
$auth=['+256750005555','0772123456'];
ok(Dir::matches('256 750 005 555',$auth)===true, "match formatted");
ok(Dir::matches('256999999999',$auth)===false, "non-merchant rejected");
ok(Dir::matches('',$auth)===false, "empty rejected");

echo "=== DailyState auto-reset by date ===\n";
$today='2026-06-21';
$stale=['date'=>'2026-06-20','unavailable'=>[33],'menu'=>[1]];
ok(DS::fresh($stale,$today)['unavailable']===[], "stale state reset");
ok(DS::fresh($stale,$today)['date']===$today, "reset stamped today");
$cur=['date'=>$today,'unavailable'=>[33]]; 
ok(DS::fresh($cur,$today)['unavailable']===[33], "same-day state kept");
ok(DS::fresh(null,$today)['date']===$today && DS::fresh(null,$today)['hours']['closed']===false, "null → empty today");

echo "=== PriceGuard typo detection ===\n";
ok(PG::warn(9000,90000)!==null, "9000→90000 flagged (digit jump)");
ok(PG::warn(50000,55000)===null, "50000→55000 ok");
ok(PG::warn(50000,120000)!==null, "50000→120000 flagged (>2x)");
ok(PG::warn(null,50000)===null, "new product (no old) ok");

echo "=== MerchantSummary rendering ===\n";
$changes=[
  ['type'=>'menu','items'=>['fafda','jalebi','patra']],
  ['type'=>'availability','target'=>'khakhra','available'=>false],
  ['type'=>'hours','open'=>'10:00','close'=>'19:00','closed'=>false],
  ['type'=>'price','target'=>'kaju katri','weight_grams'=>1000,'price'=>90000,'old'=>50000],
];
$s=Sum::render($changes,['blah blah']);
ok(str_contains($s,'Fafda, Jalebi, Patra'), "menu listed");
ok(str_contains($s,'Khakhra — unavailable today'), "availability listed");
ok(str_contains($s,'Open 10:00') && str_contains($s,'Close 19:00'), "hours listed");
ok(str_contains($s,'Kaju Katri 1kg — UGX 90,000') && str_contains($s,'was UGX 50,000'), "price + was");
ok(str_contains($s,'Couldn’t read'), "unparsed surfaced");
ok(str_contains($s,'Reply YES'), "confirm prompt");

echo "\n".($fail===0?"ALL GREEN: $pass passed, 0 failed.\n":"$pass passed, $fail FAILED.\n");
if($fail) exit(1);
