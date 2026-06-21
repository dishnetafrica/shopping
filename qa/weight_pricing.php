<?php
// Weight Pricing V1 — pure unit tests (your spec's test cases).
require __DIR__ . '/../app/Services/Bot/Pricing/WeightParser.php';
require __DIR__ . '/../app/Services/Bot/Pricing/WeightPricer.php';
use App\Services\Bot\Pricing\WeightParser as WP;
use App\Services\Bot\Pricing\WeightPricer as PR;

$pass = 0; $fail = 0;
function eq($got, $want, string $l) { global $pass,$fail; if ($got===$want) {$pass++;} else {$fail++; echo "  FAIL $l → ".var_export($got,true)." != ".var_export($want,true)."\n";} }

echo "=== units → grams ===\n";
foreach ([
    '100g'=>100,'100 g'=>100,'100 gram'=>100,'100 grams'=>100,
    '1kg'=>1000,'1 kg'=>1000,'1 kilo'=>1000,'1 kilos'=>1000,
    '500gm'=>500,'500 gm'=>500,'1.5kg'=>1500,'0.75 kg'=>750,'750g'=>750,'1500g'=>1500,
    '750g Black Pepper Kaju'=>750,'300g sev'=>300,
] as $in=>$want) eq(WP::grams($in), $want, "grams('$in')");
eq(WP::grams('paneer'), null, "grams('paneer') = null");
eq(WP::grams('2 packet sev'), null, "no bare unit -> null");

echo "=== pro-rata pricing (ref 1kg = 50,000) ===\n";
$kaju = ['reference_price'=>50000,'reference_weight_grams'=>1000];
foreach ([250=>12500,500=>25000,750=>37500,1000=>50000,1500=>75000] as $g=>$want) {
    $r = PR::price($g, $kaju); eq($r['price'] ?? null, $want, "price({$g}g) = $want"); eq($r['source'] ?? null, 'prorata', "  source prorata @ {$g}g");
}

echo "=== sev (ref 1kg = 30,000) ===\n";
$sev = ['reference_price'=>30000,'reference_weight_grams'=>1000];
eq(PR::price(100,$sev)['price'], 3000, "sev 100g = 3000");
eq(PR::price(300,$sev)['price'], 9000, "sev 300g = 9000");

echo "=== variant-first wins over pro-rata ===\n";
$withVar = $kaju + ['variants'=>[500=>24000, 1000=>50000]];
eq(PR::price(500,$withVar)['price'], 24000, "500g variant 24000 (not 25000)");
eq(PR::price(500,$withVar)['source'], 'variant', "  source variant");
eq(PR::price(750,$withVar)['source'], 'prorata', "  750g still prorata");

echo "=== rounding (nearest 100) ===\n";
eq(PR::price(333,$kaju)['price'], 16700, "333g 16650 → 16700");

echo "=== minimum weight (100g) ===\n";
$lo = PR::price(50,$kaju);  eq($lo['ok'], false, "50g rejected");  eq($lo['reason'] ?? null, 'min', "  reason min");
$lo2 = PR::price(25,$kaju); eq($lo2['ok'], false, "25g rejected");

echo "=== safety: result carries grams+price, never a count ===\n";
$r = PR::price(750,$kaju);
eq(isset($r['grams']) && isset($r['price']) && !isset($r['qty']), true, "no qty field; weight_grams+price only");
eq($r['price'] !== 750*50000, true, "750g is NOT 750 × unit price");

echo "\n" . ($fail===0 ? "ALL GREEN: $pass passed, 0 failed.\n" : "$pass passed, $fail FAILED.\n");
if ($fail) exit(1);
