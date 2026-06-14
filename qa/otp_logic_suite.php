<?php
require dirname(__DIR__).'/app/Services/Auth/OtpService.php';
use App\Services\Auth\OtpService;
$pass=0;$fail=0; function ck($l,$ok){global $pass,$fail; if($ok){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}}
$now=1000; $ttl=300; $max=5;
$mk=fn($code,$at,$att=0)=>['hash'=>hash('sha256',$code),'user_id'=>7,'attempts'=>$att,'at'=>$at];

echo "\n[verify decision]\n";
ck('correct code -> ok', OtpService::evaluate($mk('123456',$now),'123456',$now,$ttl,$max)['ok']===true);
ck('wrong code -> not ok, reason wrong', (function()use($mk,$now,$ttl,$max){$r=OtpService::evaluate($mk('123456',$now),'000000',$now,$ttl,$max);return $r['ok']===false&&$r['reason']==='wrong';})());
ck('no record -> not ok (used/expired)', OtpService::evaluate(null,'123456',$now,$ttl,$max)['reason']==='none');
ck('expired (>5min) -> not ok', OtpService::evaluate($mk('123456',$now),'123456',$now+$ttl+1,$ttl,$max)['reason']==='expired');
ck('not expired at exactly TTL', OtpService::evaluate($mk('123456',$now),'123456',$now+$ttl,$ttl,$max)['ok']===true);
ck('locked after MAX attempts even if correct', OtpService::evaluate($mk('123456',$now,5),'123456',$now,$ttl,$max)['reason']==='locked');
ck('format-insensitive code (spaces/dashes)', OtpService::evaluate($mk('123456',$now),'12 34-56',$now,$ttl,$max)['ok']===true);
ck('empty input -> wrong (never matches)', OtpService::evaluate($mk('123456',$now),'',$now,$ttl,$max)['ok']===false);
ck('constant-time path returns match only on equal hash', OtpService::evaluate($mk('999999',$now),'999999',$now,$ttl,$max)['ok']===true);

echo "\n[phone normalisation]\n";
ck('norm strips +, spaces, dashes', OtpService::norm('+256 700-123 456')==='256700123456');
ck('norm of empty -> empty', OtpService::norm('')==='');

echo "\nRESULT: PASS $pass  FAIL $fail\n";
exit($fail?1:0);
