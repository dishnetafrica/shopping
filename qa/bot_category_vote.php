<?php
/**
 * Pure harness (runs in the sandbox, no framework): the two bot decisions fixed after the Corelink
 * Africa WhatsApp test on 13 Nov 2026.
 *   php qa/bot_category_vote.php
 */
require __DIR__ . '/../app/Services/Bot/CategoryVote.php';
require __DIR__ . '/../app/Services/Bot/DeliveryWording.php';

use App\Services\Bot\CategoryVote;
use App\Services\Bot\DeliveryWording;

$pass = 0; $fail = 0;
function chk(string $label, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($ok ? '' : '  got=' . var_export($got, true) . ' want=' . var_export($want, true)) . "\n";
    $ok ? $pass++ : $fail++;
}

/** The rule as it was before the fix, to show the reported case really was the bug. */
function oldWinner(array $counts): ?string {
    arsort($counts); $names = array_keys($counts);
    if (! $names || $counts[$names[0]] < 3) return null;
    if (isset($names[1]) && $counts[$names[1]] * 2 > $counts[$names[0]]) return null;
    return (string) $names[0];
}

// --- the reported case: "i need mini kit 5 pcs how much" on the Corelink catalogue. The only word
//     that survives the length/stop-word filter is "mini"; these are the products whose name has it.
$mini = ['Mounts' => 4, 'Routers and WiFi' => 2, 'Cables' => 2, 'Power' => 1, 'Starlink Kits' => 1, 'Cases' => 1];
chk('old rule picked Mounts for a Mini Kit question (the bug)', oldWinner($mini), 'Mounts');
chk('new rule picks no category for "mini" (spread across the range)', CategoryVote::winner($mini), null);

// --- cases that must keep working
chk('one dominant category still wins', CategoryVote::winner(['Stationery' => 9, 'Cleaning' => 1]), 'Stationery');
chk('single matching category wins', CategoryVote::winner(['Battery' => 5]), 'Battery');
chk('exact majority boundary: 5 of 9 wins', CategoryVote::winner(['A' => 5, 'B' => 2, 'C' => 2]), 'A');
chk('half is not a majority: 4 of 8 does not win', CategoryVote::winner(['A' => 4, 'B' => 2, 'C' => 2]), null);
chk('fewer than 3 matches is too weak', CategoryVote::winner(['A' => 2]), null);
chk('close runner-up → no guess (unchanged rule)', CategoryVote::winner(['A' => 5, 'B' => 3]), null);
chk('no matches → null', CategoryVote::winner([]), null);
chk('order of input does not matter', CategoryVote::winner(['Cleaning' => 1, 'Stationery' => 9]), 'Stationery');
chk('string counts from SQL are handled', CategoryVote::winner(['Stationery' => '9', 'Cleaning' => '1']), 'Stationery');

// --- delivery wording: a stated policy with no free-area list is said as written
$policy = 'Delivery is not free. Our team confirms the delivery cost when you order.';
chk('policy + no free areas → state the policy', DeliveryWording::statedPolicy($policy, ''), $policy);
chk('policy is trimmed', DeliveryWording::statedPolicy("  $policy \n", '  '), $policy);
chk('policy + own free areas → existing wording kept', DeliveryWording::statedPolicy($policy, 'Juba, Munuki'), null);
chk('no policy → legacy default kept', DeliveryWording::statedPolicy('', ''), null);
chk('no policy but free areas → existing wording kept', DeliveryWording::statedPolicy('', 'Juba'), null);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
