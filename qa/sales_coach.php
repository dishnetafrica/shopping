<?php declare(strict_types=1);
// Pure test of the AI Sales Coach (App\Services\Isp\SalesCoach): what it is told, how its answer is
// read, and that its suggestion is guarded like the bot's. Framework-free; test values only.
//   php qa/sales_coach.php
require __DIR__ . '/../app/Services/Travel/Conversation/ReplyGuard.php';
require __DIR__ . '/../app/Services/Sales/Brain/Knowledge.php';
require __DIR__ . '/../app/Services/Isp/IspReplyGuard.php';
require __DIR__ . '/../app/Services/Isp/IspSalesTurn.php';
require __DIR__ . '/../app/Services/Isp/SalesCoach.php';

use App\Services\Isp\SalesCoach as S;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$published = [['topic' => 'faq', 'key' => 'install', 'title' => 'Install time', 'body' => 'Fibre installation visit takes 2–4 hours.', 'data' => null, 'version' => 1, 'published_at' => null]];
$catalogue = [['name' => 'Test Fibre 40', 'price' => 75.0, 'billing' => 'monthly', 'service_line' => 'fiber'], ['name' => 'Test Kit', 'price' => 400.0, 'billing' => 'one_off', 'service_line' => 'starlink']];
$history = [['dir' => 'in', 'sender' => 'customer', 'body' => 'Hi, how much is fibre?'], ['dir' => 'out', 'sender' => 'bot', 'body' => 'Which area are you in?'], ['dir' => 'in', 'sender' => 'customer', 'body' => "Gudele   two\n near church"]];

echo "== what the coach is told\n";
$m = S::messages('DishNet Africa Limited', 'USD', $published, $catalogue, $history, 'Akol', 'Sarah');
chk('system + user message', array_column($m, 'role'), ['system', 'user']);
chk('only approved knowledge and the switched-on catalogue', [str_contains($m[0]['content'], 'Install time'), str_contains($m[0]['content'], '- Test Fibre 40: 75 per month [fiber]'), str_contains($m[0]['content'], 'Never invent a price')], [true, true, true]);
chk('the conversation, oldest first, with who said what, whitespace tidied', str_contains($m[1]['content'], "Customer: Hi, how much is fibre?\nBot: Which area are you in?\nCustomer: Gudele two near church"), true);
chk('written for the named salesperson', str_contains($m[0]['content'], 'A salesperson (Sarah)'), true);
chk('nothing switched on: told not to state any price', str_contains(S::catalogueBlock([], 'USD'), 'do not state any price'), true);

echo "== reading the answer\n";
$p = S::parse('Sure: {"reply":"Fibre Standard is 75 a month.","why":"They asked the price.","next_step":"recommend","used":["Test Fibre 40",""],"check":""}');
chk('JSON is read even with text around it', [$p['suggestion'], $p['next_step'], $p['used']], ['Fibre Standard is 75 a month.', 'recommend', ['Test Fibre 40']]);
chk('an unknown step is dropped', S::parse('{"reply":"x","next_step":"upsell_hard"}')['next_step'], '');
chk('no reply → no suggestion', [S::parse('{"why":"x"}'), S::parse('not json')], [null, null]);

echo "== the suggestion is guarded\n";
$f = S::finish(S::parse('{"reply":"Fibre Standard is $75 a month. Installation is $90. We cover Gudele Two.","why":"w","next_step":"quote","used":[],"check":""}'), [75.0], []);
chk('an unapproved price and an unpublished coverage claim are removed', str_contains($f['suggestion'], '$90') || str_contains($f['suggestion'], 'We cover Gudele'), false);
chk('the approved price stays', str_contains($f['suggestion'], 'Fibre Standard is $75 a month.'), true);
chk('the salesperson is told what was removed', [$f['removed'], str_contains($f['check'], 'price'), str_contains($f['check'], 'coverage')], [2, true, true]);
chk('a clean suggestion is untouched', S::finish(S::parse('{"reply":"Which area are you in?","why":"w"}'), [], [])['removed'], 0);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
