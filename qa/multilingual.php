<?php
/** qa/multilingual.php — deterministic detection across languages (replies are LLM-handled). */
function has(string $t, array $w){$t=mb_strtolower($t);return (bool)array_filter($w,fn($x)=>str_contains($t,$x));}
$lead=['price','how much','bei','gharama','nunua','prix','combien','سعر','بكم'];
$pay =['paid','payment','lipa','nimelipa','payé','دفعت'];
$comp=['complaint','shida','tatizo','problème','مشكلة'];
$tot =['total','jumla','au total','المجموع'];
$quo =['quotation','quote','nukuu','devis','عرض سعر'];

$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== multilingual QA ===\n";
check('EN buying "how much per carton"', has('how much per carton?',$lead));
check('Swahili buying "bei gani"',       has('bei gani kwa carton?',$lead));
check('French buying "combien"',         has('combien pour 5 cartons?',$lead));
check('Arabic buying "بكم"',             has('بكم الكرتون؟',$lead));
check('Swahili payment "nimelipa"',      has('nimelipa kwa mobile money',$pay));
check('French payment "payé"',           has("j'ai payé",$pay));
check('Swahili complaint "tatizo"',      has('kuna tatizo na tishu',$comp));
check('Arabic complaint "مشكلة"',        has('عندي مشكلة',$comp));
check('Swahili total "jumla"',           has('jumla ni ngapi?',$tot));
check('French total "au total"',         has('combien au total',$tot));
check('French quote "devis"',            has('je veux un devis',$quo));
check('Arabic quote "عرض سعر"',          has('أريد عرض سعر',$quo));
check('greeting triggers nothing',       !has('habari yako',$lead) && !has('bonjour',$tot));
echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
