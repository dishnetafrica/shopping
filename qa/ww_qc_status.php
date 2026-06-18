<?php
require __DIR__ . '/../app/Services/Winworld/QcStatus.php';
use App\Services\Winworld\QcStatus as Q;
$pass=0;$fail=0;
function ok($c,$l){global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}
function eqs($g,$w,$l){global $pass,$fail; if($g===$w)$pass++; else{$fail++; echo "  FAIL $l -> ".var_export($g,true)." != ".var_export($w,true)."\n";}}

$active = ['Extrusion','Printing'];
$rows = [
    ['process'=>'Extrusion','supervisor_sign'=>'Asha','qc_sign'=>'Ben','sec_head_sign'=>'Cara','result'=>'pass'],
    ['process'=>'Printing','supervisor_sign'=>'Dev','qc_sign'=>'','sec_head_sign'=>'','result'=>null],
];
$pp = Q::perProcess($active, $rows);
eqs(count($pp),2,'two processes');
ok($pp[0]['complete']===true,'extrusion fully signed -> complete');
ok($pp[1]['supervisor']===true && $pp[1]['qc']===false,'printing has only supervisor');
ok($pp[1]['complete']===false,'printing not complete');
eqs(Q::signedCount($pp),1,'1 of 2 signed');
ok(Q::allSigned($pp)===false,'not all signed');
ok(Q::anyReject($pp)===false,'no reject yet');

// all signed
$rows2=[
    ['process'=>'Extrusion','supervisor_sign'=>'A','qc_sign'=>'B','sec_head_sign'=>'C','result'=>'pass'],
    ['process'=>'Printing','supervisor_sign'=>'D','qc_sign'=>'E','sec_head_sign'=>'F','result'=>'reject'],
];
$pp2=Q::perProcess($active,$rows2);
ok(Q::allSigned($pp2)===true,'all processes signed -> release ready');
ok(Q::anyReject($pp2)===true,'a reject is detected');
eqs(Q::signedCount($pp2),2,'2 of 2 signed');

// process with no qc row at all
$pp3=Q::perProcess(['Cutting'],[]);
ok($pp3[0]['complete']===false,'missing qc row -> not complete');
ok(Q::allSigned([])===false,'empty -> not signed');

echo "ww_qc_status: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
