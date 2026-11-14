<?php declare(strict_types=1);
// Pure test of App\Services\Travel\Passport\Travellers — what a trip learns from the travellers'
// passports and ID cards: ages, the party, document validity, and how travellers are shown.
// Fictional travellers only. Framework-free.
//   php qa/travel_travellers.php
require __DIR__ . '/../app/Services/Travel/Passport/Mrz.php';
require __DIR__ . '/../app/Services/Travel/Passport/Travellers.php';
require __DIR__ . '/../app/Services/Travel/Enquiry/AirportDirectory.php';
require __DIR__ . '/../app/Services/Travel/Enquiry/EnquiryBrief.php';
require __DIR__ . '/../app/Services/Travel/Enquiry/EnquirySlots.php';

use App\Services\Travel\Enquiry\EnquirySlots as S;
use App\Services\Travel\Passport\Travellers as T;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}
$today = '2026-11-14';
$p = fn (string $name, string $dob, string $exp = '2031-01-01', string $type = 'P') => ['id' => 0, 'name' => $name, 'document_type' => $type, 'issuing_country' => 'UTO',
    'nationality' => 'UTO', 'date_of_birth' => $dob, 'sex' => 'F', 'expiry_date' => $exp, 'last4' => '02C3'];
$anna = $p('ANNA MARIA ERIKSSON', '1974-08-12');
$john = $p('JOHN ERIKSSON', '1972-01-30');
$lina = $p('LINA ERIKSSON', '2018-03-05');
$baby = $p('NOA ERIKSSON', '2025-12-01');
$teen = $p('MAX ERIKSSON', '2009-11-20');
$state = fn (array $over = []) => array_replace_recursive(S::blank(), $over);

echo "== ages are counted on the travel date\n";
chk('the travel date is the first flight when it is ahead', T::travelDate($state(['segments' => [['date' => '2027-03-10']]]), $today), '2027-03-10');
chk('or the start of a safari', T::travelDate($state(['brief' => ['start_date' => '2027-07-12']]), $today), '2027-07-12');
chk('otherwise today', [T::travelDate($state(), $today), T::travelDate($state(['segments' => [['date' => '2026-01-01']]]), $today)], [$today, $today]);
chk('a child who turns 9 before the trip is 9 on the ticket', [T::age($lina, $today), T::age($lina, '2027-03-10')], [8, 9]);
chk('a teenager who turns 17 before travel is still a child', T::age($teen, '2026-12-01'), 17);

echo "== the party, from the documents\n";
chk('two adults and two children', T::party([$anna, $john, $lina, $baby], '2027-03-10'), ['adults' => 2, 'children' => 1, 'infants' => 1, 'child_ages' => [9, 1]]);
chk('nothing stated yet: the documents set the party', T::partyPatch($state(), [], [$anna], $today), ['adults' => 1, 'children' => 0, 'infants' => 0]);
chk('a child\'s passport adds the child and its age', T::partyPatch($state(['adults' => 1, 'children' => 0, 'infants' => 0]), [$anna], [$anna, $lina], $today),
    ['adults' => 1, 'children' => 1, 'infants' => 0, 'child_ages' => [8]]);
chk('a party the customer stated is never changed', T::partyPatch($state(['adults' => 2, 'children' => 0, 'infants' => 0]), [], [$anna], $today), []);
chk('stated "2 adults and 1 child" with no age: the child\'s passport gives the age', T::partyPatch($state(['adults' => 2, 'children' => 1, 'infants' => 0]), [$anna], [$anna, $lina], $today), ['child_ages' => [8]]);
chk('…but not when the documents show a different number of children', T::partyPatch($state(['adults' => 2, 'children' => 2, 'infants' => 0]), [$anna], [$anna, $lina], $today), []);
chk('…and never over ages the customer gave', T::partyPatch($state(['adults' => 2, 'children' => 1, 'infants' => 0, 'brief' => ['child_ages' => [7]]]), [$anna], [$anna, $lina], $today), []);
chk('a party the customer changed after the documents is theirs from then on', T::partyPatch($state(['adults' => 3, 'children' => 0, 'infants' => 0]), [$anna], [$anna, $john], $today), []);
chk('a document with no date of birth changes nothing', T::partyPatch($state(), [], [['name' => 'X', 'date_of_birth' => null]], $today), []);
chk('more documents than travellers is noticed', [T::exceedsParty($state(['adults' => 2]), 3), T::exceedsParty($state(['adults' => 2]), 2), T::exceedsParty($state(), 3)], [true, false, false]);

echo "== the patch is one the trip record accepts\n";
$r = S::apply($state(), T::partyPatch($state(), [], [$anna, $lina], $today), $today, fn ($q) => ['status' => 'none']);
chk('applied to a new trip: 1 adult, 1 child aged 8, no complaint', [$r['state']['adults'], $r['state']['children'], $r['state']['brief']['child_ages'], $r['issues']], [1, 1, [8], []]);

echo "== validity for the trip\n";
chk('a valid passport', T::validity($anna, $today, '2027-03-10', '2027-03-20'), null);
chk('an expired one', T::validity($p('A', '1980-01-01', '2026-01-01'), $today, '2027-03-10', null), 'expired');
chk('one that ends within six months of the return', T::validity($p('A', '1980-01-01', '2027-08-01'), $today, '2027-03-10', '2027-03-20'), 'short');
chk('six months counted from the return when there is one', T::validity($p('A', '1980-01-01', '2027-09-25'), $today, '2027-03-10', '2027-03-20'), null);

echo "== what people see — never the document number\n";
chk('the customer is told the name and age, how to correct it, and how long the number is kept', T::customerLine($anna, $today, null, false),
    "✅ Passport read: *ANNA MARIA ERIKSSON*, age 52.\nThe file itself has not been kept. If the name is not spelt exactly as on the passport, please tell me.\n"
    . "The passport details are kept securely for this booking only and the passport number is deleted 90 days after the booking is closed.");
chk('the agency\'s own retention period is the one stated', str_contains(T::customerLine($anna, $today, null, false, 30), 'deleted 30 days after'), true);
chk('an ID card is called an ID card', str_starts_with(T::customerLine($p('A B', '1990-01-01', '2031-01-01', 'I'), $today, null, false), '✅ ID card read'), true);
chk('an expired document is pointed out', str_contains(T::customerLine($anna, $today, 'expired', false), 'which has passed'), true);
chk('the same document twice', str_starts_with(T::customerLine($anna, $today, null, true), 'I already have this passport'), true);
chk('staff see the details with the number masked', T::staffLine($anna, $today, 'short'),
    'ANNA MARIA ERIKSSON · 52 yrs · born 12 Aug 1974 · F · UTO · passport …02C3 · expires 1 Jan 2031 ⚠ under 6 months validity');
chk('the trip summary lists names and ages only', T::namesLine(T::stateEntries([$anna, $lina], $today)), 'ANNA MARIA ERIKSSON (52), LINA ERIKSSON (8)');
chk('the summary line', in_array('Travellers: ANNA MARIA ERIKSSON (52)', S::summaryLines($state(['adults' => 1, 'travellers' => T::stateEntries([$anna], $today)])), true), true);
chk('a masked number', [T::mask('02C3'), T::mask('')], ['…02C3', '']);
chk('the same document gives the same fingerprint, another key does not', [T::fingerprint('P', 'UTO', 'L898902C3', 'k1') === T::fingerprint('P', 'UTO', 'L898902C3', 'k1'), T::fingerprint('P', 'UTO', 'L898902C3', 'k1') === T::fingerprint('P', 'UTO', 'L898902C3', 'k2')], [true, false]);

echo "== retention: document numbers are deleted after the trip\n";
chk('the setting defaults to 90 days and stays within 7–730', [T::retentionDays(null), T::retentionDays(''), T::retentionDays('abc'), T::retentionDays(30), T::retentionDays(1), T::retentionDays(5000)], [90, 90, 90, 30, 7, 730]);
chk('a trip closed 89 days ago keeps its numbers', T::deletionDue('2026-08-17', '2026-08-17', $today, 90), null);
chk('…closed exactly 90 days ago: deleted today', T::deletionDue('2026-08-16', '2026-08-16', $today, 90), 'closed');
chk('…closed a year ago: deleted', T::deletionDue('2025-11-14', null, $today, 90), 'closed');
chk('an agency with 30 days', [T::deletionDue('2026-10-16', null, $today, 30), T::deletionDue('2026-10-15', null, $today, 30)], [null, 'closed']);
chk('an open trip in use keeps its numbers', T::deletionDue(null, '2026-11-01', $today, 90), null);
chk('an open trip untouched for 180 days is treated as finished', [T::deletionDue(null, '2026-05-18', $today, 90), T::deletionDue(null, '2026-05-19', $today, 90)], ['inactive', null]);
chk('closing wins over activity: a trip closed recently but active long ago keeps its numbers', T::deletionDue('2026-11-01', '2026-01-01', $today, 90), null);
chk('nothing known: nothing deleted', T::deletionDue(null, null, $today, 90), null);
chk('staff see that a number was deleted', str_contains(T::staffLine($anna + ['number_deleted' => true], $today, null), 'passport …02C3 (number deleted)'), true);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
