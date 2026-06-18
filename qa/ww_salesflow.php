<?php
require __DIR__ . '/../app/Services/Winworld/ShiftCalendar.php';
require __DIR__ . '/../app/Services/Winworld/SlaClock.php';
require __DIR__ . '/../app/Services/Winworld/SalesFlow.php';
use App\Services\Winworld\SlaClock as C;
use App\Services\Winworld\SalesFlow as F;
$pass=0;$fail=0;
function ok($c,$l){global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}
function eqs($g,$w,$l){global $pass,$fail; if($g===$w)$pass++; else{$fail++; echo "  FAIL $l -> ".var_export($g,true)." != ".var_export($w,true)."\n";}}

/* ---- SalesFlow ---- */
eqs(F::ORDER[0],'enquiry','first stage');
eqs(F::next('enquiry'),'order_received','next after enquiry');
eqs(F::next('order_received'),'credit_check','next after order received');
eqs(F::next('delivery'),null,'delivery is last');
eqs(F::role('credit_check'),'sales_coord','credit check owner');
eqs(F::label('sap_approval'),'SAP posting & approval','label');

// approvals
eqs(F::approvalsFor('sap_approval'),['sm','md'],'SAP needs SM then MD');
eqs(F::approvalsFor('credit_check',10),[],'credit ok (10d) no approval');
eqs(F::approvalsFor('credit_check',45),['md'],'credit overdue 45d -> MD');
eqs(F::approvalsFor('order_received'),[],'plain stage no approval');

// canAdvance
ok(F::canAdvance('sap_approval',['sm'],0)===false,'SAP: SM only -> cannot advance');
ok(F::canAdvance('sap_approval',['sm','md'],0)===true,'SAP: SM+MD -> can advance');
ok(F::canAdvance('credit_check',[],10)===true,'credit ok -> advance freely');
ok(F::canAdvance('credit_check',[],45)===false,'credit 45d -> needs MD');
ok(F::canAdvance('credit_check',['md'],45)===true,'credit 45d + MD -> advance');
ok(F::canAdvance('order_received',[],0)===true,'plain stage advances');

/* ---- SlaClock ---- */
$start = new DateTimeImmutable('2026-06-18 10:00'); // a Thursday
$now1  = new DateTimeImmutable('2026-06-18 10:20');
// clock hours: 1h SLA due 11:00
$due = C::dueAt($start, ['h'=>1]);
eqs($due->format('H:i'),'11:00','1h clock SLA due 11:00');
eqs(C::status($due,$now1),'ok','40 min before due -> ok');
eqs(C::status($due,new DateTimeImmutable('2026-06-18 10:45')),'due_soon','15 min before due -> due_soon');
eqs(C::status($due,new DateTimeImmutable('2026-06-18 10:30')),'due_soon','exactly 30 min -> due_soon');
eqs(C::status($due,new DateTimeImmutable('2026-06-18 11:30')),'overdue','past due -> overdue');
eqs(C::minutesLeft($due,$now1),40,'40 minutes left');

// 3h SLA
eqs(C::dueAt($start,['h'=>3])->format('H:i'),'13:00','3h SLA -> 13:00');

// working-days SLA on office calendar (08-17 Mon-Sat = 9h/day). 3 working days from Thu 10:00.
$dueWd = C::dueAt($start, ['wd'=>3]);
ok($dueWd > $start, 'working-day SLA is in the future');
// 3 wd = 27 working hours; Thu 10:00 -> Thu(7h to17:00)+Fri(9)+Sat(9)=25, +2h Mon 10:00
eqs($dueWd->format('D H:i'),'Mon 10:00','3 working days skips Sunday to Monday');

echo "ww_salesflow: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
