<?php declare(strict_types=1);
// Pure test of the Travel Vertical trip record: App\Services\Travel\Enquiry\EnquirySlots merging what
// a customer says into the stored trip, with the real AirportDirectory. Framework-free.
//   php qa/travel_slots.php
require __DIR__ . '/../app/Services/Travel/Enquiry/AirportDirectory.php';
require __DIR__ . '/../app/Services/Travel/Enquiry/EnquiryBrief.php';
require __DIR__ . '/../app/Services/Travel/Enquiry/EnquirySlots.php';
require __DIR__ . '/../app/Services/Travel/Passport/Mrz.php';
require __DIR__ . '/../app/Services/Travel/Passport/Travellers.php';

use App\Services\Travel\Enquiry\AirportDirectory;
use App\Services\Travel\Enquiry\EnquirySlots as S;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}
$dir = new AirportDirectory();
$res = fn (string $q) => $dir->resolve($q);
$today = '2026-11-13';
$apply = fn (array $st, array $patch) => S::apply($st, $patch, $today, $res);
$codes = fn (array $r, string $f) => array_values(array_unique(array_column(array_filter($r['issues'], fn ($i) => $i['field'] === $f || $f === '*'), 'code')));

echo "== airport directory\n";
chk('Entebbe is one airport', $dir->resolve('Entebbe')['codes'], ['EBB']);
chk('Kampala means Entebbe airport', $dir->resolve('kampala')['codes'], ['EBB']);
chk('Kampala keeps the customer word', $dir->resolve('kampala')['label'], 'Kampala (EBB), Uganda');
chk('Dubai keeps both airports', $dir->resolve('Dubai')['codes'], ['DWC', 'DXB']);
chk('upper-case code is a code', $dir->resolve('DXB')['codes'], ['DXB']);
chk('lower-case 3 letters is NOT read as a code', $dir->resolve('dxb')['status'], 'unknown');
chk('"Goa" is the destination, not Genoa (GOA)', $dir->resolve('Goa')['codes'], ['GOI']);
chk('London resolves to the UK (dominant)', $dir->resolve('London')['country'], 'GB');
chk('London Canada is honoured', $dir->resolve('London Canada')['codes'], ['YXU']);
chk('Birmingham is ambiguous', $dir->resolve('Birmingham')['status'], 'ambiguous');
chk('Birmingham UK is not', $dir->resolve('Birmingham UK')['codes'], ['BHX']);
chk('airport name works', $dir->resolve('Heathrow')['codes'], ['LHR']);
chk('nickname works', $dir->resolve('joburg')['city'], 'Johannesburg');
chk('accents fold', $dir->resolve('São Paulo')['status'], 'ok');
chk('a country with one main airport', $dir->resolve('Rwanda')['codes'], ['KGL']);
chk('a country with several asks for a city', [$dir->resolve('Kenya')['status'], $dir->resolve('Kenya')['hint'] ?? ''], ['unknown', 'country']);
chk('nonsense is unknown', $dir->resolve('Xyzzyville')['status'], 'unknown');
chk('empty is unknown', $dir->resolve('  ')['status'], 'unknown');

echo "== building a trip over several messages\n";
$r = $apply(S::blank(), ['to' => 'Dubai']);
chk('destination stored', $r['state']['segments'][0]['to']['city'], 'Dubai');
chk('only destination changed', $r['changed'], ['destination']);
chk('missing after destination', S::missing($r['state']), ['origin', 'depart_date', 'trip_type', 'passengers']);
$r = $apply($r['state'], ['from' => 'Entebbe', 'depart_date' => '2026-12-20', 'return_date' => '2027-01-04', 'adults' => 2]);
chk('return date implies a return trip', $r['state']['trip_type'], 'return');
chk('complete', S::isComplete($r['state']), true);
chk('nothing missing', S::missing($r['state']), []);
$full = $r['state'];
chk('summary lines', S::summaryLines($full), [
    'Return: Entebbe (EBB), Uganda → Dubai, United Arab Emirates',
    'Depart 20 Dec 2026 · Return 4 Jan 2027',
    '2 adults',
]);

echo "== changes keep everything else (requirement F)\n";
$r = $apply($full, ['depart_date' => '2026-12-22']);
chk('date change: only the date changed', $r['changed'], ['depart_date']);
chk('date change: new date stored', $r['state']['segments'][0]['date'], '2026-12-22');
chk('date change: route kept', [$r['state']['segments'][0]['from']['codes'], $r['state']['segments'][0]['to']['city']], [['EBB'], 'Dubai']);
chk('date change: passengers kept', $r['state']['adults'], 2);
chk('date change: return kept', $r['state']['return_date'], '2027-01-04');
chk('date change: still complete', S::isComplete($r['state']), true);

$r = $apply($full, ['adults' => 3, 'children' => 1]);
chk('passenger change: only passengers', $r['changed'], ['adults', 'children']);
chk('passenger change: totals', [$r['state']['adults'], $r['state']['children']], [3, 1]);
chk('passenger change: dates kept', [$r['state']['segments'][0]['date'], $r['state']['return_date']], ['2026-12-20', '2027-01-04']);

$r = $apply($full, ['to' => 'Nairobi']);
chk('destination change: only destination', $r['changed'], ['destination']);
chk('destination change: new city', $r['state']['segments'][0]['to']['city'], 'Nairobi');
chk('destination change: origin + dates + pax kept', [$r['state']['segments'][0]['from']['codes'], $r['state']['segments'][0]['date'], $r['state']['adults']], [['EBB'], '2026-12-20', 2]);

$r = $apply($full, ['cabin' => 'Business', 'airlines' => ['Emirates', 'Qatar Airways'], 'direct_only' => true, 'baggage' => '2 x 23kg', 'flexible_days' => 2]);
chk('preference change: fields', $r['changed'], ['cabin', 'airlines', 'baggage', 'flexible_days', 'direct_only']);
chk('preference change: trip untouched', [$r['state']['segments'], $r['state']['return_date'], $r['state']['adults']], [$full['segments'], $full['return_date'], $full['adults']]);
$pref = $r['state'];
$r = $apply($pref, ['to' => 'Doha']);
chk('preferences survive a destination change', [$r['state']['cabin'], $r['state']['airlines'], $r['state']['direct_only']], ['business', ['Emirates', 'Qatar Airways'], true]);

$r = $apply($full, ['trip_type' => 'one way']);
chk('unknown trip-type word is ignored', $r['changed'], []);
$r = $apply($full, ['trip_type' => 'one-way']);
chk('return → one way clears the return date', [$r['state']['trip_type'], $r['state']['return_date']], ['one_way', null]);
chk('return → one way still complete', S::isComplete($r['state']), true);
$r = $apply($r['state'], ['trip_type' => 'return']);
chk('one way → return now needs a return date', S::missing($r['state']), ['return_date']);

$r = $apply($full, ['adults' => 2, 'to' => 'Dubai', 'depart_date' => '2026-12-20']);
chk('repeating the same details changes nothing', $r['changed'], []);
$r = $apply($full, ['clear' => ['return_date', 'bogus']]);
chk('clear removes only allowed fields', [$r['changed'], $r['state']['return_date']], [['return_date'], null]);
$r = $apply($full, []);
chk('empty patch is a no-op', [$r['changed'], $r['issues'], $r['state']], [[], [], $full]);

echo "== conflicts ask instead of guessing\n";
$r = $apply($full, ['depart_date' => '2027-01-10']);
chk('departure after the stored return: return cleared', $r['state']['return_date'], null);
chk('departure after the stored return: issue raised', $codes($r, 'return_date'), ['return_before_departure']);
chk('departure after the stored return: asks for return', S::missing($r['state']), ['return_date']);
$r = $apply($full, ['return_date' => '2026-12-01']);
chk('return before departure refused', [$r['state']['return_date'], $codes($r, 'return_date')], [null, ['return_before_departure']]);
$r = $apply($full, ['depart_date' => '2026-11-01']);
chk('past date refused, old date kept', [$r['state']['segments'][0]['date'], $codes($r, 'depart_date')], ['2026-12-20', ['date_past']]);
$r = $apply($full, ['depart_date' => '2028-06-01']);
chk('date beyond a year refused', $codes($r, 'depart_date'), ['date_far']);
$r = $apply($full, ['depart_date' => '20 Dec']);
chk('non-ISO date refused, never guessed', [$r['state']['segments'][0]['date'], $codes($r, 'depart_date')], ['2026-12-20', ['bad_date']]);
$r = $apply($full, ['depart_date' => '2027-02-30']);
chk('impossible date refused', $codes($r, 'depart_date'), ['bad_date']);
$r = $apply($full, ['depart_date' => $today]);
chk('today is allowed', $r['state']['segments'][0]['date'], $today);
$r = $apply($full, ['to' => 'Entebbe']);
chk('same origin and destination refused, old kept', [$r['state']['segments'][0]['to']['city'], $codes($r, 'destination')], ['Dubai', ['same_place']]);
$r = $apply($full, ['to' => 'Birmingham']);
chk('ambiguous city: old destination kept', $r['state']['segments'][0]['to']['city'], 'Dubai');
chk('ambiguous city: open question recorded', [$r['state']['pending'][0]['kind'], $r['state']['pending'][0]['field']], ['ambiguous_place', 'destination']);
chk('ambiguous city: not complete while the question is open', S::isComplete($r['state']), false);
$r2 = $apply($r['state'], ['to' => 'Birmingham UK']);
chk('answering the question clears it', [$r2['state']['pending'], $r2['state']['segments'][0]['to']['codes']], [[], ['BHX']]);
$r = $apply($full, ['to' => 'Xyzzyville']);
chk('unknown place: old kept, issue raised', [$r['state']['segments'][0]['to']['city'], $codes($r, 'destination')], ['Dubai', ['unknown_place']]);
$r = $apply($full, ['to' => 'Kenya']);
chk('a country with several airports asks for the city', $codes($r, 'destination'), ['need_city']);

echo "== passengers\n";
$r = $apply($full, ['infants' => 3]);
chk('more infants than adults refused', [$r['state']['infants'], $codes($r, 'infants')], [null, ['infant_ratio']]);
$r = $apply($full, ['infants' => 2]);
chk('one infant per adult allowed', $r['state']['infants'], 2);
$r = $apply(S::blank(), ['children' => 2, 'adults' => 0]);
chk('children without an adult refused', [$r['state']['adults'], $codes($r, 'adults')], [null, ['minor_alone']]);
$r = $apply($full, ['adults' => 0]);
chk('zero adults refused, old kept', [$r['state']['adults'], $codes($r, 'adults')], [2, ['no_adult']]);
$r = $apply($full, ['adults' => 12]);
chk('group size is stored and flagged', [$r['state']['adults'], $codes($r, 'passengers'), S::isGroup($r['state'])], [12, ['group_booking'], true]);
$r = $apply($full, ['adults' => 'two']);
chk('non-numeric passenger count ignored', $r['changed'], []);
chk('pax line', S::paxLine(['adults' => 2, 'children' => 1, 'infants' => 1]), '2 adults, 1 child, 1 infant');

echo "== multi-city\n";
$r = $apply(S::blank(), ['trip_type' => 'multi_city', 'adults' => 1, 'segments' => [
    ['from' => 'Entebbe', 'to' => 'Nairobi', 'date' => '2026-12-01'],
    ['from' => 'Nairobi', 'to' => 'Dubai', 'date' => '2026-12-05'],
    ['from' => 'Dubai', 'to' => 'Entebbe', 'date' => '2026-12-12'],
]]);
chk('three flights stored', count($r['state']['segments']), 3);
chk('multi-city complete', S::isComplete($r['state']), true);
$mc = $r['state'];
$r = $apply($mc, ['segment' => ['index' => 2, 'to' => 'Doha']]);
chk('edit flight 2 destination only', [$r['state']['segments'][1]['to']['city'], $r['state']['segments'][1]['from']['city'], $r['state']['segments'][1]['date']], ['Doha', 'Nairobi', '2026-12-05']);
chk('other flights untouched', [$r['state']['segments'][0], $r['state']['segments'][2]], [$mc['segments'][0], $mc['segments'][2]]);
$r = $apply($mc, ['segment' => ['index' => 3, 'date' => '2026-12-03']]);
chk('a flight before the previous one is refused', [$r['state']['segments'][2]['date'], $codes($r, 'segment_3')], [null, ['segment_order']]);
$r = $apply($mc, ['segment' => ['index' => 9, 'to' => 'Doha']]);
chk('editing a flight that does not exist is refused', [$r['changed'], $codes($r, 'segments')], [[], ['bad_segment']]);
$r = $apply($mc, ['segment' => ['index' => 4, 'from' => 'Entebbe', 'to' => 'Kigali', 'date' => '2026-12-20']]);
chk('adding a fourth flight', count($r['state']['segments']), 4);
$r = $apply(S::blank(), ['segments' => [['from' => 'Entebbe', 'to' => 'Nairobi', 'date' => '2026-12-01'], ['from' => 'Nairobi', 'to' => 'Dubai']]]);
chk('two flights make it multi-city', $r['state']['trip_type'], 'multi_city');
chk('incomplete flight is named', S::missing($r['state']), ['segment_2', 'passengers']);
$many = array_fill(0, 9, ['from' => 'Entebbe', 'to' => 'Nairobi', 'date' => '2026-12-01']);
$r = $apply(S::blank(), ['segments' => $many]);
chk('flight list is capped', count($r['state']['segments']), S::MAX_SEGMENTS);

echo "== robustness\n";
$r = $apply($full, ['to' => ['x'], 'adults' => null, 'airlines' => 'Emirates', 'segments' => 'no', 'segment' => 5, 'direct_only' => 'maybe']);
chk('malformed values never throw and change nothing', [$r['changed'], $r['state']], [[], $full]);
$r = $apply($full, ['notes' => str_repeat('x', 900), 'baggage' => "  two   bags \n"]);
chk('free text is trimmed and capped', [mb_strlen($r['state']['notes']), $r['state']['baggage']], [300, 'two bags']);
$r = $apply($full, ['airlines' => ['Emirates', 'Emirates', '', 'A', 'B', 'C', 'D', 'E']]);
chk('airline list is de-duplicated and capped', count($r['state']['airlines']), 5);
chk('blank state has every key', array_keys(S::blank()), ['trip_type', 'segments', 'return_date', 'adults', 'children', 'infants', 'cabin', 'airlines', 'baggage', 'flexible_days', 'direct_only', 'notes', 'pending', 'brief', 'travellers']);
// Nothing says this is a flight yet, so the flight questions are not assumed.
chk('partial stored state is tolerated', S::missing(['adults' => 1]), ['service', 'destinations', 'travel_dates']);
chk('a stored flight detail brings the flight questions', S::missing(['adults' => 1, 'trip_type' => 'return']), ['destination', 'origin', 'depart_date', 'return_date']);
chk('nice date', S::niceDate('2027-01-04'), '4 Jan 2027');

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
