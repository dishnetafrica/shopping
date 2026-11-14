<?php declare(strict_types=1);
// Pure test of the travel enquiry BRIEF: services, several destinations, approximate dates, children's
// ages, purpose, budget, accommodation and contact details, merged over many messages. Framework-free.
//   php qa/travel_brief.php
require __DIR__ . '/../app/Services/Travel/Enquiry/AirportDirectory.php';
require __DIR__ . '/../app/Services/Travel/Enquiry/EnquiryBrief.php';
require __DIR__ . '/../app/Services/Travel/Enquiry/EnquirySlots.php';

use App\Services\Travel\Enquiry\AirportDirectory;
use App\Services\Travel\Enquiry\EnquiryBrief as B;
use App\Services\Travel\Enquiry\EnquirySlots as S;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}
$today = '2026-11-14';
$place = fn (string $q) => AirportDirectory::shared()->resolve($q);
$say = function (array $state, array $patch) use ($today, $place) { return S::apply($state, $patch, $today, $place); };
$codes = fn (array $r) => array_column($r['issues'], 'code');

echo "== a safari enquiry over several messages (no flight)\n";
$r = $say(S::blank(), ['services' => ['safari']]);
chk('service recorded', $r['state']['brief']['services'], ['safari']);
chk('asks where, when and who — never flight questions', S::missing($r['state']), ['destinations', 'travel_dates', 'passengers']);
$r = $say($r['state'], ['destinations' => ['Bwindi', 'Queen Elizabeth National Park']]);
chk('two destinations kept in order', $r['state']['brief']['destinations'], ['Bwindi', 'Queen Elizabeth National Park']);
chk('no flight segment was created for a park', $r['state']['segments'], []);
$r = $say($r['state'], ['date_text' => 'July 2027']);
chk('an approximate date is enough to proceed', S::missing($r['state']), ['passengers']);
$r = $say($r['state'], ['adults' => 2, 'children' => 2]);
chk('children need their ages', S::missing($r['state']), ['child_ages']);
chk('not complete without ages', S::isComplete($r['state']), false);
$r = $say($r['state'], ['child_ages' => [6, 9]]);
chk('ages stored', $r['state']['brief']['child_ages'], [6, 9]);
chk('complete', S::isComplete($r['state']), true);
$full = $r['state'];
chk('summary', S::summaryLines($full), ['Services: Safari', 'Destinations: Bwindi → Queen Elizabeth National Park', 'When: July 2027 (dates not fixed)', "Children's ages: 6, 9", '2 adults, 2 children']);

echo "== corrections keep everything else\n";
$r = $say($full, ['start_date' => '2027-07-12', 'end_date' => '2027-07-19']);
chk('exact dates replace the approximate ones', [$r['state']['brief']['start_date'], $r['state']['brief']['end_date'], $r['state']['brief']['date_text']], ['2027-07-12', '2027-07-19', null]);
chk('destinations and party untouched', [$r['state']['brief']['destinations'], $r['state']['adults'], $r['state']['children'], $r['state']['brief']['child_ages']], [['Bwindi', 'Queen Elizabeth National Park'], 2, 2, [6, 9]]);
$r2 = $say($r['state'], ['destinations' => ['Bwindi', 'Murchison Falls', 'Jinja']]);
chk('the destination list is replaced by the new full list', $r2['state']['brief']['destinations'], ['Bwindi', 'Murchison Falls', 'Jinja']);
chk('dates survive a destination change', $r2['state']['brief']['start_date'], '2027-07-12');
$r2 = $say($r['state'], ['services' => ['hotel', 'transfer']]);
chk('a mentioned service is added, none is dropped', $r2['state']['brief']['services'], ['safari', 'hotel', 'transfer']);
$r2 = $say($r2['state'], ['remove_services' => ['transfer']]);
chk('a service is removed only when asked', $r2['state']['brief']['services'], ['safari', 'hotel']);
$r2 = $say($r['state'], ['accommodation' => 'mid-range lodges', 'purpose' => 'family holiday']);
chk('preferences added', [$r2['state']['brief']['accommodation'], $r2['state']['brief']['purpose']], ['mid-range lodges', 'family holiday']);
chk('adding a preference re-sends nothing essential', array_intersect(['destinations', 'start_date', 'adults'], $r2['changed']), []);
$r2 = $say($r['state'], ['clear' => ['end_date']]);
chk('an optional detail can be removed', $r2['state']['brief']['end_date'], null);

echo "== services by the words customers use\n";
foreach (['air ticket' => 'flights', 'Flights' => 'flights', 'hotel booking' => 'hotel', 'gorilla trekking' => 'safari', 'holiday package' => 'tour',
    'airport pickup' => 'transfer', 'car rental' => 'car_hire', 'visa processing' => 'visa', 'travel insurance' => 'insurance',
    'courier' => 'courier', 'reschedule' => 'ticket_change', 'car_hire' => 'car_hire', 'something else entirely' => 'other'] as $word => $want) {
    chk("service: $word", B::service($word), $want);
}
chk('services given as one string', $say(S::blank(), ['services' => 'visa, insurance'])['state']['brief']['services'], ['visa', 'insurance']);

echo "== dates\n";
$r = $say(S::blank(), ['services' => ['hotel'], 'depart_date' => '2027-01-10', 'return_date' => '2027-01-15']);
chk('with no flight, "depart" and "return" are the stay dates', [$r['state']['brief']['start_date'], $r['state']['brief']['end_date'], $r['state']['segments']], ['2027-01-10', '2027-01-15', []]);
$r = $say(S::blank(), ['services' => ['flights', 'hotel'], 'to' => 'Dubai', 'depart_date' => '2027-01-10']);
chk('with a flight, the date stays a departure date', [$r['state']['segments'][0]['date'], $r['state']['brief']['start_date']], ['2027-01-10', null]);
chk('flight and hotel: the flight questions apply', S::missing($r['state']), ['origin', 'trip_type', 'passengers']);
$r = $say(S::blank(), ['services' => ['safari'], 'start_date' => '2026-01-05']);
chk('a past date is refused', [$codes($r), $r['state']['brief']['start_date']], [['date_past'], null]);
$r = $say(S::blank(), ['services' => ['safari'], 'start_date' => '2027-08-10', 'end_date' => '2027-08-01']);
chk('an end before the start is refused, the start kept', [$codes($r), $r['state']['brief']['start_date'], $r['state']['brief']['end_date']], [['end_before_start'], '2027-08-10', null]);
$r = $say(S::blank(), ['services' => ['tour'], 'start_date' => '2028-06-01']);
chk('a holiday may be planned more than a year ahead', $r['state']['brief']['start_date'], '2028-06-01');
$r = $say(S::blank(), ['services' => ['tour'], 'start_date' => '2030-06-01']);
chk('but not more than two years', $codes($r), ['date_far']);
chk('nights', $say(S::blank(), ['nights' => 7])['state']['brief']['nights'], 7);
chk('absurd nights refused', $codes($say(S::blank(), ['nights' => 400])), ['bad_nights']);

echo "== children's ages\n";
$r = $say(S::blank(), ['services' => ['tour'], 'adults' => 2, 'child_ages' => [1, 4, 11]]);
chk('ages alone give the party: 2 children and 1 infant', [$r['state']['children'], $r['state']['infants'], $r['state']['brief']['child_ages']], [2, 1, [1, 4, 11]]);
$r = $say(S::blank(), ['adults' => 2, 'children' => 1, 'child_ages' => [5, 8, 12]]);
chk('more ages than children is questioned, not stored', [$codes($r), $r['state']['brief']['child_ages'], $r['state']['children']], [['child_ages_mismatch'], [], 1]);
$r = $say(S::blank(), ['adults' => 2, 'children' => 3, 'child_ages' => [5]]);
chk('fewer ages than children: still needed', S::missing($r['state']), ['service', 'destinations', 'travel_dates', 'child_ages']);
chk('an impossible age is refused', $codes($say(S::blank(), ['child_ages' => [5, 40]])), ['bad_child_age']);
$r = $say(S::blank(), ['services' => ['safari'], 'adults' => 0, 'children' => 2]);
chk('children alone are still not accepted', $codes($r), ['minor_alone']);
$r = $say(S::blank(), ['services' => ['safari'], 'adults' => 14]);
chk('a group is flagged for a person', [$codes($r), S::isGroup($r['state'])], [['group_booking'], true]);

echo "== budget is the customer's figure, never ours\n";
$r = $say(S::blank(), ['budget' => 3000, 'budget_currency' => 'usd', 'budget_per' => 'per person']);
chk('budget stored', $r['state']['brief']['budget'], ['amount' => 3000, 'currency' => 'USD', 'per' => 'person']);
chk('budget line', B::budgetLine($r['state']['brief']['budget']), 'USD 3,000 per person');
$r = $say(S::blank(), ['budget' => 5000000]);
chk('an amount with no currency is kept and the currency asked once', [$r['state']['brief']['budget'], B::softQuestions($r['state']), B::budgetLine($r['state']['brief']['budget'])], [['amount' => 5000000, 'currency' => null, 'per' => null], ['budget_currency'], '5,000,000 (currency not stated)']);
chk('a missing currency never blocks the enquiry', in_array('budget_currency', S::missing($r['state']), true), false);
$r = $say($r['state'], ['budget_currency' => 'UGX']);
chk('currency added later keeps the amount', $r['state']['brief']['budget'], ['amount' => 5000000, 'currency' => 'UGX', 'per' => null]);
chk('"shillings" alone is ambiguous', $codes($say($r['state'], ['budget_currency' => 'shillings'])), ['bad_currency']);
chk('a made-up currency is refused', $codes($say($r['state'], ['budget_currency' => 'XYZ'])), ['bad_currency']);
chk('a zero budget is refused', $codes($say(S::blank(), ['budget' => 0])), ['bad_budget']);
chk('dollar sign', $say(S::blank(), ['budget' => 800, 'budget_currency' => '$'])['state']['brief']['budget']['currency'], 'USD');

echo "== contact details\n";
$r = $say(S::blank(), ['contact_name' => '  Sarah  Namusoke ', 'contact_phone' => '+256 700 111 222', 'contact_email' => 'Sarah@Example.com']);
chk('contact stored and tidied', $r['state']['brief']['contact'], ['name' => 'Sarah Namusoke', 'phone' => '256700111222', 'email' => 'sarah@example.com']);
chk('contact line', B::contactLine($r['state']), 'Sarah Namusoke · +256700111222 · sarah@example.com');
chk('a bad phone is refused', $codes($say(S::blank(), ['contact_phone' => '12345'])), ['bad_phone']);
chk('a bad email is refused', $codes($say(S::blank(), ['contact_email' => 'not-an-email'])), ['bad_email']);

echo "== requests that are not a trip\n";
$r = $say(S::blank(), ['services' => ['ticket_change']]);
chk('a ticket change needs only what the customer wants done', S::missing($r['state']), ['request_details']);
$r = $say($r['state'], ['notes' => 'move my 3 Dec Emirates flight to 10 Dec']);
chk('then it is complete', S::isComplete($r['state']), true);
$r = $say(S::blank(), ['services' => ['visa'], 'destinations' => ['United Kingdom'], 'date_text' => 'March', 'adults' => 1]);
chk('a visa enquiry: country, when and who', S::isComplete($r['state']), true);

echo "== limits and bad input\n";
$r = $say(S::blank(), ['destinations' => ['A1', 'B2', 'C3', 'D4', 'E5', 'F6', 'G7', 'H8', 'I9', 'J10']]);
chk('at most eight destinations', [count($r['state']['brief']['destinations']), $codes($r)], [8, ['too_many_destinations']]);
chk('a repeated destination is listed once', $say(S::blank(), ['destinations' => ['Zanzibar', 'zanzibar', 'Arusha']])['state']['brief']['destinations'], ['Zanzibar', 'Arusha']);
chk('an empty list changes nothing', $say($full, ['destinations' => []])['state']['brief']['destinations'], ['Bwindi', 'Queen Elizabeth National Park']);
chk('nonsense values do not break it', $say($full, ['services' => 7, 'destinations' => 'x', 'child_ages' => 'two', 'budget' => 'lots', 'contact_name' => ['a']])['state']['brief']['destinations'], ['x']);
chk('an old row with no brief is read as an empty one', B::normalise(null), B::blank());
chk('an old flight row still works', S::isComplete(['trip_type' => 'one_way', 'adults' => 1, 'segments' => [['from' => ['label' => 'A', 'codes' => ['AAA']], 'to' => ['label' => 'B', 'codes' => ['BBB']], 'date' => '2027-01-01']]]), true);
chk('nothing in a brief is ever a price from us', array_keys(B::blank()), ['services', 'destinations', 'departure', 'start_date', 'end_date', 'date_text', 'nights', 'child_ages', 'purpose', 'accommodation', 'budget', 'contact']);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
