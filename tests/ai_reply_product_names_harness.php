<?php
/**
 * Pure harness for AiBrain::productNamesFromReply — the list-of-products extractor that lets a
 * "pictures please" reply send a photo for each product it named (instead of apologising).
 *
 * Run:  php tests/ai_reply_product_names_harness.php
 */

require __DIR__ . '/../app/Services/Bot/AiBrain.php';
use App\Services\Bot\AiBrain;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n   want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}

// The exact reply from the WhatsApp log.
$reply = "Sure, here are some photos of our packaging materials:\n\n"
    . "1. Aluminium Foil 45CM*150/300 3KG Carton Of 6pc \u{1F4F7}\n"
    . "2. ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON) \u{1F4F7}\n"
    . "3. CLING FILM 45CM (CARTON OF 6PC, MIN 5 CARTON) \u{1F4F7}\n"
    . "4. Aluminum Foil Container Medium-8389 (Carton of 1000pc) \u{1F4F7}\n\n"
    . "Let me know if you need more details on any specific item! \u{1F44D}";

$names = AiBrain::productNamesFromReply($reply);
check('extracts 4 product lines', count($names), 4);
check('drops the 📷 and () tail (golden)', $names[1] ?? '', 'ALUMINIUM FOIL 45CM GOLDEN');
check('cling film name', $names[2] ?? '', 'CLING FILM 45CM');
check('keeps the 8389 code on the container', str_contains($names[3] ?? '', '8389'), true);
check('does not keep the closing line', in_array('Let me know if you need more details on any specific item!', $names, true), false);

// Category list with descriptions → name only, no "- Great for…" tail, no *stars*.
$catReply = "1. *Aluminium Foil* (8 items) - Great for wrapping food.\n2. *Cling Film* (1 item) - Perfect for sealing.";
$cats = AiBrain::productNamesFromReply($catReply);
check('category name stripped of stars/desc', $cats[0] ?? '', 'Aluminium Foil');
check('second category', $cats[1] ?? '', 'Cling Film');

// A plain prose reply with no list → nothing to extract.
check('no list → empty', AiBrain::productNamesFromReply('Sure! What container did you mean?'), []);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
