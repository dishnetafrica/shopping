<?php declare(strict_types=1);
// Pure test of the DishNet n8n-bot import data (App\Services\Sales\Brain\DishNetBotImport): every draft
// is valid, conflicts with the quote template are open questions, prices live only in the catalogue,
// coverage never matches the bare word "Juba", and no staff personal number is carried over.
//   php qa/dishnet_import.php
require __DIR__ . '/../app/Services/Delivery/ZoneResolver.php';
require __DIR__ . '/../app/Services/Travel/Conversation/ReplyGuard.php';
require __DIR__ . '/../app/Services/Sales/Brain/Knowledge.php';
require __DIR__ . '/../app/Services/Sales/Brain/CoverageCheck.php';
require __DIR__ . '/../app/Services/Sales/Brain/DishNetBotImport.php';
require __DIR__ . '/../app/Services/Sales/Training/Assessment.php';
require __DIR__ . '/../app/Services/Sales/Brain/CustomerQuestions.php';

use App\Services\Sales\Brain\CoverageCheck as C;
use App\Services\Sales\Brain\CustomerQuestions as CQ;
use App\Services\Sales\Brain\DishNetBotImport as D;
use App\Services\Sales\Brain\Knowledge as K;
use App\Services\Travel\Conversation\ReplyGuard;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$k = D::knowledge();
$byKey = [];
foreach ($k as [$t, $key, $title, $c, $q]) {
    $byKey["$t/$key"] = ['content' => $c, 'questions' => $q];
}
$all = json_encode([D::products(), $k], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

echo "== drafts\n";
chk('every draft is valid for its topic', array_values(array_unique(array_map(fn ($e) => K::validate($e[0], is_string($e[3]) ? $e[3] : null, is_array($e[3]) ? $e[3] : null)['ok'], $k))), [true]);
chk('12 entries across 12 topics', [count($k), count(array_unique(array_column($k, 0)))], [12, 12]);
$qs = fn (string $e) => implode(' ', $byKey[$e]['questions']);
chk('uptime conflict is an open question on Fiber and on the wording guide', [str_contains($qs('fiber/service'), '99.5%'), str_contains($qs('scripts/wording'), '99.5%')], [true, true]);
chk('sold-vs-leased equipment conflict is open on Starlink and Fiber', [str_contains($qs('starlink/service'), 'leased'), str_contains($qs('fiber/service'), 'leased')], [true, true]);
chk('missing Priority prices are an open question', str_contains($qs('starlink/service'), 'Priority'), true);
chk('"fibre first" vs "sell Starlink in Juba" is an open question', str_contains($qs('packages/which-package'), 'keep selling Starlink'), true);
chk('the fixed SSP rate is an open question', str_contains($qs('payment/methods'), '8200'), true);

echo "== prices live in the catalogue only\n";
$text = implode("\n", array_map(fn ($e) => is_string($e[3]) ? $e[3] : '', $k));
chk('no money amount in any knowledge text', ReplyGuard::amounts($text), []);
$p = D::products();
chk('13 priced products, each one-time or monthly', [count($p), array_values(array_unique(array_map(fn ($x) => in_array($x['billing'], ['one_off', 'monthly'], true), $p)))], [13, [true]]);
chk('no Priority product (the text gives no price)', count(array_filter($p, fn ($x) => stripos($x['name'], 'priority') !== false)), 0);
chk('prices as written: Mini 400, Standard 550, Residential 50 monthly, Fiber Standard 75 monthly, fibre installation 150', array_map(fn ($n) => [current(array_filter($p, fn ($x) => $x['name'] === $n))['price'], current(array_filter($p, fn ($x) => $x['name'] === $n))['billing']],
    ['Starlink Mini (Gen 4)', 'Starlink Standard', 'Starlink Residential Plan', 'Fiber Standard 40 Mbps', 'Fiber Installation (includes Wi-Fi router)']),
    [[400, 'one_off'], [550, 'one_off'], [50, 'monthly'], [75, 'monthly'], [150, 'one_off']]);

echo "== coverage\n";
$areas = K::validate('coverage', null, $byKey['coverage/fiber']['content'])['data']['areas'];
chk('33 Juba places, all fibre, all covered', [count($areas), array_values(array_unique(array_column($areas, 'service_line'))), array_values(array_unique(array_column($areas, 'status')))], [33, ['fiber'], ['covered']]);
chk('a listed place is covered', C::check($areas, 'fiber', 'Plot 12, Gudele Two near the church')['status'], 'covered');
chk('"Juba" alone is NOT a match (else every Juba address would be "covered")', C::check($areas, 'fiber', 'I am in Juba')['status'], 'survey');
chk('Malakal town is not Hai Malakal', C::check($areas, 'fiber', 'Malakal town')['status'], 'survey');
chk('Starlink is not covered by the fibre list', C::check($areas, 'starlink', 'Gudele Two')['status'], 'survey');

echo "== privacy\n";
$staff = ['211927797217', '256778938825', '211926134489', '211929563036', '211923731040', '211921443004', '927797217', '778938825', '926134489', '929563036', '923731040'];
chk('no staff personal number or internal bot line is imported', array_values(array_filter($staff, fn ($n) => str_contains(preg_replace('/\D/', '', $all), $n))), []);
chk('only the three public department numbers appear', array_values(array_unique(preg_match_all('/\+211 9\d\d \d{3} \d{3}/', $all, $m) ? $m[0] : [])), ['+211 923 400 000', '+211 921 443 002', '+211 921 443 009']);

echo "== customer questions (answers left to management)\n";
$all = CQ::all();
$open = array_filter($all, fn ($q) => $q[2] !== 'covered');
$body = CQ::draftBody();
chk('44 questions: 13 covered, 16 partly, 15 missing', CQ::counts(), ['covered' => 13, 'partly' => 16, 'missing' => 15]);
chk('every status is covered/partly/missing', array_values(array_unique(array_map(fn ($q) => in_array($q[2], ['covered', 'partly', 'missing'], true), $all))), [true]);
chk('every partly/missing question is in the draft, none of the covered ones', [count(array_filter($open, fn ($q) => str_contains($body, 'Q: ' . $q[1]))), count(array_filter($all, fn ($q) => $q[2] === 'covered' && str_contains($body, 'Q: ' . $q[1])))], [count($open), 0]);
chk('no answer is written for management: every A: is a placeholder', preg_match_all('/^A: (?!\[TO ANSWER)/m', $body), 0);
chk('the instructions are inside a placeholder, so they cannot be approved and reach the bot', str_starts_with($body, '[TO ANSWER'), true);
chk('the draft blocks approval', K::hasPlaceholder($body, null), true);
chk('…and is valid text for the FAQ topic', K::validate('faq', $body, null)['ok'], true);
chk('a filled-in draft no longer blocks', K::hasPlaceholder(preg_replace('/\[TO ANSWER[^\]]*\]/', 'x', $body), null), false);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
