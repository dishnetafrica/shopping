<?php
/**
 * Regression suite for the four field-test failures (pure, no DB).
 *
 *  F1  rice -> "D.rice Samosa"      : recommendation candidates must be category-coherent
 *  F2  delivery/pin -> products     : delivery/location classify correctly (the bug was stale
 *                                     context, not detection — locked here so detection can't drift)
 *  F3  fresh order picks stale rows : a quantity in a NEW order must not be read as a row pick
 *  F4  conflicted multi-line        : add+remove/correction in one message is flagged, not guessed
 */
foreach (['CatalogueMatcher','CategoryDictionary','GreetingDictionary','LocationDictionary','FaqDictionary','IntentClassifier','ClarificationFlow','MultiLineGuard','SalesAssistantBrain'] as $c) {
    require dirname(__DIR__).'/app/Services/Bot/'.$c.'.php';
}
use App\Services\Bot\IntentClassifier as IC;
use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ClarificationFlow;
use App\Services\Bot\MultiLineGuard as MLG;
use App\Services\Bot\SalesAssistantBrain as SA;

$PASS=0;$FAIL=0;
function ok($l,$c){global $PASS,$FAIL; if($c){$PASS++;echo "  PASS  $l\n";}else{$FAIL++;echo "  FAIL  $l\n";}}
function sec($t){echo "\n[$t]\n";}

$i=0;$mk=function($n,$p,$c)use(&$i){return ['id'=>++$i,'name'=>$n,'price'=>$p,'stock'=>20,'category'=>$c,'keywords'=>''];};
$cat=[
 $mk('Kolam Rice 5kg',30000,'Rice'), $mk('India Gate Rice 5kg',45000,'Rice'), $mk('Ravi Rice 5kg',25000,'Rice'),
 $mk('D.rice Samosa',3000,'Snacks'), $mk('Pin Pop Black Cherry',1500,'Snacks'),
 $mk('Delivery Note',2000,'Stationery'), $mk('Delivery Book Butterfly',2500,'Stationery'),
 $mk('Coca Cola 500ml',2000,'Drinks'), $mk('Sugar 1kg',4500,'Grocery'),
];
$tok = IC::tokenSetFromProducts($cat);
$names = fn($a)=>array_map(fn($p)=>$p['name'],$a);

sec('F1 — recommendation candidates are category-coherent (no rice -> samosa)');
$hits = (new CatalogueMatcher())->search('rice',$cat);
$co   = SA::coherentCandidates('rice',$hits);
ok('samosa excluded from rice candidates', ! in_array('D.rice Samosa', $names($co), true));
ok('real rices retained',                  in_array('Kolam Rice 5kg', $names($co), true) && in_array('Ravi Rice 5kg', $names($co), true));
$pick = SA::pickRecommendation('rice',$co,null,[]);            // value tier
ok('value tier picks a real rice, not samosa', $pick['product']['category'] === 'Rice');

sec('F2 — delivery / location classify correctly (detection lock)');
ok('"How much delivery to Ntinda?" -> business', IC::classify('How much delivery to Ntinda?', $tok) === IC::BUSINESS);
ok('delivery blob -> business',                  IC::classify("How much delivery to Ntinda?\nCan I send location pin?\nCheckout", $tok) === IC::BUSINESS);
ok('"Can I send location pin?" is location-help', IC::isLocationHelp('can i send location pin'));
ok('order blob -> shopping',                     IC::classify("5 Coke\n10 Rice\n2 Sugar", $tok) === IC::SHOPPING);

sec('F3 — a fresh order is never read as a pick of stale rows');
$cf = new ClarificationFlow();
$flat=[]; for($n=1;$n<=10;$n++) $flat[]=['n'=>$n,'product_id'=>100+$n,'name'=>"Stale Item $n",'price'=>1000,'qty'=>1];
ok('"5 Coke 10 Rice 2 Sugar" -> no pick',  $cf->resolveSelection('5 Coke 10 Rice 2 Sugar',$flat) === []);
ok('"add 10 rice" -> no pick',             $cf->resolveSelection('add 10 rice',$flat) === []);
ok('"1" -> picks row 1',                   count($cf->resolveSelection('1',$flat)) === 1);
ok('"1 and 3" -> picks two rows',          count($cf->resolveSelection('1 and 3',$flat)) === 2);
ok('"2, 4" -> picks two rows',             count($cf->resolveSelection('2, 4',$flat)) === 2);
ok('"option 5" -> picks row 5',            count($cf->resolveSelection('option 5',$flat)) === 1);

sec('F4 — conflicted multi-line is flagged, plain orders pass through');
ok('add+remove+correction -> conflicted',  MLG::isConflicted("Kolam rice\n5kg\nAdd it\nActually remove it\nAdd brown rice instead\nCheckout"));
ok('add + remove -> conflicted',           MLG::isConflicted("add 2 milk\nremove the bread"));
ok('plain wholesale order -> NOT conflicted', ! MLG::isConflicted("5 Coke\n10 Rice\n2 Sugar"));
ok('removes only -> NOT conflicted',        ! MLG::isConflicted("remove milk\nremove bread"));
ok('single line -> NOT conflicted',         ! MLG::isConflicted("Need rice"));
ok('single-line multi-item -> NOT conflicted', ! MLG::isConflicted("2 rice and 1 oil"));

echo "\n========= RESULT =========\n";
echo "PASS $PASS  FAIL $FAIL\n";
exit($FAIL===0?0:1);
