<?php declare(strict_types=1);
// Pure test of App\Services\Travel\Passport\Mrz — finding and checking the machine-readable zone of a
// passport or ID card in OCR text. Uses the ICAO 9303 specimen documents (the fictional
// "Anna Maria Eriksson" of "Utopia", UTO), never a real person's document. Framework-free.
//   php qa/travel_mrz.php
require __DIR__ . '/../app/Services/Travel/Passport/Mrz.php';

use App\Services\Travel\Passport\Mrz;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}
$today = '2026-11-14';
$pick = fn (?array $r, array $keys) => $r === null ? null : array_intersect_key($r, array_flip($keys));

$TD3 = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";
$TD1 = "I<UTOD231458907<<<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nERIKSSON<<ANNA<MARIA<<<<<<<<<<";
$TD2 = "I<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<\nD231458907UTO7408122F1204159<<<<<<<6";

echo "== check digits (ICAO 9303 worked examples)\n";
chk('document number L898902C3 → 6', Mrz::checkDigit('L898902C3'), 6);
chk('date of birth 740812 → 2', Mrz::checkDigit('740812'), 2);
chk('expiry 120415 → 9', Mrz::checkDigit('120415'), 9);
chk('fillers count as zero', Mrz::checkDigit('<<<<<'), 0);

echo "== the three document formats, read cleanly\n";
$r = Mrz::find($TD3, $today);
chk('passport (TD3): every field', $r, [
    'format' => 'TD3', 'document_type' => 'P', 'issuing_country' => 'UTO', 'surname' => 'ERIKSSON', 'given_names' => 'ANNA MARIA',
    'document_number' => 'L898902C3', 'nationality' => 'UTO', 'date_of_birth' => '1974-08-12', 'sex' => 'F', 'expiry_date' => '2012-04-15',
]);
chk('national ID card back (TD1)', $pick(Mrz::find($TD1, $today), ['format', 'document_type', 'surname', 'given_names', 'document_number', 'date_of_birth', 'expiry_date']),
    ['format' => 'TD1', 'document_type' => 'I', 'surname' => 'ERIKSSON', 'given_names' => 'ANNA MARIA', 'document_number' => 'D23145890', 'date_of_birth' => '1974-08-12', 'expiry_date' => '2012-04-15']);
chk('older ID card (TD2)', $pick(Mrz::find($TD2, $today), ['format', 'surname', 'document_number', 'date_of_birth']),
    ['format' => 'TD2', 'surname' => 'ERIKSSON', 'document_number' => 'D23145890', 'date_of_birth' => '1974-08-12']);

echo "== found inside everything else OCR reads off the page\n";
$page = "REPUBLIC OF UTOPIA\nPASSPORT  PASSEPORT\nSurname ERIKSSON\nGiven names ANNA MARIA\n12 AUG 1974   F\n\n$TD3\n";
chk('printed text above the zone is ignored', Mrz::find($page, $today)['document_number'] ?? null, 'L898902C3');
chk('spaces OCR puts inside the lines', Mrz::find("P<UTO ERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C3 6UTO 7408122 F1204159 ZE184226B<<<<<10", $today)['document_number'] ?? null, 'L898902C3');
chk('trailing fillers dropped by OCR', Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10", $today)['given_names'] ?? null, 'ANNA MARIA');

echo "== look-alike characters are corrected only where the check digits prove it\n";
chk('O read for 0 in a date', Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO74O8122F12O4159ZE184226B<<<<<1O", $today)['date_of_birth'] ?? null, '1974-08-12');
chk('S read for 5 and B for 8 in the dates', Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO74O B122F12O41S9ZE184226B<<<<<10", $today)['expiry_date'] ?? null, '2012-04-15');
chk('B read for 8 inside the document NUMBER is refused, not guessed (only one check digit guards it)', Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nLB98902C36UTO7408122F1204159ZE184226B<<<<<10", $today), null);
chk('0 read for O in the country codes', $pick(Mrz::find("P<UT0ERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UT07408122F1204159ZE184226B<<<<<10", $today), ['issuing_country', 'nationality']), ['issuing_country' => 'UTO', 'nationality' => 'UTO']);
chk('fillers read as K at the end of the name', Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<K<<<<<KK<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10", $today)['given_names'] ?? null, 'ANNA MARIA');

echo "== a misread that the check digits catch is refused, never stored\n";
chk('a wrong digit in the date of birth', Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408132F1204159ZE184226B<<<<<10", $today), null);
chk('a wrong digit in the expiry date', Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204169ZE184226B<<<<<10", $today), null);
chk('a wrong character in the document number', Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898912C36UTO7408122F1204159ZE184226B<<<<<10", $today), null);
// Every single look-alike swap in the specimen number: none may come back as a different number.
$wrong = 0;
foreach (str_split('L898902C3') as $i => $ch) {
    foreach (['O' => '0', '0' => 'O', 'I' => '1', '1' => 'I', 'S' => '5', '5' => 'S', 'B' => '8', '8' => 'B', 'Z' => '2', '2' => 'Z', 'G' => '6', '6' => 'G'] as $from => $to) {
        if ($ch !== (string) $from) continue;
        $n = 'L898902C3'; $n[$i] = (string) $to;
        $got = Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\n{$n}6UTO7408122F1204159ZE184226B<<<<<10", $today);
        if ($got !== null && $got['document_number'] !== 'L898902C3' && $got['document_number'] !== $n) $wrong++;
    }
}
chk('no misread number is ever turned into a different number', $wrong, 0);
chk('a changed personal number (composite check)', Mrz::find("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184227B<<<<<10", $today), null);
chk('only one of the two lines', Mrz::find("L898902C36UTO7408122F1204159ZE184226B<<<<<10", $today), null);
chk('ordinary chat text', Mrz::find("Hi, I want to fly to Dubai on 20 December with my wife and 2 kids", $today), null);
chk('a long booking reference is not a passport', Mrz::find("REF ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ABCDEFGH", $today), null);
chk('a document type that is not travel or ID', Mrz::find("X<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10", $today), null);

echo "== dates\n";
chk('a birth year after this year is last century', Mrz::find($TD3, '2026-11-14')['date_of_birth'] ?? null, '1974-08-12');
$kid = "P<UTOERIKSSON<<LINA<<<<<<<<<<<<<<<<<<<<<<<<<\n" ;
$l2 = 'X12345678' . Mrz::checkDigit('X12345678') . 'UTO' . '180305' . Mrz::checkDigit('180305') . 'F' . '300101' . Mrz::checkDigit('300101') . str_repeat('<', 14) . '0';
$l2 .= (string) Mrz::checkDigit(substr($l2, 0, 10) . substr($l2, 13, 7) . substr($l2, 21, 22));
$kidR = Mrz::find($kid . $l2, $today);
chk('a child born in 2018 (a valid line built here)', [$kidR['date_of_birth'] ?? null, $kidR['given_names'] ?? null, $kidR['expiry_date'] ?? null], ['2018-03-05', 'LINA', '2030-01-01']);
chk('age on a given day', [Mrz::age('2018-03-05', '2026-11-14'), Mrz::age('2018-03-05', '2027-03-05'), Mrz::age('1974-08-12', '2026-11-14')], [8, 9, 52]);
chk('age of an impossible date is unknown', Mrz::age('2030-01-01', '2026-11-14'), null);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
