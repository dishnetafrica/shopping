<?php declare(strict_types=1);
// Pure test of the number a salesperson can call when WhatsApp hides the customer's (App\Services\Isp\CallbackNumber):
// reading a phone number the customer typed, and what the alert shows.
//   php qa/isp_callback.php
require __DIR__ . '/../app/Services/Isp/CallbackNumber.php';

use App\Services\Isp\CallbackNumber as N;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== a phone number the customer typed\n";
chk('they call it their number', N::extract('my number is 0912 345 678'), '0912345678');
chk('call me on +211 …', N::extract('call me on +211 912 345 678 please'), '211912345678');
chk('answering our question: a bare number is enough', N::extract('211912345678', true), '211912345678');
chk('answering: 00 prefix', N::extract('00256 772 123456', true), '256772123456');
chk('answering: dashes and brackets', N::extract('(+254) 712-345-678', true), '254712345678');
chk('a US number with "phone"', N::extract('phone +1 202 555 0143'), '12025550143');
chk('the first number when there are two', N::extract('0912345678 or 0923456789', true), '0912345678');
chk('a number and the kit in one message', N::extract('My number is 0912345678, I want the Starlink kit'), '0912345678');
echo "== not a phone number to save\n";
chk('Codex #169: a bare number we did not ask for', N::extract('0912345678'), null);
chk('Codex #169: an account number, even when asked', N::extract('my account number is 211912345678', true), null);
chk('a reference / order / serial number', [N::extract('reference number 0912345678'), N::extract('order no 211912345678', true), N::extract('serial number 0912345678')], [null, null, null]);
foreach (['$550 for the kit' => null, 'USD 550.00 monthly' => null, 'we are 250 users' => null, 'quotation ORD-Q261117-3NSO' => null,
          'on 18/11/2026' => null, '234239681409272' => null, 'about 912345678 maybe' => null, 'hello' => null, '' => null] as $text => $want) {
    chk('"' . $text . '" (even when asked)', N::extract((string) $text, true), $want);
}
echo "== did our message ask for a number?\n";
chk('the bot asked', N::asksForNumber('Thank you! What is the best phone number to reach you on?'), true);
chk('the missed-call message asked', N::asksForNumber('Please send us the best phone number to reach you on.'), true);
chk('other wordings', [N::asksForNumber('Can you share your number so Akol can call?'), N::asksForNumber('Which contact number should we use?'), N::asksForNumber('a number to call you on')], [true, true, true]);
chk('not asking', [N::asksForNumber('How many users will connect?'), N::asksForNumber('Quotation number ORD-1 is attached.')], [false, false]);

echo "== what the salesperson's alert shows\n";
chk('a visible number', N::labelFor('211984176391', false, null), '+211984176391');
chk('hidden, with a number to call', N::labelFor('234239681409272', true, '0912345678'), '0912345678 (WhatsApp hides their number in the chat — call this one)');
chk('hidden, international number', N::labelFor('234239681409272', true, '211912345678'), '+211912345678 (WhatsApp hides their number in the chat — call this one)');
chk('hidden, no number: said plainly, never a fake "+" number', N::labelFor('234239681409272', true, null), "WhatsApp hides this customer's number — reply to them in the ShopBot inbox (chat 234239681409272)");
chk('display: local stays local', [N::display('0912345678'), N::display('211912345678')], ['0912345678', '+211912345678']);

echo "\n" . ($fail ? 'FAILURES' : 'ALL PASS') . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
