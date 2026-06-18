<?php
require __DIR__ . '/../app/Services/Winworld/Oee.php';
require __DIR__ . '/../app/Services/Winworld/Analytics.php';
use App\Services\Winworld\Analytics as A;
$pass=0;$fail=0;
function ok($c,$l){global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}
function eqf($g,$w,$l,$eps=0.01){global $pass,$fail; if(abs($g-$w)<=$eps)$pass++; else{$fail++; echo "  FAIL $l -> $g != $w\n";}}
function eqs($g,$w,$l){global $pass,$fail; if($g===$w)$pass++; else{$fail++; echo "  FAIL $l -> ".var_export($g,true)." != ".var_export($w,true)."\n";}}

// --- downtime pareto (worst first) ---
$entries = [
    ['stop_reason'=>'Power Failure','actual_hours'=>1.0],
    ['stop_reason'=>'Material Shortage','actual_hours'=>3.0],
    ['stop_reason'=>'Power Failure','actual_hours'=>2.0],
    ['stop_reason'=>'','actual_hours'=>5.0],            // not a stop
    ['actual_hours'=>4.0],                               // no reason key
];
$p = A::downtimePareto($entries);
eqs(count($p), 2, 'two distinct stop reasons');
eqs($p[0]['reason'], 'Power Failure', 'worst by hours first (3h > ...)');
eqf($p[0]['hours'], 3.0, 'power failure total 3h');
eqs($p[0]['count'], 2, 'power failure count 2');
eqf($p[1]['hours'], 3.0, 'material shortage 3h');
eqs($p[1]['count'], 1, 'material shortage count 1');

// --- machine OEE with plan ---
$me = A::machineOee([
    ['actual_hours'=>5,'produced_kg'=>210,'scrap_kg'=>10,'target_output_kg_hr'=>45],
    ['actual_hours'=>5,'produced_kg'=>200,'scrap_kg'=>0,'target_output_kg_hr'=>45],
], 12.0);
eqf($me['run_hours'],10,'run hours summed');
eqf($me['produced_kg'],410,'produced summed');
eqf($me['availability'],10/12,'availability run/planned');
ok($me['has_plan']===true,'has_plan true when planned given');
// performance: actualRate=410/10=41, targetRate=45 -> 0.9111
eqf($me['performance'],41/45,'time-weighted performance');
// quality (410-10)/410
eqf($me['quality'],(410-10)/410,'quality from scrap');

// --- machine OEE WITHOUT plan -> availability falls back to 1 ---
$me2 = A::machineOee([['actual_hours'=>4,'produced_kg'=>160,'scrap_kg'=>0,'target_output_kg_hr'=>40]], 0.0);
eqf($me2['availability'],1.0,'no plan -> availability 1');
ok($me2['has_plan']===false,'has_plan false');
eqf($me2['performance'],1.0,'40/40 = perfect performance');
eqf($me2['oee'],1.0,'OEE 1 when A=P=Q=1');

// --- machine board ---
$now = new DateTimeImmutable('2026-06-18 12:00');
$board = A::machineBoard([
    ['machine_id'=>1,'planned_start'=>'2026-06-18 14:00','planned_end'=>'2026-06-18 18:00','required_hours'=>4],
    ['machine_id'=>1,'planned_start'=>'2026-06-20 07:00','planned_end'=>'2026-06-20 11:00','required_hours'=>4],
    ['machine_id'=>1,'planned_start'=>'2026-06-30 07:00','planned_end'=>'2026-06-30 09:00','required_hours'=>2], // outside 7d
    ['machine_id'=>2,'planned_start'=>'2026-06-19 07:00','planned_end'=>'2026-06-19 10:00','required_hours'=>3],
], $now);
$m1 = array_values(array_filter($board, fn($b)=>$b['machine_id']===1))[0];
eqs($m1['next_available'], '2026-06-30 09:00', 'next_available = latest planned_end');
eqf($m1['booked_hours_7d'], 8.0, 'booked within 7d = 4+4 (the +12d one excluded)');

// --- summary ---
$s = A::summary(
    [['status'=>'Open','order_kg'=>10],['status'=>'Planned','order_kg'=>20],['status'=>'Completed','order_kg'=>30]],
    [['produced_kg'=>100,'scrap_kg'=>10,'efficiency_pct'=>90],['produced_kg'=>100,'scrap_kg'=>0,'efficiency_pct'=>110]]
);
eqs($s['by_status']['Open'],1,'1 open'); eqs($s['by_status']['Completed'],1,'1 completed');
eqf($s['order_kg'],60,'order kg total'); eqf($s['produced_kg'],200,'produced total');
eqf($s['avg_efficiency_pct'],100,'avg efficiency (90,110)->100');
eqf($s['first_pass_yield'],95,'FPY (200-10)/200=95%');

echo "ww_analytics: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
