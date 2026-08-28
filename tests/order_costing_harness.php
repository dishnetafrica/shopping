<?php
/**
 * Pure harness for OrderCosting::reconcile — the multi-currency COGS/gross-margin fix.
 * Proves a UGX cost and a USD sale reconcile to the base currency correctly (no more UGX-read-as-USD).
 *
 * Run:  php tests/order_costing_harness.php
 */

require __DIR__ . '/../app/Services/Sales/OrderCosting.php';
use App\Services\Sales\OrderCosting;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got) . "\n   want: " . json_encode($want) . "\n"; }
}

// A stub converter: rates are "units per 1 USD". Convert from→USD→to. Base is passed per-scenario.
function makeConv(string $base, array $perUsd): callable {
    return function (float $amt, string $from) use ($base, $perUsd): float {
        $usd = $amt / ($perUsd[$from] ?? 1.0);           // amount → USD
        return $usd * ($perUsd[$base] ?? 1.0);           // USD → base
    };
}
$rates = ['USD' => 1.0, 'UGX' => 3700.0, 'SSP' => 1300.0];

// ---- Case 1 & 2: UGX purchase → USD sale, USD base. The exact ORD-151 scenario. ----
$r = OrderCosting::reconcile(2000000, 'UGX', 825, 'USD', makeConv('USD', $rates));
check('cost UGX 2,000,000 → USD', $r['cogs_base'], 540.54);
check('revenue USD 825 stays USD', $r['revenue_base'], 825.0);
check('gross profit USD', $r['profit'], 284.46);
check('gross margin % (was -242,324%)', $r['pct'], 34.5);

// ---- Case 6: USD sale, cost still stored UGX, but a small cost → healthy margin ----
$r2 = OrderCosting::reconcile(370000, 'UGX', 825, 'USD', makeConv('USD', $rates));   // UGX 370k = $100
check('small UGX cost → USD', $r2['cogs_base'], 100.0);
check('margin with $100 cost', $r2['pct'], 87.9);

// ---- Case 7: UGX purchase → UGX sale, UGX base (no conversion — must be a no-op) ----
$r3 = OrderCosting::reconcile(2000000, 'UGX', 3050000, 'UGX', makeConv('UGX', $rates));
check('UGX base: cost unchanged', $r3['cogs_base'], 2000000.0);
check('UGX base: revenue unchanged', $r3['revenue_base'], 3050000.0);
check('UGX base: margin', $r3['pct'], 34.4);

// ---- Margin is currency-invariant: same trade, base USD vs base UGX → same % ----
$usdBase = OrderCosting::reconcile(2000000, 'UGX', 825, 'USD', makeConv('USD', $rates));
$ugxBase = OrderCosting::reconcile(2000000, 'UGX', 3052500, 'UGX', makeConv('UGX', $rates)); // 825 USD ≈ 3,052,500 UGX
check('margin invariant across base currency', $usdBase['pct'], $ugxBase['pct']);

// ---- Rate movement after purchase: same cost, different date-rate → different base cost ----
$early = OrderCosting::reconcile(2000000, 'UGX', 825, 'USD', makeConv('USD', ['USD'=>1.0,'UGX'=>3700.0]));
$later = OrderCosting::reconcile(2000000, 'UGX', 825, 'USD', makeConv('USD', ['USD'=>1.0,'UGX'=>4000.0]));
check('weaker UGX → lower USD cost', $early['cogs_base'] > $later['cogs_base'], true);

// ---- Zero revenue → margin undefined (null), never a divide blow-up ----
$z = OrderCosting::reconcile(2000000, 'UGX', 0, 'USD', makeConv('USD', $rates));
check('zero revenue → pct null', $z['pct'], null);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
