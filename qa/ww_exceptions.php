<?php
require __DIR__ . '/../app/Services/Winworld/ExceptionFlow.php';
require __DIR__ . '/../app/Services/Winworld/Alerts.php';
use App\Services\Winworld\ExceptionFlow as E;
use App\Services\Winworld\Alerts as A;
$pass=0;$fail=0;
function ok($c,$l){global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}
function eqs($g,$w,$l){global $pass,$fail; if($g===$w)$pass++; else{$fail++; echo "  FAIL $l -> ".var_export($g,true)." != ".var_export($w,true)."\n";}}

/* ---- ExceptionFlow ---- */
eqs(E::role('complaint'),'pdm','complaint -> PD.M');
eqs(E::sla('complaint'),['wh'=>8],'complaint 8 working h');
eqs(E::role('goods_return'),'sdh','goods return -> SDH');
eqs(E::label('credit_note'),'Credit note','label');

// goods return value gate (10M)
eqs(E::approvalsFor('goods_return', 5000000),['sm'],'5M return -> SM only');
eqs(E::approvalsFor('goods_return', 10000000),['sm'],'exactly 10M -> SM only (not above)');
eqs(E::approvalsFor('goods_return', 15000000),['sm','md'],'15M return -> SM + MD');
eqs(E::approvalsFor('credit_note'),['sm','md'],'credit note -> SM + MD');
eqs(E::approvalsFor('debit_note'),['sm','md'],'debit note -> SM + MD');
eqs(E::approvalsFor('complaint'),[],'complaint -> no approval gate');

// canResolve
ok(E::canResolve('goods_return',['sm'],5000000)===true,'5M return + SM -> resolvable');
ok(E::canResolve('goods_return',[],5000000)===false,'5M return, no approval -> not resolvable');
ok(E::canResolve('goods_return',['sm'],15000000)===false,'15M return + SM only -> not resolvable');
ok(E::canResolve('goods_return',['sm','md'],15000000)===true,'15M return + SM+MD -> resolvable');
ok(E::canResolve('credit_note',['sm'],0)===false,'credit note + SM only -> not resolvable');
ok(E::canResolve('credit_note',['sm','md'],0)===true,'credit note + SM+MD -> resolvable');
ok(E::canResolve('complaint',[],0)===true,'complaint -> resolvable by PD.M');
ok(E::isType('goods_return')===true && E::isType('nope')===false,'isType guard');

/* ---- Alerts::slaBreach ---- */
$b1=A::slaBreach(['order_no'=>'SO0007','customer'=>'Acme','stage_label'=>'Credit & ageing check','owner_role'=>'sales_coord','minutes_over'=>20,'escalate'=>false]);
eqs($b1['type'],'sla_breach','type');
eqs($b1['role'],'sales_coord','non-escalated -> owner role');
ok(strpos($b1['text'],'SO0007')!==false,'text has order no');
ok(strpos($b1['text'],'escalated')===false,'non-escalated head');

$b2=A::slaBreach(['order_no'=>'SO0007','customer'=>'Acme','stage_label'=>'SAP posting','owner_role'=>'sales_coord','minutes_over'=>150,'escalate'=>true]);
eqs($b2['role'],'sm','escalated -> SM');
ok(strpos($b2['text'],'escalated')!==false,'escalated head');

echo "ww_exceptions: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
