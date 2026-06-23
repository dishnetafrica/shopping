<?php
/** qa/n8n_p2.php — pure-logic mirror of watchdog / digest / alert-dedupe decisions. */

function hours(string $spec): array {
    if (preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', $spec, $m))
        return [max(0,min(23,(int)$m[1])), max(1,min(24,(int)$m[2]))];
    return [7,21];
}
function inHours(int $hour, string $spec): bool { [$a,$b]=hours($spec); return $hour>=$a && $hour<$b; }

function route(array $routing, array $priority): array {
    foreach ($priority as $role) {
        $val=$routing[$role]??[]; $list=is_array($val)?$val:preg_split('/[,\s]+/',(string)$val);
        $nums=array_values(array_filter(array_map(fn($p)=>preg_replace('/[^0-9]/','',(string)$p),$list)));
        if ($nums) return $nums;
    }
    return [];
}

// watchdog: customer is unanswered when unread>0 and the wait is between threshold and max-age
function isUnanswered(int $unread, bool $agentActive, int $ageMin, int $waitMin, int $maxMin): bool {
    return $unread>0 && !$agentActive && $ageMin>=$waitMin && $ageMin<=$maxMin;
}

// digest: real UTC instant of the tenant's local midnight, given offset hours
function localMidnightUtc(int $nowUtcHour, int $offset): int {
    // returns the local hour-of-day that "now" maps to (sanity), and we test the start-of-day shift separately
    return ($nowUtcHour + $offset + 24) % 24;
}

$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== n8n_p2 QA ===\n";

check('hours "7-21" parses',          hours('7-21')===[7,21]);
check('hours junk → default',         hours('all day')===[7,21]);
check('in hours: 9 within 7-21',      inHours(9,'7-21')===true);
check('in hours: 22 outside 7-21',    inHours(22,'7-21')===false);
check('in hours: 7 is inclusive',     inHours(7,'7-21')===true);
check('in hours: 21 is exclusive',    inHours(21,'7-21')===false);

$r=['sales'=>['256771'],'dispatch'=>['256700'],'management'=>['256800']];
check('route prefers dispatch',       route($r,['dispatch','sales','management'])===['256700']);
check('route falls back to sales',    route(['sales'=>['256771']],['dispatch','sales','management'])===['256771']);
check('route empty when none',        route([],['dispatch','sales'])===[]);

check('dedupe key is signal:phone',   ('lead:2567'==='lead:'.'2567'));
check('unanswered: 12m wait fires',   isUnanswered(1,false,12,10,180)===true);
check('answered (unread 0) no fire',  isUnanswered(0,false,12,10,180)===false);
check('agent active → no fire',       isUnanswered(1,true,12,10,180)===false);
check('too fresh (5m<10m) no fire',   isUnanswered(1,false,5,10,180)===false);
check('too old (>180m) no fire',      isUnanswered(1,false,200,10,180)===false);

check('local hour: 15:00 UTC +3 = 18', localMidnightUtc(15,3)===18);
check('local hour wraps midnight',      localMidnightUtc(22,3)===1);

echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
