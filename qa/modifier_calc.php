<?php
require __DIR__.'/../app/Support/ModifierCalc.php';
use App\Support\ModifierCalc;

$pass=0;$fail=0;
function chk($label,$got,$exp){global $pass,$fail; $ok=($got===$exp); echo ($ok?"PASS":"FAIL")." | $label  got=".json_encode($got)." exp=".json_encode($exp)."\n"; $ok?$pass++:$fail++;}

// Butter Chicken $9.50 + included Naan (delta 0) => unchanged
chk('included accompaniment is free', ModifierCalc::unitPrice(9.50, [['price_delta'=>0]]), 9.50);
// + premium Butter Naan surcharge 0.50
chk('premium accompaniment surcharge', ModifierCalc::unitPrice(9.50, [['price_delta'=>0.50]]), 10.00);
// no modifiers
chk('no modifiers', ModifierCalc::unitPrice(7.00, []), 7.00);
// two selections
chk('multiple deltas', ModifierCalc::unitPrice(8.00, [['price_delta'=>0],['price_delta'=>1.50]]), 9.50);

$acc=['id'=>1,'name'=>'accompaniment','required'=>true,'min_select'=>1,'max_select'=>1];
chk('required not chosen -> error', ModifierCalc::validate([$acc], [1=>0]), ['Please choose your accompaniment.']);
chk('required chosen -> valid',     ModifierCalc::validate([$acc], [1=>1]), []);
chk('too many for single-select',   ModifierCalc::validate([$acc], [1=>2]), ['You can pick at most 1 for accompaniment.']);

$extras=['id'=>2,'name'=>'extras','required'=>false,'min_select'=>0,'max_select'=>5];
chk('optional none -> valid',       ModifierCalc::validate([$extras], [2=>0]), []);
chk('optional within max -> valid', ModifierCalc::validate([$extras], [2=>3]), []);
chk('optional over max -> error',   ModifierCalc::validate([$extras], [2=>6]), ['You can pick at most 5 for extras.']);

echo "\n".($fail===0?"ALL GREEN":"FAILED").": {$pass} passed, {$fail} failed.\n";
exit($fail?1:0);
