<?php
// mirror SalesBaseCurrency::nativeCurrency logic
const RATEABLE=['USD','UGX','SSP'];
function nativeCurrency($base,$basis){
  if(strtolower(trim($basis))==='native') return in_array($base,RATEABLE,true)?$base:'UGX';
  return $base==='USD'?'UGX':$base;   // converted
}
$ok=0;$fail=0; function ck($n,$c){global $ok,$fail;echo ($c?"PASS ":"FAIL ").$n."\n";$c?$ok++:$fail++;}
ck('USD base + converted (BEFORE) => local UGX  [the bug]', nativeCurrency('USD','')==='UGX');
ck('USD base + native (AFTER fix) => local USD  [payments book USD]', nativeCurrency('USD','native')==='USD');
ck('UGX base + native => local UGX (unaffected)', nativeCurrency('UGX','native')==='UGX');
echo "\nRESULT: $ok passed, $fail failed\n"; exit($fail?1:0);
