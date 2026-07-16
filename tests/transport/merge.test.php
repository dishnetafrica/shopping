<?php
function mergeById(array $cur, array $inc): array {
  $by=[]; foreach($cur as $e){ if(is_array($e)&&isset($e['uid']))$by[(string)$e['uid']]=$e; }
  foreach($inc as $e){ if(!is_array($e)||!isset($e['uid']))continue; $uid=(string)$e['uid']; $eu=(int)($e['updatedAt']??0);
    if(!isset($by[$uid])||$eu>(int)($by[$uid]['updatedAt']??0))$by[$uid]=$e; }
  return $by;
}
function applyTombstones(array $byId, array $tomb): array {
  $out=[]; foreach($byId as $uid=>$e){ $td=(int)($tomb[$uid]??0); if($td&&$td>=(int)($e['updatedAt']??0))continue; $out[$uid]=$e; } return $out;
}
$ok=0;$fail=0; function ck($n,$c){global $ok,$fail;echo ($c?"PASS ":"FAIL ").$n."\n";$c?$ok++:$fail++;}

// server has v1 of A; client pushes newer v2 of A + new B
$cur=[['uid'=>'A','updatedAt'=>100,'v'=>1]];
$inc=[['uid'=>'A','updatedAt'=>200,'v'=>2],['uid'=>'B','updatedAt'=>150,'v'=>1]];
$m=mergeById($cur,$inc);
ck('newer A wins (v2)', $m['A']['v']===2);
ck('new B added', isset($m['B']));

// older incoming does NOT overwrite newer server
$cur=[['uid'=>'A','updatedAt'=>300,'v'=>3]];
$inc=[['uid'=>'A','updatedAt'=>200,'v'=>2]];
$m=mergeById($cur,$inc);
ck('stale incoming ignored (keeps v3)', $m['A']['v']===3);

// tombstone removes entity when newer/equal
$m=applyTombstones(['A'=>['uid'=>'A','updatedAt'=>200]], ['A'=>250]);
ck('tombstone (newer) deletes', !isset($m['A']));
$m=applyTombstones(['A'=>['uid'=>'A','updatedAt'=>300]], ['A'=>250]);
ck('tombstone (older) does NOT delete a newer edit', isset($m['A']));

echo "\nRESULT: $ok passed, $fail failed\n";
exit($fail?1:0);
