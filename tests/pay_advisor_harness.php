<?php
/**
 * Pure harness for the Supplier Pay Advisor decision core (no Laravel, runs in the sandbox).
 * Calls the REAL PayAdvisor static methods (decide / thresholdDays / money / line) and mirrors the
 * aging fold (buildFromSlices) with plain day math, since ageDays uses Carbon.
 *
 * Run:  php tests/pay_advisor_harness.php
 */

require __DIR__ . '/../app/Services/Finance/PayAdvisor.php';
use App\Services\Finance\PayAdvisor;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got) . "\n   want: " . json_encode($want) . "\n"; }
}

// ---- decide(): the verdict logic ----
$hold = PayAdvisor::decide(16725000, 0);
check('hold when nothing overdue', $hold['state'], 'hold');
check('hold recommends nothing', $hold['recommend'], 0.0);

$pay = PayAdvisor::decide(16725000, 4950000);           // overdue 4.95M of 16.7M → keeps 70% pending
check('pay when overdue within buffer', $pay['state'], 'pay');
check('pay recommends the overdue', $pay['recommend'], 4950000.0);
check('pay pending pct', $pay['pending_pct'], 70);

$tension = PayAdvisor::decide(16725000, 12000000);      // paying 12M leaves 28% pending (< 50%)
check('tension when overdue breaks 50% buffer', $tension['state'], 'tension');
check('tension still recommends overdue (relationship-first)', $tension['recommend'], 12000000.0);
check('tension pending pct below 50', $tension['pending_pct'] < 50, true);

$clear = PayAdvisor::decide(0, 0);
check('clear when nothing owed', $clear['state'], 'clear');

$edge = PayAdvisor::decide(1000000, 500000);            // exactly at the 50% floor → pay, not tension
check('exactly 50% is pay, not tension', $edge['state'], 'pay');

// ---- thresholdDays(): "whichever is sooner" ----
check('Net 30 → sooner is 15', PayAdvisor::thresholdDays(30), 15);
check('Net 7  → sooner is 7',  PayAdvisor::thresholdDays(7), 7);
check('no term → flat 15',     PayAdvisor::thresholdDays(null), 15);
check('term 0 → flat 15',      PayAdvisor::thresholdDays(0), 15);
check('custom flat 10, Net 30 → 10', PayAdvisor::thresholdDays(30, 10), 10);

// ---- money(): formatting ----
check('USD money', PayAdvisor::money(1320, 'USD'), '$1,320.00');
check('UGX money whole', PayAdvisor::money(4950000, 'UGX'), 'UGX 4,950,000');
check('UGX money rounds stray cents', PayAdvisor::money(2696365.14, 'UGX'), 'UGX 2,696,365');

// ---- usdBracket(): USD-equivalent at the current rate ----
check('USD bracket UGX', PayAdvisor::usdBracket(4950000, 'UGX', 4000), ' (≈ $1,238)');
check('USD bracket none for USD', PayAdvisor::usdBracket(1000, 'USD', 1), '');
check('USD bracket none for unknown rate', PayAdvisor::usdBracket(4950000, 'UGX', 0), '');

// ---- line(): the one-line verdict (with USD bracket) ----
$payLine = PayAdvisor::line(array_merge($pay, ['currency' => 'UGX', 'rate' => 4000, 'oldest_days' => 18, 'threshold_days' => 15]));
check('pay line text', $payLine, 'Pay UGX 4,950,000 (≈ $1,238) — 18 days overdue');
$holdLine = PayAdvisor::line(array_merge($hold, ['currency' => 'UGX', 'oldest_days' => 5, 'threshold_days' => 15]));
check('hold line mentions threshold', strpos($holdLine, 'nothing past 15 days') !== false, true);

// ---- allocate(): greedy oldest-first cash allocation ----
check('allocate partial then hold', PayAdvisor::allocate([738, 1678, 2632, 903], 2020), [738.0, 1282.0, 0.0, 0.0]);
check('allocate covers all', PayAdvisor::allocate([738, 1678], 5000), [738.0, 1678.0]);
check('allocate nothing when broke', PayAdvisor::allocate([738], 0), [0.0]);
check('allocate exact first, none left', PayAdvisor::allocate([738, 900], 738), [738.0, 0.0]);

// ---- aging fold (mirror of buildFromSlices) ----
function foldAging(array $slices, int $termDays, int $todayTs, int $flat = 15): array {
    $thr = PayAdvisor::thresholdDays($termDays ?: null, $flat);
    $balance = 0.0; $overdue = 0.0; $oldest = 0;
    foreach ($slices as $sl) {
        $balance += $sl['native'];
        $age = (int) floor(($todayTs - strtotime($sl['at'] . ' 00:00:00')) / 86400);
        if ($age > $oldest) $oldest = $age;
        if ($age >= $thr) $overdue += $sl['native'];
    }
    return array_merge(PayAdvisor::decide($balance, $overdue), ['oldest_days' => $oldest, 'threshold_days' => $thr]);
}

$today = strtotime('2026-08-21 00:00:00');
// Nura, Net 30 → threshold 15. Bills: 21 Aug (0d, current), 31 Jul (21d, overdue), 06 Aug (15d, overdue).
$nura = [
    ['native' => 4950000, 'at' => '2026-08-21'],  // 0 days — not overdue
    ['native' => 1650000, 'at' => '2026-07-31'],  // 21 days — overdue
    ['native' => 4950000, 'at' => '2026-08-06'],  // 15 days — overdue (>= 15)
];
$r = foldAging($nura, 30, $today);
check('Nura threshold via sooner-of', $r['threshold_days'], 15);
check('Nura overdue = 31Jul + 06Aug', $r['overdue'], 6600000.0);
check('Nura balance', $r['balance'], 11550000.0);
check('Nura oldest days', $r['oldest_days'], 21);
check('Nura verdict pay (6.6M of 11.55M keeps 43%? -> tension)', $r['state'], 'tension'); // 11.55-6.6=4.95 → 43% < 50%

// Net-7 supplier: a 10-day-old bill is overdue at 7, not at 15.
$mahadev = [['native' => 8000000, 'at' => '2026-08-11']]; // 10 days old
$m = foldAging($mahadev, 7, $today);
check('Net-7 threshold', $m['threshold_days'], 7);
check('Net-7 10-day bill is overdue', $m['overdue'], 8000000.0);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
