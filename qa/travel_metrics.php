<?php declare(strict_types=1);
// Pure test of the travel AI-quality and funnel definitions. Framework-free.
//   php qa/travel_metrics.php
require __DIR__ . '/../app/Services/Travel/Enquiry/TravelPipeline.php';
require __DIR__ . '/../app/Services/Travel/Quality/EnquiryMetrics.php';

use App\Services\Travel\Quality\EnquiryMetrics as M;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}
$now = 1_800_000_000; $h = 3600;
$row = fn (array $o = []) => array_merge(['stage' => 'NEW_ENQUIRY', 'reached' => ['NEW_ENQUIRY'], 'last_customer_at' => $now - $h,
    'human_touched' => false, 'handover' => false, 'flagged' => 0, 'guard_hits' => 0], $o);

chk('no enquiries: percentages are null, not 0%', [M::compute([], $now)['successful_ai_pct'], M::compute([], $now)['enquiry_to_quotation']], [null, null]);

$rows = [
    $row(['stage' => 'DETAILS_COLLECTED', 'reached' => ['NEW_ENQUIRY', 'DETAILS_COLLECTED']]),                                   // AI did it alone
    $row(['stage' => 'DETAILS_COLLECTED', 'reached' => ['NEW_ENQUIRY', 'DETAILS_COLLECTED'], 'human_touched' => true]),          // staff wrote
    $row(['stage' => 'DETAILS_COLLECTED', 'reached' => ['NEW_ENQUIRY', 'DETAILS_COLLECTED'], 'handover' => true]),               // takeover
    $row(['stage' => 'DETAILS_COLLECTED', 'reached' => ['NEW_ENQUIRY', 'DETAILS_COLLECTED'], 'flagged' => 1]),                   // flagged answer
    $row(['stage' => 'NEW_ENQUIRY', 'last_customer_at' => $now - 49 * $h]),                                                      // abandoned
    $row(['stage' => 'NEW_ENQUIRY', 'last_customer_at' => $now - 47 * $h, 'guard_hits' => 2]),                                   // not yet abandoned
    $row(['stage' => 'QUOTATION_SENT', 'reached' => ['NEW_ENQUIRY', 'DETAILS_COLLECTED', 'QUOTATION_SENT'], 'last_customer_at' => $now - 200 * $h, 'human_touched' => true]),
    $row(['stage' => 'TICKET_ISSUED', 'reached' => ['NEW_ENQUIRY', 'DETAILS_COLLECTED', 'QUOTATION_SENT', 'READY_FOR_PAYMENT', 'PAYMENT_PENDING', 'BOOKING_CONFIRMED', 'TICKET_ISSUED'], 'human_touched' => true]),
    $row(['stage' => 'LOST', 'reached' => ['NEW_ENQUIRY', 'LOST'], 'last_customer_at' => $now - 500 * $h]),
];
$m = M::compute($rows, $now, 48);
chk('total', $m['total'], 9);
chk('open excludes lost', $m['open'], 8);
chk('ready to price = details collected now', $m['ready_to_price'], 4);
chk('successful AI: reached details with no person, no takeover, no flag', $m['successful_ai'], 1);
chk('successful AI %', $m['successful_ai_pct'], 11.1);
chk('human handover counts staff messages and takeovers', $m['human_handover'], 4);
chk('human handover %', $m['human_handover_pct'], 44.4);
chk('abandoned: open, unquoted, silent beyond the limit', $m['abandoned'], 1);
chk('incorrect responses = flags + guard hits', [$m['flagged_answers'], $m['guard_hits'], $m['incorrect_responses']], [1, 2, 3]);
chk('enquiry → quotation: 2 quoted of 6 that reached details', $m['enquiry_to_quotation'], 33.3);
chk('quotation → ticket: 1 of 2', $m['quotation_to_ticket'], 50.0);

chk('a quoted enquiry is never "abandoned"', M::isAbandoned('QUOTATION_SENT', $now - 999 * $h, $now), false);
chk('a lost enquiry is never "abandoned"', M::isAbandoned('LOST', $now - 999 * $h, $now), false);
chk('exactly at the limit is not yet abandoned', M::isAbandoned('NEW_ENQUIRY', $now - 48 * $h, $now, 48), false);
chk('one second past the limit is', M::isAbandoned('NEW_ENQUIRY', $now - 48 * $h - 1, $now, 48), true);
chk('no customer timestamp: not abandoned', M::isAbandoned('NEW_ENQUIRY', null, $now), false);
chk('a custom limit is honoured', M::isAbandoned('DETAILS_COLLECTED', $now - 7 * $h, $now, 6), true);
chk('a zero limit is treated as one hour, not "always"', M::isAbandoned('NEW_ENQUIRY', $now - 1800, $now, 0), false);
chk('current stage counts as reached even if history is missing', M::compute([$row(['stage' => 'DETAILS_COLLECTED', 'reached' => []])], $now)['successful_ai'], 1);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
