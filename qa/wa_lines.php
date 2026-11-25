<?php declare(strict_types=1);
// Pure test: the V3 WhatsApp numbers section (App\Services\WhatsApp\WhatsAppLines' pure helpers).
//   php qa/wa_lines.php
require __DIR__ . '/../app/Services/WhatsApp/WhatsAppLines.php';

use App\Services\WhatsApp\WhatsAppLines as L;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== what the owner reads for a line\n";
chk('open → Connected', L::status('open'), 'Connected');
chk('connecting → waiting for the phone (type the code)', L::status('connecting'), 'Waiting for the phone');
chk('close / missing → Not linked', [L::status('close'), L::status('closed'), L::status('missing')], ['Not linked', 'Not linked', 'Not linked']);
chk('a Meta Cloud line → Official API', L::status('n/a'), 'Official API');
chk('anything else → Unknown', L::status(''), 'Unknown');

echo "== a new line's name\n";
chk('the first extra number of business 31', L::nextInstance(31, []), 'shopbot_t31_2');
chk('the next free one', L::nextInstance(31, ['shopbot_t31_2', 'shopbot_t31_3']), 'shopbot_t31_4');
chk('a gap is reused', L::nextInstance(31, ['shopbot_t31_3']), 'shopbot_t31_2');
chk('another business\'s lines do not count', L::nextInstance(31, ['shopbot_t3_2', 'shopbot_t310_2']), 'shopbot_t31_2');

echo "== the pick-list choice\n";
chk('"66 — Aida" → 66', L::pickedId('66 — Aida'), 66);
chk('"The business" → none', L::pickedId('The business'), null);
chk('empty → none', [L::pickedId(''), L::pickedId(null)], [null, null]);
chk('a link code lasts a few minutes', L::CODE_MINUTES, 3);

echo "\n" . ($fail ? "FAILURES" : "ALL PASS") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
