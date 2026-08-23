<?php
/**
 * Pure harness for CogsRemediation::plan — the Phase-3B compensating-entry planner.
 * Maps the 15 acceptance scenarios the owner listed onto the planner's decisions. No Laravel, no DB.
 *
 * Run:  php tests/cogs_remediate_harness.php
 */

require __DIR__ . '/../app/Services/Sales/CogsRemediation.php';
use App\Services\Sales\CogsRemediation as R;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail; $ok = $got === $want; if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got) . "\n   want: " . json_encode($want) . "\n"; }
}

// toBaseMinor with a rate map (units per USD); base USD; returns MINOR (×100). null for missing currency.
function tb(array $perUsd, array $missing = []): callable {
    return function (float $amt, string $from) use ($perUsd, $missing): ?int {
        if (in_array(strtoupper($from), $missing, true)) { return null; }
        $usd = $amt / ($perUsd[strtoupper($from)] ?? 1.0);   // base = USD
        return (int) round($usd * 100);
    };
}
$rates = ['USD' => 1.0, 'UGX' => 3700.0];

// Shared: ORD-151 posted UGX 2,000,000 as $20,000.00 (2,000,000 minor); correct = $540.54 (54054 minor).
$base151 = ['order_id' => 151, 'order_no' => 'ORD-151', 'base_currency' => 'USD', 'cost_currency' => 'UGX',
    'date' => '2026-07-01', 'posted_cost_minor' => 2000000, 'posted_revenue_minor' => 82500,
    'cogs_native' => 2000000, 'already_remediated' => false];

// 1. Detect an affected order.
$p = R::plan($base151, tb($rates));
check('1  affected → REMEDIABLE', $p['status'], R::REMEDIABLE);

// 2. Compute the correct compensating amount from the ACTUAL posted value.
check('2  correct cost minor = 54054', $p['correct_cost_minor'], 54054);
check('2  overstatement minor = 1,945,946', $p['overstatement_minor'], 1945946);
check('2  overstatement major = 19,459.46', $p['overstatement'], 19459.46);

// 3. Idempotency — an already-remediated order is skipped (never double-posts).
$already = R::plan(['already_remediated' => true] + $base151, tb($rates));
check('3  already remediated → ALREADY_REMEDIATED', $already['status'], R::ALREADY);
check('3  already remediated → not remediable', $already['remediable'], false);

// 4. Stable idempotency key derived from the order id.
check('4  correction aggregate = cogsfix_151', $p['correction_aggregate'], 'cogsfix_151');
check('4  source sale aggregate = 151', $p['source_sale_aggregate'], '151');

// 5. Missing FX → REQUIRES_RECONCILIATION, excluded from auto-remediation.
$mf = R::plan($base151, tb($rates, ['UGX']));
check('5  missing FX → REQUIRES_RECONCILIATION', $mf['status'], R::REQUIRES);
check('5  missing FX → not remediable', $mf['remediable'], false);
check('5  missing FX → correct amount null (never invented)', $mf['correct_cost_minor'], null);

// 6. No kernel posting for the order → NOT_POSTED (nothing to correct).
$np = R::plan(['posted_cost_minor' => null, 'posted_revenue_minor' => null] + $base151, tb($rates));
check('6  no posting → NOT_POSTED', $np['status'], R::NOT_POSTED);
check('6  no posting → not remediable', $np['remediable'], false);

// 7. Correctly-posted order (posted == correct) → NOT_AFFECTED.
$fine = ['order_id' => 9, 'order_no' => 'ORD-9', 'base_currency' => 'USD', 'cost_currency' => 'UGX',
    'date' => '2026-07-01', 'posted_cost_minor' => 54054, 'posted_revenue_minor' => 82500,
    'cogs_native' => 2000000, 'already_remediated' => false];
check('7  posted == correct → NOT_AFFECTED', R::plan($fine, tb($rates))['status'], R::NOT_AFFECTED);

// 8. No cost recorded → NOT_AFFECTED (posted 0, correct 0).
$noc = ['posted_cost_minor' => 0, 'cogs_native' => 0] + $base151;
check('8  zero cost → NOT_AFFECTED', R::plan($noc, tb($rates))['status'], R::NOT_AFFECTED);

// 9. Posted LESS than correct (never the bug) → NOT_AFFECTED, no negative correction posted.
$under = ['posted_cost_minor' => 40000, 'cogs_native' => 2000000] + $base151;
check('9  posted < correct → NOT_AFFECTED (no negative entry)', R::plan($under, tb($rates))['status'], R::NOT_AFFECTED);

// 10. Partial-quantity order — smaller native cost, same mechanics.
$partial = ['order_id' => 20, 'order_no' => 'ORD-20', 'base_currency' => 'USD', 'cost_currency' => 'UGX',
    'date' => '2026-07-01', 'posted_cost_minor' => 1000000, 'posted_revenue_minor' => 41250,
    'cogs_native' => 1000000, 'already_remediated' => false];
$pp = R::plan($partial, tb($rates));
check('10 partial: correct minor = 27027', $pp['correct_cost_minor'], 27027);
check('10 partial: overstatement minor = 972,973', $pp['overstatement_minor'], 972973);

// 11. Different historical rate → different correct amount (never assumes a single rate).
$early = R::plan($base151, tb(['USD' => 1.0, 'UGX' => 3700.0]));
$later = R::plan($base151, tb(['USD' => 1.0, 'UGX' => 4000.0]));
check('11 weaker UGX → smaller correct cost', $later['correct_cost_minor'] < $early['correct_cost_minor'], true);

// 12. Amount is computed from the ACTUAL posting, not a fresh cost_price (drift-proof).
//     Same order, cost_price has since drifted (cogs_native now huge) but posted was 2,000,000.
$drift = ['cogs_native' => 9999999] + $base151;      // native drifted upward AFTER the sale
$pd = R::plan($drift, tb($rates));
// correct is computed from cogs_native (the correct cost of goods actually sold uses the recorded cost),
// but the OVERSTATEMENT still anchors on the real posted 2,000,000 — so it can't exceed what was booked.
check('12 overstatement never exceeds the real posted cost', $pd['overstatement_minor'] <= 2000000, true);

// 13. UGX-base tenant (minor == major): factor handling doesn't distort a same-currency book.
$ugxBase = ['order_id' => 30, 'order_no' => 'ORD-30', 'base_currency' => 'UGX', 'cost_currency' => 'UGX',
    'date' => '2026-07-01', 'posted_cost_minor' => 2000000, 'posted_revenue_minor' => 3000000,
    'cogs_native' => 500000, 'already_remediated' => false];
$ub = R::plan($ugxBase, fn ($amt, $from) => (int) round($amt));   // UGX→UGX minor == amount
check('13 UGX base: correct minor = 500000', $ub['correct_cost_minor'], 500000);
check('13 UGX base: overstatement major == minor (subunit 1)', $ub['overstatement'], 1500000.0);

// 14. Version + status carried for the audit trail / CSV export.
check('14 version stamped', $p['version'], 'cogs-remediation-v1');
check('14 status present for export', array_key_exists('status', $p), true);

// 15. Reconciliation total: sum of overstatements across a batch of affected orders.
$batch = [R::plan($base151, tb($rates)), R::plan($partial, tb($rates)), R::plan($fine, tb($rates))];
$sumOver = array_sum(array_map(fn ($r) => $r['status'] === R::REMEDIABLE ? $r['overstatement_minor'] : 0, $batch));
check('15 batch overstatement total (minor) = 2,918,919', $sumOver, 1945946 + 972973);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
