<?php
require __DIR__ . '/../app/Services/Winworld/StatusFlow.php';
use App\Services\Winworld\StatusFlow as S;
$pass=0;$fail=0;
function eqs($g,$w,$l){global $pass,$fail; if($g===$w)$pass++; else{$fail++; echo "  FAIL $l -> ".var_export($g,true)." != ".var_export($w,true)."\n";}}

eqs(S::entryStatus(false, null), 'In Process', 'running -> in process');
eqs(S::entryStatus(true, null), 'Completed', 'ended -> completed');
eqs(S::entryStatus(false, 'Power Failure'), 'Stopped', 'stop reason -> stopped');
eqs(S::entryStatus(true, 'Machine Breakdown'), 'Stopped', 'stop wins over end');

eqs(S::planningStatus(false,false), 'Planned', 'planning default');
eqs(S::planningStatus(true,false), 'In Process', 'planning started');
eqs(S::planningStatus(true,true), 'Completed', 'planning completed');

eqs(S::indentStatus([], false, false), 'Open', 'nothing -> open');
eqs(S::indentStatus([], false, true), 'Planned', 'planned but not started');
eqs(S::indentStatus([false,false], true, true), 'In Process', 'one step running');
eqs(S::indentStatus([true,false], true, true), 'In Process', 'not all done -> in process');
eqs(S::indentStatus([true,true], true, true), 'Completed', 'all steps done -> completed');

eqs(S::advance('Open','Planned'), 'Planned', 'advance forward');
eqs(S::advance('In Process','Planned'), 'In Process', 'never go backwards');
eqs(S::advance('Completed','Closed'), 'Closed', 'completed -> closed');

echo "ww_statusflow: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
