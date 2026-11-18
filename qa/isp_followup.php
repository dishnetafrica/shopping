<?php declare(strict_types=1);
// Pure test of the quotation follow-up an Internet provider's AI relationship manager sends
// (App\Services\Isp\QuoteFollowUp): when (day 2, day 5, never after 14 days or 2 touches) and the words
// (the salesperson's name, the quotation number and validity date — never an amount or a promise).
//   php qa/isp_followup.php
require __DIR__ . '/../app/Services/Isp/QuoteFollowUp.php';

use App\Services\Isp\QuoteFollowUp as F;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== when\n";
chk('day 0 and 1: nothing yet', [F::touch(0, 0), F::touch(1, 0)], [null, null]);
chk('day 2: first touch', F::touch(2, 0), 1);
chk('day 3-4 after the first: nothing', [F::touch(3, 1), F::touch(4, 1)], [null, null]);
chk('day 5: second touch', F::touch(5, 1), 2);
chk('a late run still sends the touch that is due (first, on day 6)', F::touch(6, 0), 1);
chk('after two touches: never again', [F::touch(7, 2), F::touch(12, 2)], [null, null]);
chk('older than 14 days: never', F::touch(15, 0), null);

echo "== what the customer reads\n";
$a = F::message(1, 'Peter Deng', 'Justus', 'DishNet Africa Limited', 'ORD-Q261117-3NSO', '24 Nov 2026');
chk('touch 1: first name, salesperson, quotation number', $a, 'Hello Peter, this is Justus from DishNet Africa Limited. Did you get a chance to look at your quotation ORD-Q261117-3NSO? If you have any question about it, or you would like to go ahead, just reply here and I will arrange it.');
$b = F::message(2, 'Peter', 'Justus', 'DishNet Africa Limited', 'ORD-Q261117-3NSO', '24 Nov 2026');
chk('touch 2: validity date, book or adjust, and an easy way out', [str_contains($b, 'valid until 24 Nov 2026'), str_contains($b, 'book your installation'), str_contains($b, 'I will not disturb you')], [true, true, true]);
chk('no name (or a number as a name): plain hello', [str_starts_with(F::message(1, null, 'Justus', 'DishNet', 'Q1', null), 'Hello, this is Justus from DishNet.'), str_starts_with(F::message(1, '225683989098601', 'Justus', 'DishNet', 'Q1', null), 'Hello, this is Justus from DishNet.')], [true, true]);
chk('no salesperson yet: from the company', str_starts_with(F::message(1, 'Ann', null, 'DishNet', 'Q1', null), 'Hello Ann, this is DishNet.'), true);
chk('no validity date: the sentence is left out', str_contains(F::message(2, 'Ann', 'Justus', 'DishNet', 'Q1', null), 'valid until'), false);
$all = F::message(1, 'Ann', 'Justus', 'DishNet', 'Q-1', '1 Dec 2026') . F::message(2, 'Ann', 'Justus', 'DishNet', 'Q-1', null);
chk('no amount or currency in any follow-up', preg_match('/\$|USD|SSP|\d+\.\d\d/', $all), 0);

echo "\n" . ($fail ? 'FAILURES' : 'ALL PASS') . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
