<?php
require __DIR__ . '/../app/Services/Winworld/Alerts.php';
use App\Services\Winworld\Alerts as A;
$pass=0;$fail=0;
function ok($c,$l){global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}
function eqs($g,$w,$l){global $pass,$fail; if($g===$w)$pass++; else{$fail++; echo "  FAIL $l -> ".var_export($g,true)." != ".var_export($w,true)."\n";}}
function has($arr,$type){foreach($arr as $a)if($a['type']===$type)return $a;return null;}

$base=['indent_no'=>'003-090626','product'=>'LD bags','machine'=>'A-1','process'=>'Extrusion'];

// material shortage -> stores
$a=A::fromEntry($base+['stop_reason'=>'Material Shortage']);
$s=has($a,'stop'); ok($s!==null,'material stop fires'); eqs($s['role'],'stores','material -> stores');
// breakdown -> maintenance
$a=A::fromEntry($base+['stop_reason'=>'Machine Breakdown']);
eqs(has($a,'stop')['role'],'maintenance','breakdown -> maintenance');
// power -> production
$a=A::fromEntry($base+['stop_reason'=>'Power Failure']);
eqs(has($a,'stop')['role'],'production','power -> production');

// qc reject
$a=A::fromEntry($base+['qc_result'=>'reject']);
ok(has($a,'qc_reject')!==null,'qc reject fires');
eqs(has($a,'qc_reject')['role'],'production','qc reject -> production');

// slow run (< threshold) and NOT slow when >= threshold
$a=A::fromEntry($base+['efficiency_pct'=>55]); ok(has($a,'slow')!==null,'55% < 70 -> slow');
$a=A::fromEntry($base+['efficiency_pct'=>85]); ok(has($a,'slow')===null,'85% -> not slow');
$a=A::fromEntry($base+['efficiency_pct'=>0]);  ok(has($a,'slow')===null,'0/blank -> no slow alert');
// slow suppressed when stopped (downtime already alerted)
$a=A::fromEntry($base+['efficiency_pct'=>40,'stop_reason'=>'Power Failure']);
ok(has($a,'slow')===null,'slow suppressed when stopped'); ok(has($a,'stop')!==null,'stop still fires');

// custom threshold
$a=A::fromEntry($base+['efficiency_pct'=>75], 80.0); ok(has($a,'slow')!==null,'75% < custom 80 -> slow');

// multiple at once: reject + slow
$a=A::fromEntry($base+['qc_result'=>'reject','efficiency_pct'=>50]);
eqs(count($a),2,'reject + slow = 2 alerts');

// delay risk
ok(A::delayRisk(['indent_no'=>'1','product'=>'x','planned_end'=>'2026-06-25 12:00','required_date'=>'2026-06-20'])!==null,'late -> delay risk');
ok(A::delayRisk(['indent_no'=>'1','product'=>'x','planned_end'=>'2026-06-19 12:00','required_date'=>'2026-06-20'])===null,'on time -> no alert');
ok(A::delayRisk(['indent_no'=>'1','product'=>'x','planned_end'=>'2026-06-20 23:00','required_date'=>'2026-06-20'])===null,'same day -> no alert');
ok(A::delayRisk(['indent_no'=>'1','product'=>'x'])===null,'missing dates -> no alert');
eqs(A::delayRisk(['indent_no'=>'7','product'=>'x','planned_end'=>'2026-06-25','required_date'=>'2026-06-20'])['role'],'sales','delay -> sales');

echo "ww_alerts: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
