<?php declare(strict_types=1);
// Pure test of taking a discontinued plan out of sales knowledge (App\Services\Sales\Brain\RetireMention):
// DishNet, 21 Nov 2026 — "Starlink Roam is no more". Texts below are the ones that were published.
//   php qa/retire_mention.php
require __DIR__ . '/../app/Services/Sales/Brain/RetireMention.php';

use App\Services\Sales\Brain\RetireMention as R;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$plans = 'Plans: Residential is the default for almost everyone. Priority plans only for 50+ active users; for a "business plan" question, first ask how many users. Roam is a separate plan for roaming / moving between locations (with the car adaptor for mobile use). Residential is tied to the service address, which can be updated.';
chk('the Roam sentence goes, every other word stays', R::scrub($plans, 'Roam'),
    'Plans: Residential is the default for almost everyone. Priority plans only for 50+ active users; for a "business plan" question, first ask how many users. Residential is tied to the service address, which can be updated.');
chk('a FAQ answer keeps the part that is still true', R::scrub("Latency? 5-20 ms.\nCan I move Starlink between locations? Roam plan yes; Residential is tied to the service address but it can be updated.\nFree trial? No.", 'Roam'),
    "Latency? 5-20 ms.\nCan I move Starlink between locations? Residential is tied to the service address but it can be updated.\nFree trial? No.");
chk('a question whose whole answer was the plan is dropped', R::scrub("Is Roam available? Roam is available everywhere.\nFree trial? No.", 'Roam'), 'Free trial? No.');
chk('a retired alternative: only that word goes', R::scrub('For roaming / mobile use.', 'Roam'), 'For mobile use.');
chk('…on either side of the slash', R::scrub('For mobile / roaming use.', 'Roam'), 'For mobile use.');
chk('text that never mentions it is returned untouched', R::scrub("Residential: unlimited.\nMini first.", 'Roam'), "Residential: unlimited.\nMini first.");
chk('whole words only: "roam" inside another word is not touched', [R::mentions('Broamley street', 'Roam'), R::mentions('ROAM plan', 'Roam'), R::mentions('roaming', 'Roam')], [false, true, true]);
chk('no word: nothing happens', [R::mentions('Roam', ''), R::scrub('Roam plan.', '')], [false, 'Roam plan.']);

echo "\n" . ($fail ? 'FAILURES' : 'ALL PASS') . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
