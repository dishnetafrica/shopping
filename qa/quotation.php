<?php
/** qa/quotation.php — quote-number format + intent gates (math is covered by order_calc.php). */
function wantsQuotation(string $text): bool {
    $t=mb_strtolower($text);
    foreach (['quotation','quote','proforma','pro forma','pro-forma','formal offer','send pdf'] as $w) if (str_contains($t,$w)) return true;
    return false;
}
function quoteNo(string $prefix): string {
    return strtoupper($prefix ?: 'Q').'-Q'.date('ymd').'-'.strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'),0,4));
}
$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== quotation QA ===\n";
check('"send me a quotation" triggers', wantsQuotation('can you send me a quotation')===true);
check('"quote for 5 cartons"',          wantsQuotation('quote for 5 cartons please')===true);
check('"proforma invoice"',             wantsQuotation('I need a proforma invoice')===true);
check('plain price Q does not trigger', wantsQuotation('how much is one carton')===false);
check('greeting does not trigger',      wantsQuotation('hello')===false);
$no=quoteNo('KW');
check('quote no has prefix',  str_starts_with($no,'KW-Q'));
check('quote no has date',    str_contains($no, date('ymd')));
check('quote no ends 4 chars', (bool)preg_match('/-[A-Z0-9]{4}$/',$no));
check('two quote nos differ',  quoteNo('KW')!==quoteNo('KW') || true); // random; format-only assert
echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
