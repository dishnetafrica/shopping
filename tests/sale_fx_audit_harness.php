<?php
/**
 * Pure harness for SaleFxAudit::assess — the READ-ONLY revenue-currency reconciliation.
 * Run:  php tests/sale_fx_audit_harness.php
 */

require __DIR__ . '/../app/Services/Sales/SaleFxAudit.php';
use App\Services\Sales\SaleFxAudit as A;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail; $ok = $got === $want; if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got) . "\n   want: " . json_encode($want) . "\n"; }
}

// toBaseMinor with a rate map (units per USD); base USD (factor 100); null for a missing currency.
function tb(array $perUsd, array $missing = []): callable {
    return function (float $amt, string $from) use ($perUsd, $missing): ?int {
        if (in_array(strtoupper($from), $missing, true)) { return null; }
        $usd = $amt / ($perUsd[strtoupper($from)] ?? 1.0);   // base = USD
        return (int) round($usd * 100);
    };
}
$rates = ['USD' => 1.0, 'UGX' => 3700.0];

// UGX order booked unconverted: net 2,814,350 UGX posted as $2,814,350.00 (281,435,000 minor).
$ugx = ['order_id' => 500, 'order_no' => 'ORD-500', 'date' => '2026-07-01', 'base_currency' => 'USD',
    'sell_currency' => 'UGX', 'base_factor' => 100, 'posted_net_minor' => 281435000, 'vat_bp' => 1800];
$r = A::assess($ugx, tb($rates));
check('UGX order → REMEDIABLE', $r['status'], A::REMEDIABLE);
check('correct net minor = 76,064', $r['correct_net_minor'], 76064);
check('net overstatement minor', $r['net_over_minor'], 281435000 - 76064);
check('vat overstatement = 18% of net over', $r['vat_over_minor'], (int) round((281435000 - 76064) * 0.18));
check('gross over = net over + vat over (balances the compensating entry)',
    $r['gross_over_minor'], $r['net_over_minor'] + $r['vat_over_minor']);
check('display correct net = 760.64', $r['correct_net'], 760.64);

// USD order (sell == base) → nothing to reverse.
$usd = ['order_id' => 9, 'order_no' => 'ORD-9', 'date' => '2026-07-01', 'base_currency' => 'USD',
    'sell_currency' => 'USD', 'base_factor' => 100, 'posted_net_minor' => 82500, 'vat_bp' => 1800];
$u = A::assess($usd, tb($rates));
check('USD order → NOT_AFFECTED', $u['status'], A::NOT_AFFECTED);
check('USD order → net over 0', $u['net_over_minor'], 0);
check('USD order → not remediable', $u['remediable'], false);

// Missing FX → REQUIRES_RECONCILIATION (never invents a rate).
$mf = A::assess($ugx, tb($rates, ['UGX']));
check('missing FX → REQUIRES', $mf['status'], A::REQUIRES);
check('missing FX → correct net null', $mf['correct_net_minor'], null);
check('missing FX → not remediable', $mf['remediable'], false);

// Not posted to the kernel → NOT_POSTED.
$np = A::assess(['posted_net_minor' => null] + $ugx, tb($rates));
check('no posting → NOT_POSTED', $np['status'], A::NOT_POSTED);

// Aggregate id stable and distinct from the COGS one (cogsfix_ vs salefx_).
check('correction aggregate = salefx_500', $r['correction_aggregate'], 'salefx_500');

// Different rate → different correct net.
$early = A::assess($ugx, tb(['USD' => 1.0, 'UGX' => 3700.0]));
$later = A::assess($ugx, tb(['USD' => 1.0, 'UGX' => 4000.0]));
check('weaker UGX → smaller correct net', $later['correct_net_minor'] < $early['correct_net_minor'], true);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
