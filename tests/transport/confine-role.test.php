<?php
const ALLOW=['/transport','/papi/transport-get','/papi/transport-sync','/panel/logout','/logout','/app/login','/login'];
const ALLOW_PREFIX=['/panel/shared/','/build/','/storage/','/vendor/','/livewire/','/up'];
function allowed(string $path):bool{
  $path='/'.ltrim($path,'/');
  if(in_array($path,ALLOW,true))return true;
  foreach(ALLOW_PREFIX as $pre){ if(str_starts_with($path,$pre))return true; }
  return false;
}
$ok=0;$fail=0; function ck($n,$c){global $ok,$fail;echo ($c?"PASS ":"FAIL ").$n."\n";$c?$ok++:$fail++;}
// transport user CAN reach:
ck('/transport allowed', allowed('/transport'));
ck('/papi/transport-sync allowed', allowed('/papi/transport-sync'));
ck('/papi/transport-get allowed', allowed('/papi/transport-get'));
ck('/panel/logout allowed', allowed('/panel/logout'));
ck('/panel/shared/ui.js allowed', allowed('/panel/shared/ui.js'));
// transport user CANNOT reach (the whole ERP):
ck('/panel BLOCKED', !allowed('/panel'));
ck('/panel/orders BLOCKED', !allowed('/panel/orders'));
ck('/panel/expenses BLOCKED', !allowed('/panel/expenses'));
ck('/v3 BLOCKED', !allowed('/v3'));
ck('/v3/quotations BLOCKED', !allowed('/v3/quotations'));
ck('/papi/staff BLOCKED', !allowed('/papi/staff'));
ck('/papi/products BLOCKED', !allowed('/papi/products'));
ck('/papi/delivery-rules BLOCKED', !allowed('/papi/delivery-rules'));
ck('/ (root) BLOCKED', !allowed('/'));
// must NOT allow a sneaky path that merely contains transport
ck('/papi/transport-sync-evil BLOCKED', !allowed('/papi/transport-sync-evil'));
echo "\nRESULT: $ok passed, $fail failed\n"; exit($fail?1:0);
