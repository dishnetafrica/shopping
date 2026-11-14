<?php declare(strict_types=1);
// Real OCR test of App\Services\Travel\Passport\MrzReader with the real `tesseract` program, on
// synthetic images of the ICAO 9303 specimen documents (fictional "Anna Maria Eriksson" of Utopia,
// qa/fixtures/mrz). Not phone photos of real passports: real-world accuracy must be measured on staging.
// Needs tesseract (apt install tesseract-ocr) and pdftoppm (poppler-utils); SKIPS if tesseract is missing.
//   php qa/travel_mrz_ocr.php
require __DIR__ . '/../app/Services/Travel/Passport/Mrz.php';
require __DIR__ . '/../app/Services/Travel/Passport/MrzReader.php';

use App\Services\Travel\Passport\MrzReader;

if (! MrzReader::available()) {
    echo "SKIPPED: tesseract is not installed\n";
    exit(0);
}

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}
$dir = __DIR__ . '/fixtures/mrz/';
$reader = new MrzReader();
$slowest = 0.0;
$read = function (string $file, string $mime) use ($reader, $dir, &$slowest): array {
    $t = microtime(true);
    $r = $reader->read((string) file_get_contents($dir . $file), $mime, '2026-11-14');
    $took = microtime(true) - $t;
    $slowest = max($slowest, $took);
    printf("     %-26s %-10s %d attempt(s) %.1fs\n", $file, $r['status'], $r['attempts'], $took);

    return $r;
};
$key = fn (array $r) => $r['mrz'] === null ? null : [$r['mrz']['document_number'], $r['mrz']['date_of_birth'], $r['mrz']['expiry_date'], $r['mrz']['nationality']];
$want = ['L898902C3', '1974-08-12', '2012-04-15', 'UTO'];

echo "== passports and ID cards that should be read\n";
chk('a straight photo of the photo page', $key($read('passport-clean.jpg', 'image/jpeg')), $want);
chk('a small, heavily compressed photo', $key($read('passport-small.jpg', 'image/jpeg')), $want);
chk('a photo taken sideways', $key($read('passport-sideways.jpg', 'image/jpeg')), $want);
chk('a photo upside down', $key($read('passport-upside-down.jpg', 'image/jpeg')), $want);
chk('a PDF scan', $key($read('passport-scan.pdf', 'application/pdf')), $want);
chk('the back of a national ID card', $key($read('idcard-back.jpg', 'image/jpeg')), ['D23145890', '1974-08-12', '2012-04-15', 'UTO']);

echo "== what must never produce a wrong reading\n";
$b = $read('passport-blurred.jpg', 'image/jpeg');
chk('a blurred photo is either refused or read correctly — never read wrongly', $b['mrz'] === null || $key($b) === $want, true);
chk('a photo that is not a passport', $read('not-a-passport.jpg', 'image/jpeg')['status'], 'unreadable');
chk('bytes that are not an image', $reader->read('hello', 'image/jpeg', '2026-11-14')['status'], 'unsupported');

echo "== time: a reading must end well inside the message job's 30 s lock and 60 s worker limit\n";
chk('no reading took longer than the budget plus one second', $slowest <= MrzReader::TOTAL_BUDGET + 1, true);
chk('the budget leaves room inside the 30 s conversation lock', MrzReader::TOTAL_BUDGET + MrzReader::RUN_TIMEOUT <= 30, true);

echo "== temporary files are always removed\n";
$tmp = rtrim(sys_get_temp_dir(), '/') . '/cbss-mrz';
chk('nothing is left in the private temporary folder', glob($tmp . '/*') ?: [], []);
chk('the folder is private to this user', substr(sprintf('%o', fileperms($tmp)), -3), '700');

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
