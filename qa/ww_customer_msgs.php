<?php
require __DIR__ . '/../app/Services/Winworld/CustomerMessages.php';
use App\Services\Winworld\CustomerMessages as M;
$pass=0;$fail=0;
function eqs($g,$w,$l){global $pass,$fail; if($g===$w)$pass++; else{$fail++; echo "  FAIL $l -> ".var_export($g,true)." != ".var_export($w,true)."\n";}}
function ok($c,$l){global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}

eqs(M::eventForStage('order_received'),'order_received','stage order_received');
eqs(M::eventForStage('order_indent'),'in_production','order_indent -> in_production');
eqs(M::eventForStage('delivery'),'out_for_delivery','delivery -> out_for_delivery');
eqs(M::eventForStage('credit_check'),null,'credit_check -> no customer msg');
eqs(count(M::events()),4,'4 events');

$ctx=['order_no'=>'SO0007','customer'=>'Acme','product'=>'LD Bags'];
$m=M::render('order_received',$ctx);
ok(strpos($m,'SO0007')!==false,'order_no in msg');
ok(strpos($m,'Acme')!==false,'customer in msg');
ok(strpos($m,'LD Bags')!==false,'product in msg');
ok(strpos($m,'{')===false,'no leftover placeholders');

$o=M::render('delivered',$ctx,'Custom {order_no} done');
eqs($o,'Custom SO0007 done','override template used');
eqs(M::render('nope',$ctx),null,'unknown event -> null');

echo "ww_customer_msgs: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
