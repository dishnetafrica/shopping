<?php
/**
 * Winworld Phase-1 ACCEPTANCE walkthrough (engine-level, pure logic).
 * Walks the real OIF 003-090626 (Delicious Bakery, 1KG milk-bread bags, qty 300)
 * through: item -> order kg -> plan -> required hours -> planned end -> actuals -> OEE.
 * Numbers for length / output rate are illustrative pending design Q2.
 */
require __DIR__ . '/../app/Services/Winworld/Formula.php';
require __DIR__ . '/../app/Services/Winworld/Blending.php';
require __DIR__ . '/../app/Services/Winworld/ShiftCalendar.php';
require __DIR__ . '/../app/Services/Winworld/Oee.php';
use App\Services\Winworld\{Formula, Blending, ShiftCalendar, Oee};

$pass=0; $fail=0;
function ok($c,string $l):void{global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}

echo "Indent 003-090626 | Delicious Bakery | LD Printed Bags 1KG milk bread | qty 300\n";
echo str_repeat('-',64)."\n";

// 1) ITEM: gram/pcs from dimensions (W 41 x L 24 x gauge 120 — L illustrative)
$gram = Formula::gramPerPcs(41, 24, 120);
printf("1. gram/pcs        = 41 x 24 x 120 / 3300 = %.4f g\n", $gram);
ok($gram > 0, 'gram/pcs computed');

// 2) ORDER: kg for 300 pcs
$orderKg = Formula::orderKg(300, $gram);
printf("2. order_kg        = 300 x %.4f / 1000     = %.3f kg\n", $gram, $orderKg);
ok($orderKg > 0, 'order_kg computed');

// 3) BLENDING: mixing 100kg, 70% LD + 30% masterbatch on Ext-A
$blend = Blending::compute(100, [['material'=>'LD Resin','pct_a'=>70],['material'=>'White MB','pct_a'=>30]]);
printf("3. blend (100kg)   = LD %.0fkg + MB %.0fkg, total %.0fkg, balanced=%s\n",
    $blend['lines'][0]['qty_a'], $blend['lines'][1]['qty_a'], $blend['total_kgs'], $blend['ok']?'yes':'NO');
ok($blend['ok'], 'recipe balances to 100%');

// 4) PLAN: manual output wins (45 kg/hr), required hours
$final = Formula::finalOutputKgHr(38.0 /*auto advisory*/, 45.0 /*manual*/);
$hours = Formula::requiredHours($orderKg, $final);
printf("4. final output    = %.0f kg/hr (manual over auto) ; required = %.3f h\n", $final, $hours);
ok($final === 45.0, 'manual output is source of truth');
ok($hours > 0, 'required hours computed');

// 5) SCHEDULE: planned end on the 12h window, start 2026-06-18 14:00
$cal = new ShiftCalendar(7,19);
$start = new DateTimeImmutable('2026-06-18 14:00');
$end = $cal->addWorkingHours($start, $hours);
printf("5. planned_start   = %s -> planned_end = %s (shift-aware)\n", $start->format('Y-m-d H:i'), $end->format('Y-m-d H:i'));
ok($end > $start, 'planned_end after start');

// 6) ACTUALS: ran 14:00-19:00 (5h), produced 210kg, 8kg scrap, target = 45
$as = new DateTimeImmutable('2026-06-18 14:00'); $ae = new DateTimeImmutable('2026-06-18 19:00');
$ah = Formula::elapsedHours($as,$ae);
$aout = Formula::actualOutputKgHr(210, $ah);
printf("6. actuals         = %.1fh run, 210kg (8 scrap), actual output %.2f kg/hr\n", $ah, $aout);
ok(abs($ah-5.0)<0.001, 'actual hours = 5');

// 7) OEE: planned 5h available, actual 5h, 42 vs 45 kg/hr, 210kg / 8 scrap
$m = Oee::compute(5, 5, $aout, 45, 210, 8);
printf("7. OEE             = A %.2f x P %.2f x Q %.2f = %.3f (eff %.1f%%)\n",
    $m['availability'],$m['performance'],$m['quality'],$m['oee'],$m['efficiency_pct']);
ok($m['oee']>0 && $m['oee']<=1, 'OEE in [0,1]');
ok($m['quality']>0.95 && $m['quality']<1, 'quality reflects 8kg scrap');

echo str_repeat('-',64)."\n";
echo "ww_acceptance: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
