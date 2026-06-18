<?php
require __DIR__ . '/../app/Services/Winworld/MaterialYield.php';
use App\Services\Winworld\MaterialYield as Y;
$pass=0;$fail=0;
function eqf($g,$w,$l,$e=0.05){global $pass,$fail; if(abs($g-$w)<=$e)$pass++; else{$fail++; echo "  FAIL $l -> $g != $w\n";}}
function ok($c,$l){global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}

// input recorded
$r = Y::rollup([
    ['input_kg'=>100,'produced_kg'=>90,'scrap_kg'=>8,'regrind_kg'=>5],
    ['input_kg'=>200,'produced_kg'=>185,'scrap_kg'=>10,'regrind_kg'=>6],
]);
eqf($r['input_kg'],300,'input summed');
eqf($r['good_kg'],257,'good = produced-scrap (82+175)');
eqf($r['yield_pct'],85.7,'yield 257/300');
eqf($r['scrap_kg'],18,'scrap summed');
eqf($r['regrind_kg'],11,'regrind summed');
eqf($r['regrind_recovery_pct'],61.1,'regrind recovery 11/18');
eqf($r['waste_pct'],14.3,'waste 43/300');

// input NOT recorded -> falls back to produced+scrap
$r2 = Y::rollup([['produced_kg'=>90,'scrap_kg'=>10]]);
eqf($r2['input_kg'],100,'fallback input = produced+scrap');
eqf($r2['yield_pct'],80,'fallback yield 80/100');

// empty / zero guard
$r3 = Y::rollup([]);
eqf($r3['yield_pct'],0,'empty -> 0'); ok($r3['input_kg']===0.0,'empty input 0');

echo "ww_material_yield: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
