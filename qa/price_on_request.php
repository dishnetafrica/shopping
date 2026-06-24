<?php
/** qa/price_on_request.php — POA convention: price<=0 means "price on request". */
// storefront keep-filter mirror
function keep($p){ return $p['name']!=='' && ($p['price']>0 || $p['poa']); }
function poa($price){ return !($price>0); }
// OrderCalculator mirror: price<=0 => not totalled, flagged unmatched (team confirms)
function calcLine($price,$qty){ if($price<=0) return ['sum'=>null,'matched'=>false]; return ['sum'=>$price*$qty,'matched'=>true]; }
// bot catalogue line mirror
function catPrice($price){ return ($price>0)?(string)$price:'price on request'; }

$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== price_on_request QA ===\n";
check('blank price => poa true',  poa(0)===true);
check('positive price => poa false', poa(45000)===false);
check('poa product kept on storefront', keep(['name'=>'Toilet Jumbo','price'=>0,'poa'=>true])===true);
check('priced product kept',            keep(['name'=>'Toilet Paper','price'=>45000,'poa'=>false])===true);
check('nameless dropped',               keep(['name'=>'','price'=>0,'poa'=>true])===false);
check('poa not added to total',         calcLine(0,5)['sum']===null);
check('poa flagged for team',           calcLine(0,5)['matched']===false);
check('priced item totals normally',    calcLine(45000,2)['sum']===90000);
check('bot shows "price on request"',   catPrice(0)==='price on request');
check('bot shows number when priced',   catPrice(45000)==='45000');
echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n"; exit($fail===0?0:1);
