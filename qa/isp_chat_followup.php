<?php declare(strict_types=1);
// Pure test of the follow-up when an Internet-provider enquiry goes quiet (App\Services\Isp\ChatFollowUp):
// when (day 1, day 4, never after 14 days or 2 touches), who (buyers, not vendors) and the words (no amounts).
//   php qa/isp_chat_followup.php
require __DIR__ . '/../app/Services/Isp/ChatFollowUp.php';

use App\Services\Isp\ChatFollowUp as F;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== when\n";
chk('same day: nothing yet', F::touch(0, 0), null);
chk('a day after their last message: first touch', F::touch(1, 0), 1);
chk('days 2-3 after the first: nothing', [F::touch(2, 1), F::touch(3, 1)], [null, null]);
chk('day 4: second touch', F::touch(4, 1), 2);
chk('a late run still sends the touch that is due', F::touch(5, 0), 1);
chk('after two touches: never again (until they write)', [F::touch(6, 2), F::touch(13, 2)], [null, null]);
chk('older than 14 days: never', F::touch(15, 0), null);

echo "== who\n";
chk('a vendor (billboards)', F::notACustomer(["Dear Team, I'm Stephen from WALMAT. We offer billboard advertising in Juba"]), true);
chk('a partner', F::notACustomer(['Let proceed our partnership agreement.']), true);
chk('a job seeker', F::notACustomer(['I am sending my CV for any vacancy']), true);
chk('a buyer', F::notACustomer(['How much is Starlink for my office?', 'ok thanks']), false);
chk('asked about a service or price: chased', [F::salesEnquiry('warm', ['starlink']), F::salesEnquiry('hot', ['buy']), F::salesEnquiry('warm', ['price'])], [true, true, true]);
chk('only said hello: chased once as a new contact', F::salesEnquiry('cold', []), true);
chk('no sales question at all: not chased', F::salesEnquiry('warm', []), false);

echo "== what the customer reads\n";
$a = F::message(1, 'warm', 'starlink', 'Peter Deng', 'Justus', 'DishNet Africa Limited');
chk('touch 1: first name, salesperson, what they asked, one question', $a, 'Hello Peter, this is Justus from DishNet Africa Limited. I am following up on the Starlink you asked about. Do you have any other questions, or would you like me to prepare a quotation for you?');
chk('they had a quotation: it is named', str_contains(F::message(1, 'warm', 'starlink', 'Peter', 'Justus', 'DishNet', 'ORD-Q261117-3NSO'), 'your quotation ORD-Q261117-3NSO?'), true);
chk('hot: quotation and site survey', str_contains(F::message(1, 'hot', 'fiber', null, 'Justus', 'DishNet'), 'the Fiber internet you wanted. Shall I prepare your quotation and arrange the site survey?'), true);
chk('only said hello: home or business?', str_contains(F::message(1, 'cold', 'unsure', null, 'Justus', 'DishNet'), 'home or for your business'), true);
chk('touch 2: an easy way out', str_contains(F::message(2, 'warm', 'starlink', 'Ann', 'Justus', 'DishNet'), 'I will not disturb you again'), true);
chk('no name, or a number as a name: plain hello', [str_starts_with(F::message(1, 'warm', 'fiber', null, 'Justus', 'DishNet'), 'Hello, this is Justus from DishNet.'), str_starts_with(F::message(1, 'warm', 'fiber', '225683989098601', 'Justus', 'DishNet'), 'Hello, this is Justus from DishNet.')], [true, true]);
chk('no salesperson yet: from the company', str_starts_with(F::message(1, 'warm', 'fiber', 'Ann', null, 'DishNet'), 'Hello Ann, this is DishNet.'), true);
$all = '';
foreach (['hot', 'warm', 'cold', 'price_changed', 'wanted_cheaper'] as $seg) {
    foreach ([1, 2] as $t) { $all .= F::message($t, $seg, 'both', 'Ann', 'Justus', 'DishNet', $t === 1 ? 'Q-1' : null); }
}
chk('never an amount, a discount or a date', preg_match('/\$|USD|SSP|\d+\.\d\d|discount|free|today|tomorrow/i', $all), 0);

echo "\n" . ($fail ? 'FAILURES' : 'ALL PASS') . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
