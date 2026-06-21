<?php
/**
 * Framework-free QA for Supermarket Search — synonyms, narrowing, ranking, analytics.
 * Run: php qa/supermarket_search.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$base = __DIR__ . '/../app/Services/Bot/Search/';
require $base . 'SearchSynonyms.php';
require $base . 'SearchNarrowing.php';
require $base . 'SearchRanker.php';
require $base . 'SearchAnalyticsCalc.php';

use App\Services\Bot\Search\SearchSynonyms as SYN;
use App\Services\Bot\Search\SearchNarrowing as NAR;
use App\Services\Bot\Search\SearchRanker as RANK;
use App\Services\Bot\Search\SearchAnalyticsCalc as CALC;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

/* ------------------------------------------------------------ synonyms */
ok('chawal -> rice',         in_array('rice', SYN::expand('chawal'), true));
ok('need sukari -> sugar',   in_array('sugar', SYN::expand('need sukari'), true));
ok('cooking oil -> tel',     in_array('tel', SYN::expand('cooking oil'), true));
ok('rice -> chawal',         in_array('chawal', SYN::expand('rice'), true));
ok('unrelated stays',        SYN::expand('shampoo') === ['shampoo']);
$mei = SYN::meiliSynonyms();
ok('meili rice has chawal',  in_array('chawal', $mei['rice'] ?? [], true));
ok('meili sukari has sugar', in_array('sugar', $mei['sukari'] ?? [], true));

/* ----------------------------------------------------------- narrowing */
ok('21 -> narrow',     NAR::shouldNarrow(21));
ok('20 -> no narrow',  ! NAR::shouldNarrow(20));
$rows = [];
foreach (['Basmati','Basmati','Daily','Daily','Premium','Budget'] as $i => $cat) {
    $rows[] = ['id' => $i + 1, 'name' => "Rice $i", 'category' => $cat, 'price' => 5000 + $i * 1000, 'stock' => 5];
}
$f = NAR::facets($rows, 4);
ok('facets picks category', $f && $f['dimension'] === 'category');
ok('facets gives options',  $f && count($f['options']) >= 2 && in_array('Basmati', $f['options'], true));
ok('facets question',       $f && $f['question'] === 'Which type?');
// price fallback when no category/brand
$pr = [['id'=>1,'name'=>'A','price'=>1000,'stock'=>3],['id'=>2,'name'=>'B','price'=>9000,'stock'=>3]];
$pf = NAR::facets($pr);
ok('price tier fallback', $pf && $pf['dimension'] === 'price' && in_array('Budget', $pf['options'], true));

/* ------------------------------------------------------------- ranker */
$cands = [
    ['id'=>1,'name'=>'Sugar 1kg','price'=>4000,'stock'=>0,'popularity'=>50],   // OOS -> dropped
    ['id'=>2,'name'=>'Sugar 2kg','price'=>7500,'stock'=>10,'popularity'=>5],
    ['id'=>3,'name'=>'Sugar','price'=>4200,'stock'=>3,'popularity'=>1],         // exact
    ['id'=>4,'name'=>'Brown Sugar','price'=>6000,'stock'=>8,'popularity'=>40],
];
$ranked = RANK::rank($cands, ['query'=>'sugar','history'=>[4=>2],'popularity'=>[]]);
ok('OOS dropped',          ! in_array(1, array_column($ranked, 'id'), true));
ok('exact match first',    ($ranked[0]['id'] ?? 0) === 3);
ok('all in stock',         count(array_filter($ranked, fn($r)=>$r['stock']>0)) === count($ranked));
// history outranks popularity for non-exact
$h = RANK::rank([
    ['id'=>10,'name'=>'Blue Band','price'=>5000,'stock'=>5,'popularity'=>99],
    ['id'=>11,'name'=>'Prestige','price'=>5000,'stock'=>5,'popularity'=>1],
], ['query'=>'margarine','history'=>[11=>3],'popularity'=>[]]);
ok('history beats popularity', ($h[0]['id'] ?? 0) === 11);

/* ----------------------------------------------------------- analytics */
ok('conversion rate', CALC::conversionRate(100, 35) === 35.0);
ok('zero-result rate', CALC::zeroResultRate(50, 5) === 10.0);
ok('div-by-zero safe', CALC::conversionRate(0, 0) === 0.0);
$sum = CALC::summarize([
    ['type'=>'search'],['type'=>'search'],['type'=>'search'],['type'=>'search'],
    ['type'=>'zero_result'],['type'=>'click'],['type'=>'add'],
]);
ok('summarize searches', $sum['searches'] === 4 && $sum['adds'] === 1);
ok('summarize conversion', $sum['conversion_rate'] === 25.0);

echo "\n=== supermarket_search: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
