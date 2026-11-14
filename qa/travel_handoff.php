<?php declare(strict_types=1);
// Pure test of who may receive a travel enquiry: App\Services\Travel\Handoff\StaffHandoff::parseRecipients.
// The agency's staff numbers are separate from the number customers write to. Framework-free.
//   php qa/travel_handoff.php
require __DIR__ . '/../app/Services/Travel/Handoff/StaffHandoff.php';

use App\Services\Travel\Handoff\StaffHandoff as H;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}
$public = ['256788611242'];
$r = fn (string $raw, array $pub = []) => H::parseRecipients($raw, $pub);
$reasons = fn (array $x) => array_column($x['rejected'], 'number');

echo "== reading what an admin typed\n";
chk('one number', $r('256701000001')['recipients'], ['256701000001']);
chk('several, on lines, with spaces and a plus', $r("+256 701 000 001\n256-702-000-002 , 254 711 000 003")['recipients'], ['256701000001', '256702000002', '254711000003']);
chk('a repeated number is used once', $r('256701000001, +256701000001')['recipients'], ['256701000001']);
chk('nothing typed means nobody', $r('')['recipients'], []);
chk('blank lines and stray commas are ignored', $r(" ,\n\n256701000001,, ")['recipients'], ['256701000001']);

echo "== numbers that are refused, with a reason an admin can act on\n";
$x = $r('0701000001');
chk('a local number without the country code is not guessed', [$x['recipients'], $x['rejected'][0]['reason']], [[], 'needs the country code (for example 2567…)']);
chk('too short', $reasons($r('12345')), ['12345']);
chk('words', $r('ask Sarah')['recipients'], []);

echo "== the customer-facing number is never a staff number\n";
$x = $r('256788611242, 256701000001', $public);
chk('the public number is dropped and the staff number kept', [$x['recipients'], $reasons($x)], [['256701000001'], ['256788611242']]);
chk('the reason says why', $x['rejected'][0]['reason'], 'this is the number customers write to; staff alerts are never sent there');
chk('the public number written with a plus and spaces', $r('+256 788 611 242', $public)['recipients'], []);
chk('the public number in a different country-code form (same last nine digits)', $r('00256788611242', $public)['recipients'], []);
chk('only the public number configured: nobody is alerted', $r('256788611242', $public)['recipients'], []);
chk('several public numbers (extra channels)', $r('256788611242, 256700222333, 256701000001', ['256788611242', '+256 700 222 333'])['recipients'], ['256701000001']);
chk('an empty public number rejects nothing', $r('256701000001', ['', null ?? ''])['recipients'], ['256701000001']);

echo "== limits\n";
$many = implode(',', array_map(fn ($i) => '2567010000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 13)));
$x = $r($many);
chk('at most ten recipients', [count($x['recipients']), count($x['rejected'])], [10, 3]);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
