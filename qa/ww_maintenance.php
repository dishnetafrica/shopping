<?php
require __DIR__ . '/../app/Services/Winworld/Maintenance.php';
use App\Services\Winworld\Maintenance as M;
$pass=0;$fail=0;
function eqf($g,$w,$l,$e=0.05){global $pass,$fail; if(abs($g-$w)<=$e)$pass++; else{$fail++; echo "  FAIL $l -> $g != $w\n";}}
function eqi($g,$w,$l){global $pass,$fail; if($g===$w)$pass++; else{$fail++; echo "  FAIL $l -> ".var_export($g,true)." != ".var_export($w,true)."\n";}}

$orders = [
  // three completed breakdowns: 30, 60, 90 min repair
  ['type'=>'breakdown','machine_id'=>1,'machine'=>'A-1','status'=>'done','downtime_min'=>45,'started_at'=>'2026-06-10 08:00','completed_at'=>'2026-06-10 08:30'],
  ['type'=>'breakdown','machine_id'=>1,'machine'=>'A-1','status'=>'done','downtime_min'=>90,'started_at'=>'2026-06-11 08:00','completed_at'=>'2026-06-11 09:00'],
  ['type'=>'breakdown','machine_id'=>2,'machine'=>'ABA','status'=>'done','downtime_min'=>120,'started_at'=>'2026-06-12 08:00','completed_at'=>'2026-06-12 09:30'],
  // open breakdown (no repair time yet) - excluded from MTTR
  ['type'=>'breakdown','machine_id'=>2,'machine'=>'ABA','status'=>'open','downtime_min'=>0,'reported_at'=>'2026-06-18 07:00'],
  // PMs: 2 due, 1 on-time, 1 missed; 1 not yet due
  ['type'=>'preventive','machine_id'=>1,'machine'=>'A-1','status'=>'done','due_at'=>'2026-06-15 17:00','completed_at'=>'2026-06-14 12:00'],
  ['type'=>'preventive','machine_id'=>2,'machine'=>'ABA','status'=>'open','due_at'=>'2026-06-16 17:00'],
  ['type'=>'preventive','machine_id'=>1,'machine'=>'A-1','status'=>'open','due_at'=>'2026-07-30 17:00'],
];

$mttr = M::mttr($orders);
eqi($mttr['count'],3,'mttr counts 3 completed breakdowns');
eqf($mttr['minutes'],60,'mttr mean 60 min (30+60+90)/3... actually 30,60,90');

$mtbf = M::mtbf(200.0, 4);
eqf($mtbf['hours'],50,'mtbf 200h / 4 failures');
eqi($mtbf['failures'],4,'mtbf failures');

$pm = M::pmCompliance($orders, '2026-06-18 23:59');
eqi($pm['due'],2,'2 PMs due by asOf');
eqi($pm['on_time'],1,'1 PM on time');
eqf($pm['pct'],50,'PM compliance 50%');

$pmNone = M::pmCompliance([], '2026-06-18');
eqf($pmNone['pct'],100,'nothing due -> 100%');

$bm = M::byMachine($orders);
eqi($bm[0]['machine'],'A-1','worst machine first by downtime (A-1 135)');
eqf($bm[0]['downtime_min'],135,'A-1 downtime 45+90');
eqi($bm[0]['failures'],2,'A-1 failures');

$s = M::summary($orders, 200.0, '2026-06-18 23:59');
eqi($s['breakdowns'],4,'summary breakdowns');
eqi($s['open'],3,'summary open: 1 bd + 2 pm open');
eqf($s['mttr']['minutes'],60,'summary mttr');
eqf($s['mtbf']['hours'],50,'summary mtbf');
eqf($s['pm']['pct'],50,'summary pm%');

echo "ww_maintenance: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
