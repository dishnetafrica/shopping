<?php
/**
 * Human-shopkeeper layer regression suite (pure, no DB).
 *
 * Locks in:
 *   - intent detection: opinion / doubt / comparison, and NON-firing on orders,
 *     numbers, greetings, affirmations and bargaining (which belongs to FollowUp)
 *   - the hybrid recommendation policy: owner default -> best-seller -> best value
 *   - "X vs Y" / "compare X and Y" side parsing
 *   - FollowUp price-objection vocab ("too expensive" -> cheaper) WITHOUT flipping
 *     a genuine premium request ("expensive one" -> premium)
 */
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__).'/app/Services/Bot/ClarificationFlow.php';
require dirname(__DIR__).'/app/Services/Bot/FollowUp.php';
require dirname(__DIR__).'/app/Services/Bot/SalesAssistantBrain.php';
use App\Services\Bot\SalesAssistantBrain as SA;
use App\Services\Bot\FollowUp;

$PASS = 0; $FAIL = 0;
function ok($label, $cond){ global $PASS,$FAIL; if($cond){$PASS++; echo "  PASS  $label\n";} else {$FAIL++; echo "  FAIL  $label\n";} }
function sec($t){ echo "\n[$t]\n"; }

sec('Intent detection — opinion');
ok('which one is good?',           SA::detect('which one is good?') === SA::OPINION);
ok('what do you recommend?',       SA::detect('what do you recommend?') === SA::OPINION);
ok('recommend a good rice',        SA::detect('recommend a good rice') === SA::OPINION);
ok('which rice is good',           SA::detect('which rice is good') === SA::OPINION);
ok('most popular one?',            SA::detect('most popular one?') === SA::OPINION);
ok('any good?',                    SA::detect('any good?') === SA::OPINION);
ok('whats good',                   SA::detect('whats good') === SA::OPINION);

sec('Intent detection — popularity / fast-moving (Indian customer phrasings)');
ok('which one sells more?',        SA::detect('which one sells more?') === SA::OPINION);
ok('which one moves fast?',        SA::detect('which one moves fast?') === SA::OPINION);
ok('most people take which one?',  SA::detect('most people take which one?') === SA::OPINION);
ok('what is popular?',             SA::detect('what is popular?') === SA::OPINION);
ok('whats selling?',               SA::detect('whats selling?') === SA::OPINION);
ok('best seller?',                 SA::detect('best seller?') === SA::OPINION);
ok('what do people usually buy?',  SA::detect('what do people usually buy?') === SA::OPINION);
ok('which rice sells more -> term "rice"', SA::stripCues('which rice sells more') === 'rice');

sec('Intent detection — doubt');
ok('are you sure?',                SA::detect('are you sure?') === SA::DOUBT);
ok('you sure about that?',         SA::detect('you sure about that?') === SA::DOUBT);
ok('r u sure',                     SA::detect('r u sure') === SA::DOUBT);
ok('is it really good?',           SA::detect('is it really good?') === SA::DOUBT);

sec('Intent detection — compare');
ok('which is better, A or B?',     SA::detect('which is better, kolam or india gate?') === SA::COMPARE);
ok('kolam vs india gate',          SA::detect('kolam vs india gate') === SA::COMPARE);
ok('compare basmati and brown',    SA::detect('compare basmati and brown rice') === SA::COMPARE);
ok('difference between X and Y',   SA::detect('difference between kolam and ravi') === SA::COMPARE);

sec('Intent detection — must NOT fire (normal flow owns these)');
ok('2 rice -> null',               SA::detect('2 rice') === null);
ok('rice -> null',                 SA::detect('rice') === null);
ok('hello -> null',                SA::detect('hello') === null);
ok('checkout -> null',             SA::detect('checkout') === null);
ok('"1" -> null',                  SA::detect('1') === null);
ok('"1 2 3" -> null',              SA::detect('1 2 3') === null);
ok('yes -> null',                  SA::detect('yes') === null);
ok('bare "sure" (no ?) -> null',   SA::detect('sure') === null);
ok('ok -> null',                   SA::detect('ok') === null);
ok('add milk -> null',             SA::detect('add milk') === null);
ok('too expensive -> null (bargaining, not sales-talk)', SA::detect('too expensive') === null);
ok('best one -> null (FollowUp premium owns it)',        SA::detect('best one') === null);

sec('parseVersus — sides');
ok('"...better, A or B"',          SA::parseVersus('which is better, kolam or india gate?') === ['kolam','india gate']);
ok('A vs B',                       SA::parseVersus('kolam vs india gate') === ['kolam','india gate']);
ok('compare A and B',              SA::parseVersus('compare basmati and brown rice') === ['basmati','brown rice']);
ok('difference between A and B',   SA::parseVersus('difference between kolam and ravi') === ['kolam','ravi']);

sec('stripCues — leaves the product term');
ok('which rice is good?',          SA::stripCues('which rice is good?') === 'rice');
ok('what do you recommend for rice', SA::stripCues('what do you recommend for rice') === 'rice');
ok('recommend a good cooking oil', SA::stripCues('recommend a good cooking oil') === 'cooking oil');

sec('pickRecommendation — hybrid policy (owner default -> best-seller -> value)');
$A = ['id'=>1,'name'=>'Kolam Rice 5kg','price'=>30000,'stock'=>10];
$B = ['id'=>2,'name'=>'India Gate Rice 5kg','price'=>45000,'stock'=>10];
$C = ['id'=>3,'name'=>'Ravi Rice 5kg','price'=>25000,'stock'=>10];
$cands = [$A,$B,$C];

$r = SA::pickRecommendation('rice', $cands, $B, []);            // owner default wins
ok('owner default is chosen',      ($r['product']['id'] ?? null) === 2 && str_contains($r['basis'],'recommend'));
ok('owner default tagged pick',    ($r['tag'] ?? null) === 'pick');

$r = SA::pickRecommendation('rice', $cands, null, [1=>40,3=>5]); // no default -> best-seller (id 1)
ok('best-seller chosen when no default', ($r['product']['id'] ?? null) === 1 && str_contains($r['basis'],'popular'));
ok('best-seller tagged popular',   ($r['tag'] ?? null) === 'popular');

$r = SA::pickRecommendation('rice', $cands, null, []);          // no default, no sales -> cheapest in stock (id 3)
ok('best value chosen when no data', ($r['product']['id'] ?? null) === 3 && str_contains($r['basis'],'value'));
ok('best value tagged value',      ($r['tag'] ?? null) === 'value');

$r = SA::pickRecommendation('rice', [], null, []);              // nothing
ok('empty -> no product',          ($r['product'] ?? null) === null);

sec('FollowUp — price-objection maps to cheaper, premium NOT flipped');
ok('"too expensive" -> cheaper',   FollowUp::parse('too expensive') === 'cheaper');
ok('"its too costly" -> cheaper',  FollowUp::parse('its too costly') === 'cheaper');
ok('"expensive" -> cheaper',       FollowUp::parse('expensive') === 'cheaper');
ok('"over budget" -> cheaper',     FollowUp::parse('over budget') === 'cheaper');
ok('"anything cheaper" -> cheaper',FollowUp::parse('anything cheaper') === 'cheaper');
ok('"expensive one" -> premium (unchanged)', FollowUp::parse('expensive one') === 'premium');
ok('"cheaper" -> cheaper (unchanged)',       FollowUp::parse('cheaper') === 'cheaper');
ok('"bigger" -> larger (unchanged)',         FollowUp::parse('bigger') === 'larger');
ok('"more brands" -> more (unchanged)',      FollowUp::parse('more brands') === 'more');
ok('"2 rice" -> null (not a follow-up)',     FollowUp::parse('2 rice') === null);

echo "\n========= RESULT =========\n";
echo "PASS $PASS  FAIL $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
