<?php
/** Winworld Formula engine - pure logic. */
require __DIR__ . '/../app/Services/Winworld/Formula.php';
use App\Services\Winworld\Formula;

$pass = 0; $fail = 0;
function ok($c, string $l): void { global $pass,$fail; if($c)$pass++; else {$fail++; echo "  FAIL $l\n";} }
function eqf(float $g, float $w, string $l, float $eps = 0.001): void { global $pass,$fail; if(abs($g-$w)<=$eps)$pass++; else {$fail++; echo "  FAIL $l -> $g != $w\n";} }

// gram/pcs = W x L x gauge / 3300
eqf(Formula::gramPerPcs(20, 150, 120), (20*150*120)/3300, 'gram/pcs basic');     // 109.0909
eqf(Formula::gramPerPcs(41, 60, 120),  (41*60*120)/3300,  'gram/pcs OIF-ish');
eqf(Formula::gramPerPcs(0, 150, 120), 0.0, 'zero width -> 0');
eqf(Formula::gramPerPcs(20, 150, 0),  0.0, 'zero gauge -> 0');

// order kg = qty x gram/pcs / 1000
eqf(Formula::orderKg(300, 100), 30.0, 'order kg 300x100g = 30kg');
eqf(Formula::orderKg(0, 100), 0.0, 'zero qty -> 0');

// final output: manual wins, else auto, else 0
eqf(Formula::finalOutputKgHr(50.0, 80.0), 80.0, 'manual wins over auto');
eqf(Formula::finalOutputKgHr(50.0, null), 50.0, 'auto used when no manual');
eqf(Formula::finalOutputKgHr(50.0, 0.0),  50.0, 'manual 0 falls back to auto');
eqf(Formula::finalOutputKgHr(null, null), 0.0, 'no rate -> 0');

// required hours = order kg / final output
eqf(Formula::requiredHours(30.0, 60.0), 0.5, 'required hours 30kg @60kg/hr = 0.5h');
eqf(Formula::requiredHours(30.0, 0.0),  0.0, 'no rate -> 0 hours (not div by zero)');
eqf(Formula::requiredHours(0.0, 60.0),  0.0, 'no kg -> 0 hours');

// elapsed + actual output
$s = new DateTimeImmutable('2026-06-18 07:00:00');
$e = new DateTimeImmutable('2026-06-18 11:00:00');
eqf(Formula::elapsedHours($s, $e), 4.0, 'elapsed 4h');
eqf(Formula::elapsedHours($e, $s), 0.0, 'reversed -> 0');
eqf(Formula::actualOutputKgHr(240.0, 4.0), 60.0, 'actual output 240kg/4h = 60kg/hr');
eqf(Formula::actualOutputKgHr(240.0, 0.0), 0.0, 'no hours -> 0');

echo "ww_formula: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
