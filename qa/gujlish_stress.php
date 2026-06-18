<?php
/**
 * Stress test: how Pal's (Gujarati/Kampala) customers actually type orders in Gujlish.
 * Tests the PARSER only (qty + item extraction, greeting drop, over-trigger guard).
 * Catalogue matching is separate (runs in BotBrain against the live catalogue).
 */
require __DIR__ . '/../app/Services/Bot/BulkOrderParser.php';

use App\Services\Bot\BulkOrderParser;

function show(string $s): string { return str_replace(["\n"], ['  ⏎  '], $s); }

// [label, message, expectBulk(bool), expectItems(int|null)]
$cases = [
    // ---- SHOULD be detected as a bulk order ----
    ['clean list (reported)', "Jsk \n2 packet panipuri \n2 packet kachori \n1 packet ratlami sev \n1 packet masala sing \n1 plain boondi", true, 5],
    ['comma single line',     "2 panipuri, 2 kachori, 1 boondi", true, 3],
    ['qty AFTER product',     "panipuri 2, kachori 2, boondi 1", true, 3],
    ['gujarati num words',    "be packet kachori\ntran packet sev", true, 2],
    ['greeting + qty-after',  "Jai Shri Krishna\nkachori 2\nsev 1 kg\nboondi 1", true, 3],
    ['joined with ane',       "2 panipuri ane 2 kachori", true, 2],
    ['joined with and',       "2 panipuri and 3 kachori and 1 sev", true, 3],
    ['no-space qty+unit',     "1kg sev\n2pkt wafer", true, 2],
    ['kem cho + items',       "kem cho\n3 samosa\n2 dhokla", true, 2],
    ['numword + product',     "ek farsi puri\nbe nankhatai", true, 2],
    ['plus separated',        "2 panipuri + 2 kachori + 1 sev", true, 3],
    ['nag unit (pieces)',     "10 nag samosa\n5 nag kachori", true, 2],
    ['mixed styles',          "jsk\nkachori 2 packet\n1 kg sev\nbe boondi", true, 3],

    // ---- SHOULD NOT be detected (let normal flow handle) ----
    ['greeting only',         "Jsk", false, 0],
    ['kem cho only',          "kem cho", false, 0],
    ['single item',           "2 panipuri", false, null],          // 1 item < 2 → not bulk
    ['greeting + 1 item',     "hi\n2 panipuri", false, null],
    ['question',              "do you have fresh kachori?", false, 0],
    ['price question',        "sev nu price ketlu che?", false, 0],
    ['thanks',                "thank you bhai", false, 0],
    ['menu word',             "menu", false, 0],
    ['prose',                 "I want some snacks\nfor a party tomorrow", false, 0],
];

$miss = 0;
printf("%-22s | %-7s | %-5s | %s\n", 'case', 'bulk?', 'items', 'parsed');
echo str_repeat('-', 100) . "\n";
foreach ($cases as [$label, $msg, $expBulk, $expItems]) {
    $bulk  = BulkOrderParser::looksLikeBulkOrder($msg);
    $items = BulkOrderParser::parseAll($msg);
    $n     = count($items);
    $parsed = implode(' · ', array_map(fn ($i) => $i['qty'] . '×' . $i['query'], $items)) ?: '—';

    $bad = ($bulk !== $expBulk) || ($expItems !== null && $bulk && $n !== $expItems);
    if ($bad) $miss++;
    printf("%-22s | %-7s | %-5s | %s%s\n",
        $label, $bulk ? 'YES' : 'no', $n, $parsed, $bad ? '   <-- MISMATCH' : '');
}
echo str_repeat('-', 100) . "\n";
echo "gujlish_stress: " . (count($cases) - $miss) . "/" . count($cases) . " as expected"
   . ($miss ? "  (FAIL {$miss})" : "  (0 failed)") . "\n";
