<?php declare(strict_types=1);
// Pure test of the Sales Brain rules (App\Services\Sales\Brain\Knowledge, CoverageCheck, TemplateTerms).
// Framework-free. Area names and figures are test values, not DishNet's.
//   php qa/sales_brain.php
require __DIR__ . '/../app/Services/Delivery/ZoneResolver.php';
require __DIR__ . '/../app/Services/Sales/Brain/Knowledge.php';
require __DIR__ . '/../app/Services/Sales/Brain/CoverageCheck.php';
require __DIR__ . '/../app/Services/Sales/Brain/TemplateTerms.php';

use App\Services\Sales\Brain\CoverageCheck as C;
use App\Services\Sales\Brain\Knowledge as K;
use App\Services\Sales\Brain\TemplateTerms as T;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== a version only moves forwards\n";
chk('draft → approved → published → retired', [K::next('draft', 'approve'), K::next('approved', 'publish'), K::next('published', 'retire')], ['approved', 'published', 'retired']);
chk('a draft cannot be published without approval', K::next('draft', 'publish'), null);
chk('a published version cannot be approved again or rejected', [K::next('published', 'approve'), K::next('published', 'reject')], [null, null]);
chk('rejected and retired are final', [K::next('rejected', 'approve'), K::next('retired', 'publish')], [null, null]);
chk('only a draft can be edited', [K::editable('draft'), K::editable('approved'), K::editable('published')], [true, false, false]);
chk('one version in progress at a time', [K::canStartVersion(['published', 'retired']), K::canStartVersion(['published', 'draft']), K::canStartVersion(['approved'])], [true, false, false]);
chk('entry key', K::key('  Starlink Terms (SS) '), 'starlink-terms-ss');

echo "== what a valid entry is\n";
chk('unknown topic refused', K::validate('gossip', 'x', null)['error'], 'unknown_topic');
chk('text topic needs text', K::validate('faq', '  ', null)['error'], 'body_required');
chk('text topic keeps no data', K::validate('faq', 'Q: A', ['x' => 1])['data'], null);
$t = K::validate('terms', null, ['title' => 'T', 'items' => [['label' => 'Pay', 'text' => 'Due monthly'], ['label' => 'Empty', 'text' => ' ']]]);
chk('terms: blank items dropped, text made from the items', [count($t['data']['items']), $t['body']], [1, '1. Pay: Due monthly']);
chk('terms with no items refused', K::validate('terms', 'x', ['items' => []])['error'], 'terms_need_items');
$cov = fn (array $a) => K::validate('coverage', null, ['areas' => [$a]]);
chk('coverage: status must be covered/survey/none', $cov(['name' => 'Area A', 'service_line' => 'fiber', 'status' => 'yes', 'keywords' => ['area a']])['error'], 'coverage_area_invalid');
chk('coverage: service must be starlink/fiber', $cov(['name' => 'Area A', 'service_line' => 'wimax', 'status' => 'covered', 'keywords' => ['area a']])['error'], 'coverage_area_invalid');
chk('coverage: needs place names or a circle', $cov(['name' => 'Area A', 'service_line' => 'fiber', 'status' => 'covered'])['error'], 'coverage_area_needs_place');
chk('coverage: place names under 3 letters are ignored (no accidental matches)', $cov(['name' => 'A', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['ab', 'area a']])['data']['areas'][0]['keywords'], ['area a']);

echo "== which terms print on a quotation\n";
$pub = ['starlink' => ['title' => 'S', 'items' => [['label' => '', 'text' => 's']]], 'general' => ['title' => 'G', 'items' => [['label' => '', 'text' => 'g']]]];
chk('Starlink quotation → Starlink terms', K::quoteTerms(['starlink'], $pub)[0]['title'], 'S');
chk('both services → general terms', K::quoteTerms(['starlink', 'fiber'], $pub)[0]['title'], 'G');
chk('no service known → general terms', K::quoteTerms([], $pub)[0]['title'], 'G');
chk('Fiber quotation with Fiber terms not published → none (never another service\'s terms)', K::quoteTerms(['fiber'], $pub), []);
chk('nothing published → none', K::quoteTerms(['starlink'], []), []);

echo "== coverage answers come only from published areas\n";
$areas = [
    ['name' => 'Test Town Centre', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['test town'], 'lat' => null, 'lng' => null, 'radius_m' => null, 'note' => ''],
    ['name' => 'Test Hills', 'service_line' => 'fiber', 'status' => 'none', 'keywords' => ['test hills'], 'lat' => null, 'lng' => null, 'radius_m' => null, 'note' => 'No fibre planned'],
    ['name' => 'Test Circle', 'service_line' => 'starlink', 'status' => 'covered', 'keywords' => [], 'lat' => 4.85, 'lng' => 31.6, 'radius_m' => 5000, 'note' => ''],
];
chk('a listed covered place', C::check($areas, 'fiber', 'I live in Test Town near the market')['status'], 'covered');
chk('a listed not-served place', [C::check($areas, 'fiber', 'test hills')['status'], C::check($areas, 'fiber', 'test hills')['note']], ['none', 'No fibre planned']);
chk('an unknown place is "survey", never "covered"', C::check($areas, 'fiber', 'Somewhere Else')['status'], 'survey');
chk('another service\'s area does not count', C::check($areas, 'starlink', 'Test Town')['status'], 'survey');
chk('a pin inside a published circle', C::check($areas, 'starlink', '', 4.86, 31.6)['status'], 'covered');
chk('a pin outside it', C::check($areas, 'starlink', '', 5.5, 31.6)['status'], 'survey');
$nested = array_merge($areas, [['name' => 'Test Town Hills', 'service_line' => 'fiber', 'status' => 'none', 'keywords' => ['test town hills'], 'lat' => null, 'lng' => null, 'radius_m' => null, 'note' => '']]);
chk('the most specific place name wins ("Test Town Hills" is not "Test Town")', C::check($nested, 'fiber', 'Plot 4, Test Town Hills')['status'], 'none');
$tie = [['name' => 'X1', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['same place'], 'lat' => null, 'lng' => null, 'radius_m' => null, 'note' => ''],
        ['name' => 'X2', 'service_line' => 'fiber', 'status' => 'survey', 'keywords' => ['same place'], 'lat' => null, 'lng' => null, 'radius_m' => null, 'note' => '']];
chk('two areas claim the same name → the cautious answer', C::check($tie, 'fiber', 'same place')['status'], 'survey');
chk('nothing published at all → survey', C::check([], 'fiber', 'Test Town')['status'], 'survey');

echo "== the screen's text-box forms\n";
$items = K::parseTermsLines("1. Payment: Due on the 1st\n- Contract: 6 months\nNo label line\n\n");
chk('terms lines: numbering and bullets stripped, label split at the colon', $items, [['label' => 'Payment', 'text' => 'Due on the 1st'], ['label' => 'Contract', 'text' => '6 months'], ['label' => '', 'text' => 'No label line']]);
chk('terms round-trip', K::parseTermsLines(K::termsLines(['items' => $items])), $items);
$tpl = T::all()['starlink'];
chk('the template terms round-trip through the text box unchanged', K::parseTermsLines(K::termsLines($tpl)), $tpl['items']);
$c = K::validate('coverage', null, ['areas' => K::parseCoverageLines("Test Town | Fiber | Covered | test town, test market | Main road only\nTest Circle | starlink | survey | | | 4.85,31.6,5000")]);
chk('coverage lines parse and validate', [$c['ok'], $c['data']['areas'][0]['keywords'], $c['data']['areas'][1]['radius_m']], [true, ['test town', 'test market'], 5000]);
chk('coverage round-trip', K::validate('coverage', null, ['areas' => K::parseCoverageLines(K::coverageLines($c['data']))])['data'], $c['data']);
chk('a typo in the status is refused, not guessed', K::validate('coverage', null, ['areas' => K::parseCoverageLines('Test Town | fiber | coverd | test town')])['error'], 'coverage_area_invalid');

echo "== the template terms (imported as drafts only)\n";
$all = T::all();
chk('Starlink 14, Fiber 13, general 12 terms, as in the template', [count($all['starlink']['items']), count($all['fiber']['items']), count($all['general']['items'])], [14, 13, 12]);
chk('every set is valid', [K::validate('terms', null, $all['starlink'])['ok'], K::validate('terms', null, $all['fiber'])['ok'], K::validate('terms', null, $all['general'])['ok']], [true, true, true]);
chk('every set carries questions for management (so none can be approved as imported)', array_map(fn ($s) => count($s['questions']) > 0, $all), ['starlink' => true, 'fiber' => true, 'general' => true]);
chk('the leased-vs-transfer contradiction is flagged on Starlink', str_contains(implode(' ', $all['starlink']['questions']), 'transfer'), true);
chk('the source says draft, not approved', str_contains(T::SOURCE, 'not approved'), true);
chk('no Uganda text', stripos(json_encode($all), 'Kampala') === false && stripos(json_encode($all), 'UGX') === false, true);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
