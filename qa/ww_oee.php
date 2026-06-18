<?php
/** Winworld OEE engine - pure logic. */
require __DIR__ . '/../app/Services/Winworld/Oee.php';
use App\Services\Winworld\Oee;

$pass = 0; $fail = 0;
function ok($c, string $l): void { global $pass,$fail; if($c)$pass++; else {$fail++; echo "  FAIL $l\n";} }
function eqf(float $g, float $w, string $l, float $eps = 0.0005): void { global $pass,$fail; if(abs($g-$w)<=$eps)$pass++; else {$fail++; echo "  FAIL $l -> $g != $w\n";} }

eqf(Oee::availability(10, 12), 10/12, 'availability 10/12');
eqf(Oee::availability(13, 12), 1.0, 'availability capped at 1');
eqf(Oee::availability(5, 0), 0.0, 'no planned time -> 0');

eqf(Oee::performanceRaw(60, 80), 0.75, 'performance raw 60/80');
eqf(Oee::performanceRaw(90, 80), 1.125, 'performance raw can exceed 1');
eqf(Oee::quality(200, 20), 0.9, 'quality (200-20)/200 = 0.9');
eqf(Oee::quality(200, 0), 1.0, 'no scrap -> quality 1');
eqf(Oee::quality(0, 0), 0.0, 'no production -> 0');

// full compute: 10h run / 12h planned, 60 vs 80 kg/hr, 200kg with 20 scrap
$m = Oee::compute(10, 12, 60, 80, 200, 20);
eqf($m['availability'], 0.8333, 'compute availability');
eqf($m['performance'], 0.75, 'compute performance (capped)');
eqf($m['quality'], 0.9, 'compute quality');
eqf($m['oee'], round((10/12)*0.75*0.9, 4), 'compute OEE = AxPxQ');
eqf($m['efficiency_pct'], 75.0, 'efficiency % uncapped = 75');

// faster-than-standard: performance_raw>1 but OEE caps it
$m2 = Oee::compute(12, 12, 90, 80, 100, 0);
eqf($m2['performance_raw'], 1.125, 'raw perf 1.125');
eqf($m2['performance'], 1.0, 'capped perf 1.0');
eqf($m2['oee'], 1.0, 'OEE 1.0 at full A, capped P, perfect Q');
eqf($m2['efficiency_pct'], 112.5, 'efficiency % 112.5 (uncapped)');

echo "ww_oee: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
