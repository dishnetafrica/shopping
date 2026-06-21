<?php
/**
 * qa/product_order.php — pure-logic QA for product display ordering.
 * Mirrors seller.html pmFiltered() sort + pmPin() top/bottom math, and the
 * server sort (display_order DESC, name ASC). Run: php qa/product_order.php
 */
$pass=0;$fail=0;
function ok($l,$g,$w){global $pass,$fail;$a=json_encode($g);$b=json_encode($w);
  if($a===$b){$pass++;echo "  ok  $l\n";}else{$fail++;echo "FAIL  $l\n        got : $a\n        want: $b\n";}}

/* sort comparator: higher display_order first, then name A->Z */
function order_list(array $items): array {
    usort($items, function($a,$b){
        $d = ($b['order'] ?? 0) <=> ($a['order'] ?? 0);
        return $d !== 0 ? $d : strcmp((string)$a['name'], (string)$b['name']);
    });
    return array_map(fn($p)=>$p['name'], $items);
}
/* pin math */
function pin_value(array $items, int $mode): int {
    $orders = array_map(fn($p)=>(int)($p['order']??0), $items);
    if ($mode === 1)  return (count($orders)?max(max($orders),0):0) + 1;   // top = max(>=0)+1
    if ($mode === -1) return (count($orders)?min(min($orders),0):0) - 1;   // bottom = min(<=0)-1
    return 0;                                                              // reset
}

$cat=[['name'=>'Banana','order'=>0],['name'=>'Apple','order'=>0],['name'=>'Mango','order'=>0]];

echo "== default (all 0) stays alphabetical ==\n";
ok('alpha when no pins', order_list($cat), ['Apple','Banana','Mango']);

echo "== pin to top shows first ==\n";
$top=pin_value($cat,1); // 1
$c2=$cat; foreach($c2 as &$p){if($p['name']==='Mango')$p['order']=$top;} unset($p);
ok('top value is max+1', $top, 1);
ok('pinned item leads, rest alpha', order_list($c2), ['Mango','Apple','Banana']);

echo "== push to bottom shows last ==\n";
$bot=pin_value($c2,-1); // -1
$c3=$c2; foreach($c3 as &$p){if($p['name']==='Apple')$p['order']=$bot;} unset($p);
ok('bottom value is min-1', $bot, -1);
ok('pushed item trails', order_list($c3), ['Mango','Banana','Apple']);

echo "== second pin-to-top stacks above the first ==\n";
$top2=pin_value($c3,1); // max is 1 -> 2
ok('next top is higher', $top2, 2);
$c4=$c3; foreach($c4 as &$p){if($p['name']==='Banana')$p['order']=$top2;} unset($p);
ok('newest top is first', order_list($c4), ['Banana','Mango','Apple']);

echo "== reset returns to alphabetical slot ==\n";
ok('reset value is 0', pin_value($c4,0), 0);
$c5=$c4; foreach($c5 as &$p){if($p['name']==='Banana')$p['order']=0;} unset($p);
ok('after reset, Banana back in middle', order_list($c5), ['Mango','Banana','Apple']);

echo "\n--------------------------------------------------\n";
echo "product_order: {$pass} passed, {$fail} failed\n";
exit($fail===0?0:1);
