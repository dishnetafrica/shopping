<?php declare(strict_types=1);
// Pure test: customer memory worded for an Internet provider (App\Services\Relationship\IspMemory).
//   php qa/isp_memory.php
require __DIR__ . '/../app/Services/Relationship/IspMemory.php';

use App\Services\Relationship\IspMemory as M;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== what the analyst is asked to remember\n";
$p = M::extractorPrompt('{"name":"Lomin"}');
chk('an Internet provider selling Starlink and Fiber, not a shop', [str_contains($p, 'Internet provider in South Sudan that sells Starlink and Fiber'), str_contains($p, 'WhatsApp commerce shop')], [true, false]);
chk('where the service is wanted, with a landmark — not a shop to deliver to', [str_contains($p, 'WHERE the service is wanted: area + a landmark'), str_contains($p, 'Konyo Konyo'), stripos($p, 'deliver')], [true, false, false]);
chk('the facts a salesperson needs', [str_contains($p, 'service_interest (starlink|fiber|both|unsure)'), str_contains($p, 'users_or_devices'), str_contains($p, 'home_or_business'), str_contains($p, 'next_expected_action')], [true, true, true, true]);
chk('never a price in the memory', [str_contains($p, 'NEVER a price, amount, discount or plan price'), str_contains($p, 'never write any money amount anywhere')], [true, true]);
chk('the same JSON the extractor reads (customer / review / summary / stage_proposal)', [str_contains($p, '"customer": {'), str_contains($p, '"review": {'), str_contains($p, '"summary"'), str_contains($p, '"stage_proposal"'), str_contains($p, '"shop_area"')], [true, true, true, true, true]);
chk('what is already known is passed in', str_contains($p, 'KNOWN SO FAR: {"name":"Lomin"}'), true);
chk('Arabic and Juba Arabic are expected', str_contains($p, 'Juba Arabic, Arabic or mixed English'), true);

echo "== what the bot reads\n";
chk('the place is confirmed, not asked again', M::locationLine('Gudele 2, near the roundabout'),
    'Where they want the service (from earlier chats): Gudele 2, near the roundabout. When coverage or installation comes up, CONFIRM it ("still for Gudele 2, near the roundabout?") rather than asking again. If they name somewhere different, use the new place.');
chk('gaps: name and place', M::gaps('', ''), ['their name', 'where they want the service (area + a landmark)']);
chk('gaps: nothing missing', M::gaps('Lomin', 'Gudele'), []);
chk('one question, only when needed, never before answering', [str_contains(M::gapsLine(['their name']), 'only when it is needed (coverage, quotation or installation)'), str_contains(M::gapsLine(['their name']), 'never before answering them')], [true, true]);
chk('the memory never sets a price or plan (Roam was discontinued)', [str_contains(M::NO_OLD_PRICES, 'never sets a price or a plan'), str_contains(M::NO_OLD_PRICES, 'discontinued')], [true, true]);
chk('nothing in the bot lines about delivery or shops', preg_match('/deliver|shop/i', M::locationLine('X') . M::gapsLine(M::gaps('', '')) . M::NO_OLD_PRICES), 0);

echo "\n" . ($fail ? "FAILURES" : "ALL PASS") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
