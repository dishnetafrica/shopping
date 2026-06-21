<?php
// Weight surfacing in the order parser: weight_grams attached only for real weight units,
// qty/query never disturbed (additive). Pure.
require __DIR__ . '/../app/Services/Bot/BulkOrderParser.php';
use App\Services\Bot\BulkOrderParser as B;

$pass=0;$fail=0;
function ok($c,$l){global $pass,$fail; if($c){$pass++;}else{$fail++;echo "  FAIL $l\n";}}

echo "=== weight units → weight_grams attached ===\n";
$W = [
  ['750g black pepper kaju', 750, 'black pepper kaju'],
  ['1kg sev',               1000, 'sev'],
  ['2 kg ghathiya',         2000, 'ghathiya'],
  ['sev 1 kg',              1000, 'sev'],
  ['need 1kg paneer',       1000, 'paneer'],
  ['500gm mavo',             500, 'mavo'],
  ['250 grams kaju',         250, 'kaju'],
];
foreach ($W as [$txt,$g,$q]) {
  $l = B::parseLine($txt);
  ok($l && ($l['weight_grams'] ?? null) === $g, "$txt → weight_grams=$g");
  ok($l && $l['query'] === $q, "$txt → query='$q'");
}

echo "=== decimal kg weights ===\n";
foreach ([['1.5kg fafda',1500,'fafda'],['fafda 1.5kg',1500,'fafda'],['0.75kg sev',750,'sev'],['2.5 kg ghathiya',2500,'ghathiya']] as [$txt,$g,$q]) {
  $l = B::parseLine($txt);
  ok($l && ($l['weight_grams'] ?? null) === $g, "$txt → weight_grams=$g");
  ok($l && $l['query'] === $q, "$txt → query='$q'");
}

echo "=== NON-weight units / bare numbers → NO weight_grams ===\n";
foreach (['2 packet panipuri','kachori 2','300 fafda','2-panipuri','5 plate sev'] as $txt) {
  $l = B::parseLine($txt);
  ok($l && !isset($l['weight_grams']), "$txt → no weight_grams (count line)");
}

echo "=== qty/query preserved exactly (additive) ===\n";
$l = B::parseLine('2 packet panipuri'); ok($l['qty']===2 && $l['query']==='panipuri', "count line intact");
$l = B::parseLine('300 fafda');         ok($l['qty']===300 && $l['query']==='fafda', "bare number intact");

echo "=== mixed multi-item message ===\n";
$r = B::parseAll('750g black pepper kaju, 2 packet sev, 1kg jalebi');
ok(count($r)===3, "3 lines");
ok(($r[0]['weight_grams']??null)===750 && !isset($r[1]['weight_grams']) && ($r[2]['weight_grams']??null)===1000, "weight/count/weight mix correct");

echo "\n".($fail===0?"ALL GREEN: $pass passed, 0 failed.\n":"$pass passed, $fail FAILED.\n");
if($fail) exit(1);
