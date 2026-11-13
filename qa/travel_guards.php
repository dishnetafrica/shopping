<?php declare(strict_types=1);
// Pure test of the Travel Vertical's conversation guards: the hidden trip marker, the reply guard
// (no invented fares, no asking for passport data) and the passport-number mask. Framework-free.
//   php qa/travel_guards.php
require __DIR__ . '/../app/Services/Travel/Conversation/TripMarker.php';
require __DIR__ . '/../app/Services/Travel/Conversation/ReplyGuard.php';
require __DIR__ . '/../app/Services/Travel/Privacy/PassportGuard.php';

use App\Services\Travel\Conversation\ReplyGuard as G;
use App\Services\Travel\Conversation\TripMarker as M;
use App\Services\Travel\Privacy\PassportGuard as P;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== trip marker\n";
$r = M::extract("Lovely, Dubai it is! When would you like to travel?\n<<TRIP {\"to\":\"Dubai\",\"adults\":2}>>");
chk('marker is removed from the customer text', $r['reply'], 'Lovely, Dubai it is! When would you like to travel?');
chk('fields are read', $r['patch'], ['to' => 'Dubai', 'adults' => 2]);
chk('well-formed is not malformed', [$r['malformed'], $r['new_trip']], [false, false]);
$r = M::extract('Noted. <<TRIP {"trip_type":"multi_city","segments":[{"from":"Entebbe","to":"Nairobi","date":"2026-12-01"},{"from":"Nairobi","to":"Dubai"}]}>>');
chk('nested flights are read whole', count($r['patch']['segments']), 2);
chk('nested: text is clean', $r['reply'], 'Noted.');
$r = M::extract('Sure. <<TRIP {"segment":{"index":2,"to":"Doha"}}>> Anything else?');
chk('marker in the middle of a reply', [$r['reply'], $r['patch']['segment']['to']], ['Sure.  Anything else?', 'Doha']);
$r = M::extract('A new trip then! <<TRIP {"new_trip":true,"to":"London"}>>');
chk('new trip flag is read and not stored as a field', [$r['new_trip'], $r['patch']], [true, ['to' => 'London']]);
$r = M::extract('Ok <<TRIP {"to":"Dubai"}>> and <<TRIP {"adults":3,"to":"Doha"}>>');
chk('two markers merge, later wins', $r['patch'], ['to' => 'Doha', 'adults' => 3]);
$r = M::extract('Just a normal reply with no marker.');
chk('no marker: text untouched, nothing reported', [$r['reply'], $r['patch'], $r['malformed']], ['Just a normal reply with no marker.', null, false]);
$r = M::extract("Got it.\n<<TRIP {\"to\":\"Dubai\", \"adults\":");
chk('cut-off marker never leaks', [$r['reply'], $r['patch'], $r['malformed']], ['Got it.', null, true]);
$r = M::extract('Got it. <<TRIP {to: Dubai}>>');
chk('broken JSON is stripped and reported', [$r['reply'], $r['patch'], $r['malformed']], ['Got it.', null, true]);
$r = M::extract('Fine <<TRIP {"to":"Dubai"}>> <<QUOTE {"items":[{"name":"Visa service","qty":1}]}>>');
chk('other markers are left for the brain', str_contains($r['reply'], '<<QUOTE {"items"'), true);
$r = M::extract('');
chk('empty reply', [$r['reply'], $r['patch']], ['', null]);

echo "== reply guard: fares\n";
$r = G::clean('A return ticket to Dubai is about USD 480 per person. Which dates suit you?');
chk('an invented fare sentence is removed', $r['reply'], "Which dates suit you?\n\n" . G::FARE_NOTE);
chk('fare hit counted', $r['fare_hits'], 1);
foreach (['Flights start from $450.', 'It costs 1,750,000 UGX.', 'Around 480 dollars each.', 'Roughly UGX 1.8m return.', 'Fares are from 450k shillings.', 'Expect about US$ 500.', 'Price: 650 USD', 'It is 2,500,000/= for two.'] as $s) {
    $r = G::clean($s);
    chk("fare removed: $s", [$r['fare_hits'] >= 1, str_contains($r['reply'], G::FARE_NOTE)], [true, true]);
}
foreach (['Checked baggage is usually 23kg per person.', 'You are travelling on 20 Dec 2026 with 2 adults.', 'A 30% deposit confirms the booking.', 'Our office is open 8:00am to 5:00pm.', 'Call us on +256 788 611 242.', 'That is flight 1 of 3.', 'Emirates flies EK730 daily.'] as $s) {
    $r = G::clean($s);
    chk("not a fare, untouched: $s", [$r['reply'], $r['fare_hits']], [$s, 0]);
}
$r = G::clean('Our visa service is UGX 350,000. Shall I add it?', [350000.0]);
chk('a real catalogue price is allowed', [$r['reply'], $r['fare_hits']], ['Our visa service is UGX 350,000. Shall I add it?', 0]);
$r = G::clean('Our visa service is UGX 350,000 and the flight is about UGX 1,800,000.', [350000.0]);
chk('a sentence mixing a real price with an invented one is removed', $r['fare_hits'], 1);
$r = G::clean('Understood, your budget is USD 500 per person.', G::amounts('my budget is 500 usd each'));
chk('the customer\'s own figure may be repeated', [$r['fare_hits'], str_contains($r['reply'], 'USD 500')], [0, true]);
chk('amount parser', G::amounts('USD 480, 1,750,000 UGX, $45.50, 450k shillings and UGX 1.8m'), [480.0, 1750000.0, 45.5, 450000.0, 1800000.0]);
chk('no amounts in plain text', G::amounts('2 adults on 20 Dec 2026, 23kg bags'), []);
$r = G::clean("Here you go.\n<<QUOTE {\"items\":[{\"name\":\"Visa service\",\"qty\":1,\"price\":\"USD 90\"}]}>>");
chk('hidden marker lines are never altered', [str_contains($r['reply'], '"price":"USD 90"'), $r['fare_hits']], [true, 0]);
$r = G::clean("1. Emirates, non-stop, USD 612\n2. flydubai, non-stop, USD 498\nWhich one?");
chk('an invented fare list is removed line by line', [$r['fare_hits'], str_contains($r['reply'], '612'), str_contains($r['reply'], 'Which one?')], [2, false, true]);
$r = G::clean('It costs USD 480.');
chk('a reply that was only a fare becomes the team note', $r['reply'], G::FARE_NOTE);

echo "== reply guard: passport\n";
foreach (['Please send me a photo of your passport.', 'Kindly share your passport number so I can book.', 'Could you upload the passport copy here?', 'What is your passport number?', 'Can I have a picture of the passport page?', 'Please WhatsApp us your passport.'] as $s) {
    $r = G::clean($s);
    chk("request removed: $s", [$r['passport_hits'], $r['reply']], [1, G::PASSPORT_NOTE]);
}
foreach (['Please do not send your passport here.', 'Our team will collect passport details securely when you book.', 'Make sure your passport is valid for six months.', 'Names must match the passport exactly.', "You don't need to send a passport photo now."] as $s) {
    $r = G::clean($s);
    chk("allowed: $s", [$r['passport_hits'], $r['reply']], [0, $s]);
}
$r = G::clean('Great, two adults to Dubai. Please send your passport copies. When do you fly?');
chk('only the request sentence goes', $r['reply'], "Great, two adults to Dubai. When do you fly?\n\n" . G::PASSPORT_NOTE);

echo "== passport number mask\n";
$r = P::mask('My passport number is A1234567, please book');
chk('typed number is masked', [$r['text'], $r['masked']], ['My passport number is ' . P::MASK . ', please book', true]);
foreach (['passport B0123456', 'Passport no: AB1234567', 'passport 123456789', 'here is the passport: P12345678X'] as $s) {
    chk("masked: $s", [P::mask($s)['masked'], str_contains(P::mask($s)['text'], P::MASK)], [true, true]);
}
foreach ([
    'My booking reference is ABC123',                         // no word "passport"
    'Call me on 0788611242',                                  // no word "passport"
    'My passport expires 20 Dec 2027',                        // a date, not a number
    'Is my passport ok? I travel 20261220',                   // compact date
    'Passport is ready. My number is +256788611242',          // long phone with +
    'Passport ready, 2 adults 1 child',                       // small numbers
    'Do I need a passport for Kenya?',
    'passport ok, ticket ref XK7P2Q',                         // too few digits
] as $s) {
    chk("left alone: $s", P::mask($s), ['text' => $s, 'masked' => false]);
}
chk('empty text', P::mask(''), ['text' => '', 'masked' => false]);
chk('photos are withheld', [P::withholds('image'), P::withholds('audio'), P::withholds('file'), P::withholds('location')], [true, false, false, false]);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
