<?php
/**
 * qa/enrichment.php — framework-free tests for ProductEnrichmentService pure logic.
 * Covers: vocabulary validation, out-of-vocab rejection, confidence gating (apply/review/skip),
 * plan() with an injected fake classifier, and dry-run safety (plan writes nothing).
 * The live model call (classifyOne) is NOT exercised here — verify it on staging with a key.
 */
require __DIR__ . '/../app/Services/Enrichment/ProductEnrichmentService.php';

use App\Services\Enrichment\ProductEnrichmentService as E;

$P = 0; $F = 0;
function ok(string $label, bool $cond): void { global $P, $F; $cond ? $P++ : $F++; echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n"; }
function sec(string $s): void { echo "\n[$s]\n"; }

sec('validate() — in-vocab and out-of-vocab');
ok('cooking_oil stays cooking_oil',        E::validate(['product_type'=>'cooking_oil','confidence'=>0.9])['product_type'] === 'cooking_oil');
ok('normalizes "Skincare Oil" -> skincare_oil', E::validate(['product_type'=>'Skincare Oil','confidence'=>0.9])['product_type'] === 'skincare_oil');
ok('normalizes hyphen "spice-mix" -> spice_mix', E::validate(['product_type'=>'spice-mix','confidence'=>0.8])['product_type'] === 'spice_mix');
ok('out-of-vocab "artisan_oil" -> other',  E::validate(['product_type'=>'artisan_oil','confidence'=>0.97])['product_type'] === 'other');
ok('out-of-vocab caps confidence <=0.40',  E::validate(['product_type'=>'artisan_oil','confidence'=>0.97])['confidence'] <= 0.40);
ok('in_vocab flag false for invented type', E::validate(['product_type'=>'artisan_oil','confidence'=>0.9])['in_vocab'] === false);
ok('garbage input -> other, 0 conf',       E::validate('not-an-array')['product_type'] === 'other');
ok('confidence clamped to [0,1]',          E::validate(['product_type'=>'rice','confidence'=>5])['confidence'] === 1.0);

sec('decision() — confidence gating');
ok('>=0.85 -> apply',          E::decision(['product_type'=>'rice','confidence'=>0.85]) === 'apply');
ok('0.84 -> review',           E::decision(['product_type'=>'rice','confidence'=>0.84]) === 'review');
ok('0.55 floor -> review',     E::decision(['product_type'=>'rice','confidence'=>0.55]) === 'review');
ok('0.54 -> skip',             E::decision(['product_type'=>'rice','confidence'=>0.54]) === 'skip');
ok('other -> skip regardless', E::decision(['product_type'=>'other','confidence'=>0.99]) === 'skip');

sec('systemPrompt() — vocabulary-constrained, JSON-only');
$sp = E::systemPrompt();
ok('lists cooking_oil in vocab', str_contains($sp, 'cooking_oil'));
ok('lists spice_mix in vocab',   str_contains($sp, 'spice_mix'));
ok('demands strict JSON',        stripos($sp, 'STRICT JSON') !== false);
ok('forbids inventing a type',   stripos($sp, 'Never invent') !== false);

sec('plan() — with an injected fake classifier (no network), dry-run safe');
$fake = function (string $name, string $cat) {
    $map = [
        'Fortune Sunflower Oil 1L' => ['product_type'=>'cooking_oil','confidence'=>0.96],
        'Bio-Oil 60ML'            => ['product_type'=>'skincare_oil','confidence'=>0.92],
        'Neem Oil 100ML'          => ['product_type'=>'cosmetic_oil','confidence'=>0.7],   // -> review
        'Kolam Rice 5KG'          => ['product_type'=>'rice','confidence'=>0.98],
        'Rice Crisps 35GMS'       => ['product_type'=>'snack','confidence'=>0.6],          // -> review
        'Shan Chicken 65'         => ['product_type'=>'spice_mix','confidence'=>0.88],
        'Mystery Thing'           => ['product_type'=>'gizmo','confidence'=>0.99],          // -> other -> skip
        'Network Failed Item'     => null,                                                  // -> unclassified
    ];
    return array_key_exists($name, $map) ? $map[$name] : ['product_type'=>'other','confidence'=>0.1];
};
$products = array_map(fn ($n, $i) => ['id'=>$i+1, 'name'=>$n, 'category'=>'', 'product_type'=>''], array_keys(array_flip([
    'Fortune Sunflower Oil 1L','Bio-Oil 60ML','Neem Oil 100ML','Kolam Rice 5KG',
    'Rice Crisps 35GMS','Shan Chicken 65','Mystery Thing','Network Failed Item',
])), range(0,7));
$svc = new E('test-key');                 // key present so isEnabled() not needed for plan() w/ injected classifier
$plan = $svc->plan($products, $fake);
$by = [];
foreach ($plan['rows'] as $r) $by[$r['name']] = $r;

ok('Fortune Sunflower -> cooking_oil APPLY', $by['Fortune Sunflower Oil 1L']['decision'] === 'apply' && $by['Fortune Sunflower Oil 1L']['product_type'] === 'cooking_oil');
ok('Bio-Oil -> skincare_oil APPLY',          $by['Bio-Oil 60ML']['decision'] === 'apply' && $by['Bio-Oil 60ML']['product_type'] === 'skincare_oil');
ok('Neem Oil (0.70) -> REVIEW',              $by['Neem Oil 100ML']['decision'] === 'review');
ok('Kolam Rice -> rice APPLY',               $by['Kolam Rice 5KG']['decision'] === 'apply' && $by['Kolam Rice 5KG']['product_type'] === 'rice');
ok('Rice Crisps (0.60) -> snack REVIEW',     $by['Rice Crisps 35GMS']['decision'] === 'review' && $by['Rice Crisps 35GMS']['product_type'] === 'snack');
ok('Shan Chicken 65 -> spice_mix APPLY',     $by['Shan Chicken 65']['decision'] === 'apply' && $by['Shan Chicken 65']['product_type'] === 'spice_mix');
ok('invented "gizmo" -> other SKIP',         $by['Mystery Thing']['decision'] === 'skip');
ok('null classifier result -> unclassified', $by['Network Failed Item']['decision'] === 'unclassified');

$s = $plan['summary'];
ok('summary counts: apply=4',         ($s['apply'] ?? 0) === 4);
ok('summary counts: review=2',        ($s['review'] ?? 0) === 2);
ok('summary counts: skip>=1',         ($s['skip'] ?? 0) >= 1);
ok('summary counts: unclassified=1',  ($s['unclassified'] ?? 0) === 1);
ok('plan() returns rows for every product', count($plan['rows']) === count($products));

sec('parseBatch() — map batch model response to ids, validate, handle gaps');
$items = [
    ['id'=>101,'name'=>'Fortune Sunflower Oil 1L'],
    ['id'=>102,'name'=>'Bio-Oil 60ML'],
    ['id'=>103,'name'=>'Kolam Rice 5KG'],
    ['id'=>104,'name'=>'Mystery Thing'],
];
$good = '{"results":[{"i":1,"product_type":"cooking_oil","confidence":0.95},{"i":2,"product_type":"skincare_oil","confidence":0.9},{"i":3,"product_type":"rice","confidence":0.97},{"i":4,"product_type":"gizmo","confidence":0.99}]}';
$pb = E::parseBatch($good, $items);
ok('batch maps i=1 -> id 101 cooking_oil', ($pb[101]['product_type'] ?? '') === 'cooking_oil');
ok('batch maps i=2 -> id 102 skincare_oil', ($pb[102]['product_type'] ?? '') === 'skincare_oil');
ok('batch out-of-vocab gizmo -> other',     ($pb[104]['product_type'] ?? '') === 'other');
$gap = '{"results":[{"i":1,"product_type":"cooking_oil","confidence":0.95},{"i":3,"product_type":"rice","confidence":0.9}]}';
$pb2 = E::parseBatch($gap, $items);
ok('omitted item stays null (unclassified)', $pb2[102] === null && $pb2[104] === null);
ok('present items still classified',         ($pb2[101]['product_type'] ?? '') === 'cooking_oil' && ($pb2[103]['product_type'] ?? '') === 'rice');
ok('bare JSON array (no results key) works', (E::parseBatch('[{"i":1,"product_type":"rice","confidence":0.9}]', $items)[101]['product_type'] ?? '') === 'rice');
ok('out-of-range index ignored safely',      E::parseBatch('{"results":[{"i":99,"product_type":"rice","confidence":0.9}]}', $items)[101] === null);
ok('garbage content -> all null',            count(array_filter(E::parseBatch('not json', $items))) === 0);
ok('fenced json ```json ... ``` parsed',     (E::parseBatch("```json\n{\"results\":[{\"i\":1,\"product_type\":\"cooking_oil\",\"confidence\":0.9}]}\n```", $items)[101]['product_type'] ?? '') === 'cooking_oil');

sec('systemPromptBatch() — vocab + one-entry-per-number JSON');
$spb = E::systemPromptBatch();
ok('batch prompt lists vocab',        str_contains($spb, 'cooking_oil') && str_contains($spb, 'spice_mix'));
ok('batch prompt demands results[]',  str_contains($spb, '"results"'));
ok('batch prompt: strict JSON only',  stripos($spb, 'STRICT JSON') !== false);

sec('looksMeaningless() — bare codes/numbers carry no product signal');
ok('"30056640" is meaningless',        E::looksMeaningless('30056640') === true);
ok('"300ML" alone is meaningless',      E::looksMeaningless('300ML') === true);
ok('"2PIN Plug" is classifiable',       E::looksMeaningless('2PIN Plug') === false);
ok('"200 Men Perfume" classifiable',    E::looksMeaningless('200 Men Perfume 100ML') === false);
ok('"Kimbo Oil 500G" classifiable',     E::looksMeaningless('Kimbo Oil 500G') === false);
// parseBatch must force a meaningless name to other even if the model was confident
$codeItems = [['id'=>901,'name'=>'30056640'],['id'=>902,'name'=>'Kolam Rice 5KG']];
$codeResp = '{"results":[{"i":1,"product_type":"cosmetic_oil","confidence":0.9},{"i":2,"product_type":"rice","confidence":0.97}]}';
$cpb = E::parseBatch($codeResp, $codeItems);
ok('code-name forced to other despite 0.9', ($cpb[901]['product_type'] ?? '') === 'other');
ok('code-name decision is skip',            E::decision($cpb[901]) === 'skip');
ok('real name beside it still classified',  ($cpb[902]['product_type'] ?? '') === 'rice');

echo "\n========= RESULT =========\n";
echo "PASS $P  FAIL $F\n";
exit($F === 0 ? 0 : 1);
