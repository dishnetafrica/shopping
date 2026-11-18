<?php declare(strict_types=1);
// Pure test of the win-back list: App\Services\Isp\Winback (how each past WhatsApp customer is chased) and
// App\Services\WhatsApp\EvolutionHistory::parse (reading the line's stored history). Framework-free.
// Every conversation here is made up; the shapes follow the live patterns seen in DishNet's history.
//   php qa/isp_winback.php
require __DIR__ . '/../app/Services/Isp/Winback.php';
require __DIR__ . '/../app/Services/WhatsApp/EvolutionHistory.php';

use App\Services\Isp\Winback as W;
use App\Services\WhatsApp\EvolutionHistory as H;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$now = strtotime('2026-11-17 12:00:00');
$d = fn (int $daysAgo) => $now - $daysAgo * 86400;
$c = fn (array $rows) => W::classify($rows, $now);

echo "== who is chased, and how warm\n";
$hot = $c([[$d(10), false, 'Hello! Can I get more info on this?'], [$d(10), true, 'Welcome!'], [$d(9), false, 'I want starlink mini for my shop'], [$d(9), false, 'where is your office?'], [$d(8), true, 'Our office is in town.']]);
chk('wanted to buy and visit, recently: hot, Starlink', [$hot['segment'], $hot['service'], $hot['skip']], ['hot', 'starlink', null]);
chk('…we answered last: not unanswered', $hot['unanswered'], false);
$old = $c([[$d(200), false, 'I want fiber at home, call me']]);
chk('the same wish 200 days ago is warm, not hot', $old['segment'], 'warm');
chk('…and the customer spoke last: unanswered', $old['unanswered'], true);
chk('…older scores lower than recent', $old['score'] < $hot['score'], true);
chk('asked when the kit was $299: price changed', $c([[$d(40), false, 'Is the mini still 299 USD?']])['segment'], 'price_changed');
chk('asked for a $30 plan: wanted cheaper', $c([[$d(40), false, 'is there a $30 monthly plan?']])['segment'], 'wanted_cheaper');
chk('asked a price: warm', $c([[$d(40), false, 'How much is it']])['segment'], 'warm');
chk('only the advert question and hello: cold', $c([[$d(5), false, 'Hello! Can I get more info on this?'], [$d(5), false, 'Hi'], [$d(5), true, 'Welcome']])['segment'], 'cold');
chk('anything of their own beyond hello is at least warm', $c([[$d(5), false, 'Can you bring it to Akobo?']])['segment'], 'warm');
chk('"fiber for my office" is not a visit to OUR office: warm, not hot', $c([[$d(5), false, 'fiber price for my office please']])['segment'], 'warm');
chk('"office hours?" is a visit: hot', $c([[$d(5), false, 'what are your office hours?']])['segment'], 'hot');
chk('"speak to an agent" is a callback: hot', $c([[$d(5), false, 'Can I speak to an agent please']])['segment'], 'hot');
chk('Fiber words: Fiber; both: both', [$c([[$d(5), false, 'fibre 20 mbps price?']])['service'], $c([[$d(5), false, 'starlink or fiber which is better?']])['service']], ['fiber', 'both']);
chk('"DishNet" is not the word "dish" (not Starlink)', $c([[$d(5), false, 'is dishnet available in wau?']])['service'], 'unsure');

echo "== existing customers (relationship, not a sales pitch)\n";
chk('a fault report', $c([[$d(20), false, 'Network is really down since morning']])['segment'], 'existing_customer');
chk('a renewal', $c([[$d(20), false, 'my subscription expired how do I renew']])['segment'], 'existing_customer');
chk('said they paid', $c([[$d(20), false, 'I have paid, here is the receipt']])['segment'], 'existing_customer');

echo "== left out\n";
chk('their last word was stop', $c([[$d(3), false, 'how much?'], [$d(2), false, 'Not interested, stop']])['skip'], 'asked_to_stop');
chk('…an old "stop" followed by a new question is not', $c([[$d(9), false, 'stop'], [$d(2), false, 'ok now how much is fiber?']])['skip'], null);
chk('only forwarded scams', $c([[$d(3), false, "I got 150,000 SSP! You should try it too / https://scam.example/x"], [$d(3), false, 'You are qualified to relocate - APPLY TODAY']])['skip'], 'spam_only');
chk('a shared map location is not spam', $c([[$d(3), false, 'my place https://maps.app.goo.gl/abc123 please install']])['skip'], null);
chk('a scam does not make a real question spam', $c([[$d(3), false, 'https://scam.example/y you should try it'], [$d(3), false, 'starlink price?']])['segment'], 'warm');
chk('we sent this number the old bot\'s staff alerts: a staff phone', $c([[$d(3), true, "\u{1F514} WANTS TO VISIT OFFICE (hot) / From: X"], [$d(3), false, 'Client not yet replied']])['skip'], 'staff_phone');
chk('nothing written by the customer (only our broadcasts, or only photos)', [$c([[$d(3), true, 'Promo!']])['skip'], $c([[$d(3), false, '[image]']])['skip']], ['no_customer_text', 'no_customer_text']);

echo "== the first message\n";
$o = W::opener('hot', 'starlink', true, 'Mary Akech', 'Justus', 'DishNet Africa');
chk('personal: their first name, the salesperson, the company', str_starts_with($o, 'Hello Mary, this is Justus from DishNet Africa.'), true);
chk('honest about the delay when we never answered', str_contains($o, 'I am sorry we did not get back to you earlier.'), true);
chk('offers a quotation', str_contains($o, 'quotation'), true);
chk('no apology when we did answer', str_contains(W::opener('hot', 'starlink', false, 'Mary', 'Justus', 'DishNet Africa'), 'sorry'), false);
chk('a name that is not a name (emoji, a number) is left out', [str_starts_with(W::opener('cold', 'unsure', false, "\u{1F60A}\u{1F60A}", 'Justus', 'DishNet'), 'Hello, this is'), str_starts_with(W::opener('cold', 'unsure', false, '+211 92', 'Justus', 'DishNet'), 'Hello, this is')], [true, true]);
$all = '';
foreach (array_keys(W::SEGMENTS) as $s) {
    foreach (['starlink', 'fiber', 'both', 'unsure'] as $sv) {
        $all .= W::opener($s, $sv, true, 'Ann', 'Justus', 'DishNet Africa') . "\n";
    }
}
chk('no first message states any amount (prices changed: the quotation carries today\'s)', preg_match('/\d/', $all), 0);
chk('the price-changed message says prices changed, without numbers', str_contains(W::opener('price_changed', 'starlink', false, 'Ann', 'Justus', 'DishNet'), 'our prices were different'), true);
chk('an existing customer hears from their account manager, not a pitch', [str_contains(W::opener('existing_customer', 'fiber', true, 'Ann', 'Justus', 'DishNet'), 'account manager'), str_contains(W::opener('existing_customer', 'fiber', true, 'Ann', 'Justus', 'DishNet'), 'quotation')], [true, false]);

echo "== spreading the work\n";
$s = W::schedule(['1' => ['owner' => 7, 'score' => 50], '2' => ['owner' => 7, 'score' => 90], '3' => ['owner' => 7, 'score' => 70], '4' => ['owner' => 8, 'score' => 10]], 2);
chk('best first, at most 2 a day per salesperson, each salesperson starts today', $s, ['2' => 0, '3' => 0, '1' => 1, '4' => 0]);

echo "== reading the history\n";
chk('a customer text', H::parse(['key' => ['remoteJid' => '211920000001@s.whatsapp.net', 'fromMe' => false, 'id' => 'A'], 'pushName' => 'Ann', 'messageTimestamp' => 1763000000, 'message' => ['conversation' => ' hi ']]),
    ['211920000001', 1763000000, false, 'hi', 'Ann', false]);
chk('ours: no contact name taken from it', H::parse(['key' => ['remoteJid' => '211920000001@s.whatsapp.net', 'fromMe' => true], 'pushName' => 'DishNet', 'messageTimestamp' => ['low' => 5], 'message' => ['extendedTextMessage' => ['text' => 'Welcome']]]),
    ['211920000001', 5, true, 'Welcome', '', false]);
chk('a "name" with no letter (the hidden id itself) is not kept as a name', H::parse(['key' => ['remoteJid' => '225683989098601@lid'], 'pushName' => '225683989098601', 'message' => ['conversation' => 'hi']])[4], '');
chk('a disappearing message is unwrapped', H::parse(['key' => ['remoteJid' => '211920000001@s.whatsapp.net'], 'message' => ['ephemeralMessage' => ['message' => ['conversation' => 'price?']]]])[3], 'price?');
chk('a hidden-id chat goes under the real number when Evolution has it', array_values(array_intersect_key(H::parse(['key' => ['remoteJid' => '123456789012345@lid', 'remoteJidAlt' => '211920000002@s.whatsapp.net'], 'message' => ['conversation' => 'x']]), [0 => 1, 5 => 1])), ['211920000002', false]);
chk('…and with no number is kept under the hidden id, as ShopBot keys it, marked hidden', [H::parse(['key' => ['remoteJid' => '123456789012345@lid'], 'message' => ['conversation' => 'x']])[0], H::parse(['key' => ['remoteJid' => '123456789012345@lid'], 'message' => ['conversation' => 'x']])[5]], ['123456789012345', true]);
chk('WhatsApp\'s own account (0@s.whatsapp.net, the "busiest season" tips) is not a customer', H::parse(['key' => ['remoteJid' => '0@s.whatsapp.net'], 'pushName' => '0', 'message' => ['conversation' => '*Get ready for the busiest season of the year*']]), null);
chk('groups, status, channels are not customers', [H::parse(['key' => ['remoteJid' => '1-2@g.us'], 'message' => ['conversation' => 'x']]), H::parse(['key' => ['remoteJid' => 'status@broadcast'], 'message' => ['conversation' => 'x']]), H::parse(['key' => ['remoteJid' => '1@newsletter'], 'message' => ['conversation' => 'x']])], [null, null, null]);
chk('a reaction is not a message; a photo is kept as a marker', [H::parse(['key' => ['remoteJid' => '211920000003@s.whatsapp.net'], 'message' => ['reactionMessage' => ['text' => 'x']]]), H::parse(['key' => ['remoteJid' => '211920000003@s.whatsapp.net'], 'message' => ['imageMessage' => ['url' => 'u']]])[3]], [null, '[image]']);

echo "\n" . ($fail ? 'FAILURES' : 'ALL PASS') . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
