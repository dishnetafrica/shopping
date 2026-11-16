<?php declare(strict_types=1);
// Pure test of the Internet-provider bot rules: App\Services\Isp\IspReplyGuard (what the model may not
// say) and IspSalesTurn's prompt text and allowances. Framework-free. Places and prices are test values.
//   php qa/isp_bot.php
require __DIR__ . '/../app/Services/Travel/Conversation/ReplyGuard.php';
require __DIR__ . '/../app/Services/Sales/Brain/Knowledge.php';
require __DIR__ . '/../app/Services/Isp/IspReplyGuard.php';
require __DIR__ . '/../app/Services/Isp/IspSalesTurn.php';

use App\Services\Isp\IspReplyGuard as G;
use App\Services\Isp\IspSalesTurn as S;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$published = [
    ['topic' => 'terms', 'key' => 'starlink', 'title' => 'Starlink terms', 'body' => '', 'data' => ['title' => 'T', 'items' => [['label' => 'Relocation', 'text' => '$75 fee.']]], 'version' => 1, 'published_at' => null],
    ['topic' => 'faq', 'key' => 'install', 'title' => 'Install time', 'body' => 'Installation takes 2-5 days after payment.', 'data' => null, 'version' => 1, 'published_at' => null],
    ['topic' => 'coverage', 'key' => 'fiber', 'title' => 'Fiber coverage', 'body' => '', 'data' => ['areas' => [
        ['name' => 'Test Town', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['test town', 'test market'], 'lat' => null, 'lng' => null, 'radius_m' => null, 'note' => ''],
        ['name' => 'Test Hills', 'service_line' => 'fiber', 'status' => 'none', 'keywords' => ['test hills'], 'lat' => null, 'lng' => null, 'radius_m' => null, 'note' => ''],
    ]], 'version' => 1, 'published_at' => null],
];
[$amounts, $places] = S::allowed($published);

echo "== what the guard lets through\n";
chk('amounts written in published knowledge are allowed', $amounts, [75.0]);
chk('places published as covered (not the not-served ones)', $places, ['test town', 'test market']);

echo "== prices\n";
$r = G::clean('Great choice! The Standard kit is $599. Relocation is $75.', array_merge($amounts, [120.0]), $places);
chk('an invented price is removed, a published one stays, and the note is added', $r['reply'], "Great choice! Relocation is $75.\n\n" . G::PRICE_NOTE);
chk('…counted', $r['price_hits'], 1);
chk('a catalogue price stays', G::clean('The kit is USD 120.', [120.0], [])['reply'], 'The kit is USD 120.');
chk('the customer\'s own budget may be repeated', G::clean('With a budget of $300 we can look at options.', [300.0], [])['price_hits'], 0);
chk('"takes 3 days" is not a price (the "kes" in "takes" is not Kenyan shillings)', G::clean('Installation takes 3 days after payment.', [], [])['reply'], 'Installation takes 3 days after payment.');
chk('…while a real KES amount is still read', \App\Services\Travel\Conversation\ReplyGuard::amounts('about KES 2,000'), [2000.0]);
chk('no amounts: untouched', G::clean('Which area are you in?', [], [])['reply'], 'Which area are you in?');

echo "== coverage claims\n";
chk('claiming an unlisted place is covered is removed', G::clean('Yes, we cover Gudele. Shall I book a visit?', [], $places)['reply'], "Shall I book a visit?\n\n" . G::COVERAGE_NOTE);
chk('a place published as covered may be confirmed', G::clean('Yes, Test Town is covered by our fiber.', [], $places)['coverage_hits'], 0);
chk('"fiber is available in your area" with no published place: removed', G::claimsCoverage('Fiber is available in your area!', $places), true);
chk('a hedge is fine ("we will confirm coverage")', G::claimsCoverage('We cover many areas, but we will confirm coverage at your location.', $places), false);
chk('a question is fine', G::claimsCoverage('Do you know if we cover your street?', $places), false);
chk('saying a place is NOT covered is fine', G::claimsCoverage('Unfortunately Test Hills is not covered yet.', $places), false);
chk('nothing published: every coverage claim is removed', G::claimsCoverage('We serve Test Town.', []), true);
chk('hidden marker lines are never touched', G::clean("Sure.\n<<QUOTE {\"items\":[{\"name\":\"Kit\",\"price\":999}]}>>", [], [])['reply'], "Sure.\n<<QUOTE {\"items\":[{\"name\":\"Kit\",\"price\":999}]}>>");

echo "== what the model is told\n";
$none = S::knowledgeBlock([]);
chk('nothing published: told plainly not to state prices, packages, coverage or terms', str_contains($none, 'none is published yet') && str_contains($none, 'Do not state any price'), true);
$block = S::knowledgeBlock($published);
chk('published knowledge is given, terms as numbered text', [str_contains($block, 'Install time'), str_contains($block, '1. Relocation: $75 fee.')], [true, true]);
chk('coverage is listed by service and answer', [str_contains($block, 'Fiber — Covered: Test Town (test town, test market)'), str_contains($block, 'Fiber — Not served: Test Hills')], [true, true]);
chk('anything unlisted is a survey', str_contains($block, 'any place not listed here: site survey needed'), true);
chk('the rules forbid general knowledge and unwritten promises', [str_contains(S::rules(), 'do not use general knowledge'), str_contains(S::rules(), 'Never promise a discount')], [true, true]);
$withQuiz = array_merge($published, [['topic' => 'quiz', 'key' => 'product', 'title' => 'Product quiz', 'body' => '1 question(s)', 'data' => ['module' => 'product', 'questions' => [['q' => 'SECRET QUIZ QUESTION', 'options' => ['a', 'b'], 'answer' => 0, 'why' => '']]], 'version' => 1, 'published_at' => null]]);
chk('training questions never reach the bot\'s or coach\'s knowledge', str_contains(S::knowledgeBlock($withQuiz), 'SECRET QUIZ') || str_contains(S::knowledgeBlock($withQuiz), 'Training questions'), false);
chk('no Uganda defaults in the rules', stripos(S::rules() . $block, 'Kampala') === false && stripos(S::rules() . $block, 'UGX') === false, true);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
