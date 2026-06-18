<?php
/** Winworld Blending (BOM) engine - pure logic. */
require __DIR__ . '/../app/Services/Winworld/Blending.php';
use App\Services\Winworld\Blending;

$pass = 0; $fail = 0;
function ok($c, string $l): void { global $pass,$fail; if($c)$pass++; else {$fail++; echo "  FAIL $l\n";} }
function eqf(float $g, float $w, string $l, float $eps = 0.001): void { global $pass,$fail; if(abs($g-$w)<=$eps)$pass++; else {$fail++; echo "  FAIL $l -> $g != $w\n";} }

// single extruder recipe, 100kg mix, 70/30
$r = Blending::compute(100.0, [
    ['material' => 'LD Resin',   'pct_a' => 70],
    ['material' => 'Masterbatch','pct_a' => 30],
]);
eqf($r['lines'][0]['qty_a'], 70.0, '70% of 100kg = 70');
eqf($r['lines'][1]['qty_a'], 30.0, '30% of 100kg = 30');
eqf($r['totals']['a'], 100.0, 'col A total = 100');
eqf($r['total_kgs'], 100.0, 'grand total = 100 (one extruder)');
ok($r['ok'] === true, 'balanced recipe ok');

// two extruders, A balanced, B unbalanced (sums 90)
$r2 = Blending::compute(200.0, [
    ['material' => 'LD',   'pct_a' => 50, 'pct_b' => 40],
    ['material' => 'LLD',  'pct_a' => 50, 'pct_b' => 50],
]);
eqf($r2['totals']['a'], 200.0, 'A total = 200');
eqf($r2['totals']['b'], 180.0, 'B total = 180 (90% of 200)');
eqf($r2['total_kgs'], 380.0, 'grand total A+B = 380');
ok($r2['balanced']['a'] === true,  'col A balanced (100%)');
ok($r2['balanced']['b'] === false, 'col B unbalanced flagged (90%)');
ok($r2['ok'] === false, 'overall not ok when a column is off');

// empty / zero mix
$r3 = Blending::compute(0.0, [['material' => 'X', 'pct_a' => 100]]);
eqf($r3['total_kgs'], 0.0, 'zero mix -> zero kg');

echo "ww_blending: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
