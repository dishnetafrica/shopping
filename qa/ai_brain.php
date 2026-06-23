<?php
/** qa/ai_brain.php — mirrors AiBrain::detectSignals + the bot-mode resolution incl. native 'ai'. */

function detectSignals(string $text): array {
    $t = mb_strtolower($text);
    $has = fn(array $w) => (bool) array_filter($w, fn($x) => str_contains($t, $x));
    $out = [];
    if ($has(['price','how much','cost','rate','quote','quotation','per carton','wholesale','bulk','order','buy','supply','need','interested','send me','do you have','available','stock'])) $out[]='lead';
    if ($has(['distributor','reseller','dealer','agent','stockist','become a'])) $out[]='distributor';
    if ($has(['paid','payment','sent money','mobile money','momo','deposit','transferred','receipt'])) $out[]='payment';
    if ($has(['complaint','problem','not working','damaged','wrong','refund','poor quality','defective','issue'])) $out[]='complaint';
    if ($has(['confirm','confirmed','go ahead','place the order','deliver to','delivery to'])) $out[]='order';
    return $out;
}
// brain resolution (ProcessIncomingMessage): ai/n8n return first, else inbuilt for auto|inbuilt, else off
function brain(string $m): string {
    if ($m==='ai') return 'ai';
    if ($m==='n8n') return 'n8n';
    if (in_array($m,['auto','inbuilt'],true)) return 'inbuilt';
    return 'off';
}
function toggle(string $cur, string $req): string {
    $mode = in_array($req,['auto','off'],true) ? $req : 'auto';
    if (in_array($cur,['n8n','ai'],true) && $mode==='auto') $mode = $cur;
    return $mode;
}
function pick(array $routing, array $priority): array {
    foreach ($priority as $r) { if (!empty($routing[$r])) return $routing[$r]; }
    return [];
}

$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== ai_brain QA ===\n";

check('greeting → no signal',          detectSignals('hello, good morning')===[]);
check('price → lead',                  detectSignals('how much per carton?')===['lead']);
check('distributor enquiry',           in_array('distributor', detectSignals('I want to become a distributor')));
check('payment mention',               in_array('payment', detectSignals('I sent money via mobile money')));
check('complaint',                     in_array('complaint', detectSignals('the tissue is damaged, I want a refund')));
check('order/delivery intent',         in_array('order', detectSignals('please deliver to Mbarara, go ahead')));
check('general Q → no signal',         detectSignals('what does GSM mean?')===[]);

check('ai mode → ai brain',            brain('ai')==='ai');
check('n8n mode → n8n brain',          brain('n8n')==='n8n');
check('auto → inbuilt',                brain('auto')==='inbuilt');
check('off → off',                     brain('off')==='off');

check('toggle: ai + on stays ai',      toggle('ai','auto')==='ai');
check('toggle: ai + off → off',        toggle('ai','off')==='off');
check('toggle: n8n + on stays n8n',    toggle('n8n','auto')==='n8n');
check('toggle: auto + on → auto',      toggle('auto','auto')==='auto');

check('alert role priority dispatch',  pick(['sales'=>['1'],'dispatch'=>['2']],['dispatch','sales'])===['2']);
check('alert role fallback sales',     pick(['sales'=>['1']],['dispatch','sales','management'])===['1']);

echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
