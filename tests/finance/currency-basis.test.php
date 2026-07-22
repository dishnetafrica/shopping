<?php
// mirror SalesBaseCurrency::nativeCurrency logic
const RATEABLE=['USD','UGX','SSP'];
function nativeCurrency($base,$basis){
  if(strtolower(trim($basis))==='native') return in_array($base,RATEABLE,true)?$base:'UGX';
  return $base==='USD'?'USD':$base;   // SAFEGUARD: USD base is native (prices ARE USD); UGX base stays UGX
}
$ok=0;$fail=0; function ck($n,$c){global $ok,$fail;echo ($c?"PASS ":"FAIL ").$n."\n";$c?$ok++:$fail++;}
ck('USD base + converted => local USD (safeguard: never divides a $ order to $0.27)', nativeCurrency('USD','')==='USD');
ck('USD base + native => local USD (payments book USD)', nativeCurrency('USD','native')==='USD');
ck('UGX base + converted => local UGX (unaffected)', nativeCurrency('UGX','')==='UGX');
ck('UGX base + native => local UGX (unaffected)', nativeCurrency('UGX','native')==='UGX');
ck('SSP base + converted => local SSP (unaffected)', nativeCurrency('SSP','')==='SSP');
echo "\nRESULT: $ok passed, $fail failed\n"; exit($fail?1:0);
