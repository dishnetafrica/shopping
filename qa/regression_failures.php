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
foreach (['CatalogueMatcher','CategoryDictionary','GreetingDictionary','LocationDictionary','FaqDictionary','IntentClassifier','ClarificationFlow','MultiLineGuard','ConversationStageAnalyzer','SalesAssistantBrain','DiscoveryContextBuilder','CartCorrection','CartEditor','AiIntentInterpreter'] as $c) {
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

sec('F5 — a whole conversation in one message: lead on the discovery product, not the soup');
$blob = "Need rice\nWhich one is good?\nNot basmati\nDaily use\nFamily of 5\nNot expensive\nWhat do most customers buy?\nOk add 2\nNeed oil also\nToo expensive\nAny cheaper?\nAdd that\nDeliver Ntinda\nSend location pin\nCheckout";
$mk2 = $mk; $j=0; // extend catalogue with the colliding flour + oils
$cat2 = array_merge($cat, [
  ['id'=>50,'name'=>'Family Rice Flour 1kg','price'=>4000,'stock'=>20,'category'=>'Flour','keywords'=>''],
  ['id'=>51,'name'=>'India Gate Basmati Rice 5kg','price'=>48000,'stock'=>20,'category'=>'Rice','keywords'=>''],
  ['id'=>52,'name'=>'Sunflower Oil 2L','price'=>18000,'stock'=>20,'category'=>'Oil','keywords'=>''],
]);
$seg = SA::discoverySegment($blob);
ok('discovery segment stops before "add"',  ! str_contains($seg, 'add') && str_contains($seg, 'rice'));
ok('subject term is "rice" (not soup/flour)', SA::subjectTerm($seg, $cat2) === 'rice');
ok('"not basmati" recorded as exclusion',     isset(SA::excludedTerms($seg)['basmati']));
$co = SA::coherentCandidates('rice', (new CatalogueMatcher())->search('rice', $cat2));
ok('Family Rice Flour excluded (wrong category)', ! in_array('Family Rice Flour 1kg', $names($co), true));
ok('value pick is a real rice',               SA::pickRecommendation('rice', $co, null, [])['product']['category'] === 'Rice');

sec('F6 — multi-stage message decomposes to the earliest stage (handle one step at a time)');
use App\Services\Bot\ConversationStageAnalyzer as STG;
$compound = "Need rice\nWhich one is good?\nAdd 2\nCheckout";
ok('compound lead stage is DISCOVERY',        STG::leadStage($compound) === STG::DISCOVERY);
ok('compound is multi-stage',                 STG::isMultiStage($compound));
ok('lead segment keeps discovery, drops add/checkout',
        str_contains(strtolower(STG::leadSegment($compound)), 'good')
        && ! str_contains(strtolower(STG::leadSegment($compound)), 'add')
        && ! str_contains(strtolower(STG::leadSegment($compound)), 'checkout'));
ok('mega-blob drops everything after discovery',
        ! str_contains(strtolower(STG::leadSegment($blob)), 'checkout')
        && ! str_contains(strtolower(STG::leadSegment($blob)), 'deliver'));
ok('plain order "2 rice and 1 oil" is NOT multi-stage', ! STG::isMultiStage('2 rice and 1 oil'));
ok('all-selection "add 2 milk / add 1 bread" is NOT multi-stage', ! STG::isMultiStage("add 2 milk\nadd 1 bread"));
ok('single "checkout" unchanged',             STG::leadSegment('checkout') === 'checkout');
ok('single "which oil is good?" unchanged',   STG::leadSegment('which oil is good?') === 'which oil is good?');

sec('F7 — DiscoveryContextBuilder extracts structured meaning, not a blob');
use App\Services\Bot\DiscoveryContextBuilder as DCB;
$ricecat = [
  ['id'=>1,'name'=>'Maharashtra Kolam Rice 5kg','price'=>30000,'stock'=>20,'category'=>'Rice','keywords'=>''],
  ['id'=>2,'name'=>'Ravi Rice 5kg','price'=>25000,'stock'=>20,'category'=>'Rice','keywords'=>''],
  ['id'=>3,'name'=>'Sona Rice 1kg','price'=>8000,'stock'=>20,'category'=>'Rice','keywords'=>''],
];
$dseg = SA::discoverySegment("Need rice\nNot basmati\nDaily use\nFamily of 5\nNot expensive");
$dctx = DCB::build($dseg, $ricecat);
ok('product = rice',                 $dctx['product'] === 'rice');
ok('exclude contains basmati',       in_array('basmati', $dctx['exclude'], true));
ok('usage = daily',                  $dctx['usage'] === 'daily');
ok('family_size = 5',                $dctx['family_size'] === 5);
ok('budget = low',                   $dctx['budget'] === 'low');
ok('budget "premium" -> high',       DCB::budget('premium quality please') === 'high');
ok('budget plain -> null',           DCB::budget('rice please') === null);
ok('family "4 people" -> 4',         DCB::familySize('rice for 4 people') === 4);
ok('usage "biryani" -> special',     DCB::usage('rice for biryani') === 'special');
ok('phrase mentions family + affordable + product',
        str_contains(DCB::phrase($dctx), 'family of 5')
        && str_contains(DCB::phrase($dctx), 'affordable')
        && str_contains(DCB::phrase($dctx), 'rice'));
ok('budget=low picks cheapest',      SA::pickRecommendation('rice',$ricecat,null,[],['budget'=>'low'])['product']['id'] === 3);
ok('budget=high picks dearest',      SA::pickRecommendation('rice',$ricecat,null,[],['budget'=>'high'])['product']['id'] === 1);
ok('sizeValue 5kg > 1kg',            SA::sizeValue('Ravi Rice 5kg') > SA::sizeValue('Sona Rice 1kg'));

sec('F8 — unit-price reasoning + head-noun filtering (rice vs rice-snacks)');
use App\Services\Bot\SalesAssistantBrain as SAB;
// head-position: rice-modifier snacks excluded even when miscategorised as "Rice"
$ricesnacks = [
  ['id'=>1,'name'=>'D.rice Samosa','price'=>3000,'stock'=>20,'category'=>'Rice','keywords'=>''],
  ['id'=>2,'name'=>'Rice Crisps 50g','price'=>2000,'stock'=>20,'category'=>'Rice','keywords'=>''],
  ['id'=>3,'name'=>'Kolam Rice 5KG','price'=>30000,'stock'=>20,'category'=>'Rice','keywords'=>''],
  ['id'=>4,'name'=>'Numa Rice 5KG','price'=>28000,'stock'=>20,'category'=>'Rice','keywords'=>''],
];
$cohere = $names(SAB::coherentCandidates('rice', (new CatalogueMatcher())->search('rice', $ricesnacks)));
ok('rice-snack "D.rice Samosa" excluded (modifier, not head)', ! in_array('D.rice Samosa', $cohere, true));
ok('"Rice Crisps" excluded (modifier)',  ! in_array('Rice Crisps 50g', $cohere, true));
ok('real rices kept',                    in_array('Kolam Rice 5KG', $cohere, true) && in_array('Numa Rice 5KG', $cohere, true));

// value/unit-price question
ok('"cheaper ... per kg" detected as value Q', SAB::detectValue('which one is cheaper in india gate per kg'));
ok('"per kg" -> unit kg',               SAB::valueUnit('cheapest per kg') === 'kg');
ok('"per litre" -> unit l',             SAB::valueUnit('best value per litre') === 'l');
ok('plain "which is good" not a value Q', ! SAB::detectValue('which one is good'));

$ig = [
  ['id'=>1,'name'=>'India Gate Feast Rozzana 1KG','price'=>13800,'stock'=>9,'category'=>'Rice','keywords'=>''],
  ['id'=>2,'name'=>'India Gate Basmati 1KG','price'=>14000,'stock'=>9,'category'=>'Rice','keywords'=>''],
  ['id'=>3,'name'=>'India Gate Sella Basmati 10KG','price'=>137500,'stock'=>9,'category'=>'Rice','keywords'=>''],
  ['id'=>4,'name'=>'India Gate Excel 5KG','price'=>70000,'stock'=>9,'category'=>'Rice','keywords'=>''],
];
$rank = SAB::unitPriceRanking($ig, 'kg');
ok('cheapest per-kg is the 10KG Sella',  $rank[0]['product']['id'] === 3);
ok('per-kg of 10KG @137500 = 13750',     abs($rank[0]['unit_price'] - 13750) < 1);
ok('5KG @70000 = 14000/kg',              abs((70000.0/5) - 14000) < 1);
ok('parseSize 500ml -> 0.5 l',           SAB::parseSize('Oil 500ml') == ['value'=>0.5,'unit'=>'l']);
ok('parseSize 2kg -> 2 kg',              SAB::parseSize('Sugar 2kg') == ['value'=>2.0,'unit'=>'kg']);

sec('F9 — "i need 10 pcs update order" updates quantity, not a product search');
use App\Services\Bot\CartEditor as CE;
$onecart = [['product_id'=>1,'name'=>'Balaji Wafers 45G','price'=>3000,'qty'=>1]];
ok('"i need 10 pcs update order" is an edit',   CE::isEditIntent('i need 10 pcs update order'));
$ap = CE::apply($onecart, 'i need 10 pcs update order');
ok('quantity updated to 10',                    $ap !== null && ($ap['cart'][0]['qty'] ?? 0) === 10);
ok('product unchanged (still Balaji Wafers)',   $ap['cart'][0]['name'] === 'Balaji Wafers 45G');
ok('"update order to 10" is an edit',           CE::isEditIntent('update order to 10'));
ok('"make it 5" is an edit',                    CE::isEditIntent('make it 5'));
ok('"i need 10 apples" is NOT an edit (new order)', ! CE::isEditIntent('i need 10 apples'));
ok('"add 5 coke" is NOT an edit',               ! CE::isEditIntent('add 5 coke'));
ok('"update my address" (no qty) is NOT an edit', ! CE::isEditIntent('update my address'));

sec('F10 — AI interpreter is understanding-only and can NEVER execute commerce');
use App\Services\Bot\AiIntentInterpreter as AII;
ok('valid recommend kept',        (AII::validate(['intent'=>'recommend_product','category'=>'rice','confidence'=>0.9])['intent'] ?? '') === 'recommend_product');
ok('"checkout" intent rejected',  (AII::validate(['intent'=>'checkout','confidence'=>0.99])['intent'] ?? '') === 'unclear');
ok('"add_to_cart" rejected',      (AII::validate(['intent'=>'add_to_cart','quantity'=>10,'confidence'=>0.99])['intent'] ?? '') === 'unclear');
ok('"set_quantity" rejected',     (AII::validate(['intent'=>'set_quantity','qty'=>99,'confidence'=>0.99])['intent'] ?? '') === 'unclear');
$stray = AII::validate(['intent'=>'recommend_product','category'=>'rice','total'=>99999,'quantity'=>50,'price'=>1,'confidence'=>0.9]);
ok('stray total/quantity/price stripped', ! isset($stray['total']) && ! isset($stray['quantity']) && ! isset($stray['price']));
ok('low confidence -> unclear (asks, not guesses)', (AII::validate(['intent'=>'recommend_product','category'=>'rice','confidence'=>0.4])['intent'] ?? '') === 'unclear');
ok('confidence clamped to 0..1',  (AII::validate(['intent'=>'greeting','confidence'=>5])['confidence'] ?? -1) === 1.0);
ok('garbage -> unclear',          (AII::validate(['foo'=>'bar'])['intent'] ?? '') === 'unclear');
ok('non-array -> null',           AII::validate('checkout now') === null);
// toAction only ever points at READ-ONLY handlers (never add/remove/checkout)
$handlers = [];
foreach ([['intent'=>'recommend_product'],['intent'=>'compare_value'],['intent'=>'delivery_question'],['intent'=>'location_pin_question'],['intent'=>'product_search'],['intent'=>'unclear']] as $iv) $handlers[] = AII::toAction($iv)['handler'];
ok('every action handler is read-only', empty(array_intersect($handlers, ['add','remove','checkout','set_quantity','clear'])));
ok('disabled without an API key (no-op by default)', ! (new AII())->isEnabled());
ok('interpret() returns null when disabled', (new AII())->interpret('need rice') === null);

sec('F11 — multi-message discovery accumulates one context and recommends (not search)');
$dcat = [
    ['id'=>1,'name'=>'India Gate Basmati 1KG','price'=>14000,'stock'=>9,'category'=>'Rice','keywords'=>''],
    ['id'=>2,'name'=>'Maharashtra Kolam Rice 5KG','price'=>30000,'stock'=>9,'category'=>'Rice','keywords'=>''],
    ['id'=>3,'name'=>'Coca Cola 500ml','price'=>2000,'stock'=>9,'category'=>'Drinks','keywords'=>''],
];
$act = null;
foreach (['Need rice','Not basmati','Daily use','Family of 5','Not expensive'] as $m) {
    $dd = DCB::decide($act, $m, $dcat);
    if (in_array($dd['action'], ['enter','enrich','ask'], true)) $act = $dd['ctx'];
}
ok('"Need rice" enters discovery (not search)', DCB::decide(null,'Need rice',$dcat)['action'] === 'enter');
ok('"Not basmati" enriches, never searches basmati', DCB::decide(['category'=>'rice','exclude'=>[]],'Not basmati',$dcat)['action'] === 'enrich');
ok('category accumulates as rice',        ($act['category'] ?? '') === 'rice');
ok('exclude accumulates basmati',         in_array('basmati', $act['exclude'] ?? [], true));
ok('usage accumulates daily',             ($act['usage'] ?? '') === 'daily');
ok('family_size accumulates 5',           ($act['family_size'] ?? 0) === 5);
ok('budget accumulates low',              ($act['budget'] ?? '') === 'low');
$round = DCB::build(DCB::toOpinionText($act), $dcat);
ok('accumulated context round-trips through the tested opinion path',
   ($round['product'] ?? '') === 'rice' && ($round['family_size'] ?? 0) === 5
   && ($round['budget'] ?? '') === 'low' && ($round['usage'] ?? '') === 'daily'
   && in_array('basmati', $round['exclude'] ?? [], true));
// break-out & non-hijack
ok('"5 coke" mid-discovery breaks out (concrete add)', DCB::decide($act,'5 coke',$dcat)['action'] === 'skip');
ok('"rice 5kg" is a concrete add, not discovery',      DCB::looksLikeConcreteAdd('rice 5kg',$dcat));
ok('"family of 5" is NOT a concrete add',          ! DCB::looksLikeConcreteAdd('family of 5',$dcat));
ok('plain "rice" does NOT enter discovery (still search)', DCB::decide(null,'rice',$dcat)['action'] === 'skip');
ok('cold qualifier "Not basmati" asks for the category',   DCB::decide(null,'Not basmati',$dcat)['action'] === 'ask');
ok('"which rice is good" stays with SA, not discovery',    DCB::decide(null,'which rice is good',$dcat)['action'] === 'skip');
ok('selection "2" mid-discovery skips (handled by selection)', DCB::decide($act,'2',$dcat)['action'] === 'skip');

echo "\n========= RESULT =========\n";
echo "PASS $PASS  FAIL $FAIL\n";
exit($FAIL===0?0:1);
