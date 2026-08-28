<?php
/**
 * Pure harness for CogsAudit::assess — the READ-ONLY Phase-3A reconciliation classifier.
 * Covers the applicable acceptance cases: detect affected / unaffected, correct FX, missing FX,
 * partial qty, multiple currencies, different rates. (Journal-writing cases are Phase 3B.)
 *
 * Run:  php tests/cogs_audit_harness.php
 */

require __DIR__ . '/../app/Services/Sales/CogsAudit.php';
use App\Services\Sales\CogsAudit;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got) . "\n   want: " . json_encode($want) . "\n"; }
}

// A converter with a rate map (units per USD); returns null for a currency marked missing.
function conv(string $base, array $perUsd, array $missing = []): callable {
    return function (float $amt, string $from) use ($base, $perUsd, $missing): ?float {
        if (in_array(strtoupper($from), $missing, true)) { return null; }
        $usd = $amt / ($perUsd[$from] ?? 1.0);
        return round($usd * ($perUsd[$base] ?? 1.0), 2);
    };
}
$rates = ['USD' => 1.0, 'UGX' => 3700.0];

// 1 & 3 & 11: affected order — UGX cost, USD sale, USD base → remediable with correct amounts.
$r = CogsAudit::assess(
    ['id' => 151, 'order_no' => 'ORD-151', 'sell_currency' => 'USD', 'base_currency' => 'USD',
     'cost_currency' => 'UGX', 'sales' => 825, 'cogs_native' => 2000000],
    conv('USD', $rates));
check('affected → REMEDIABLE', $r['status'], CogsAudit::REMEDIABLE);
check('correct COGS USD', $r['correct_cogs_base'], 540.54);
check('incorrect (posted) COGS', $r['incorrect_cogs_base'], 2000000.0);
check('COGS overstatement', $r['cogs_overstatement'], 1999459.46);
check('correct gross profit', $r['correct_gross_profit'], 284.46);
check('correct margin %', $r['correct_gross_margin_pct'], 34.5);
check('requires compensating', $r['requires_compensating'], true);
check('journal ref keys on order', $r['journal_ref'], 'sale:151');

// 2: unaffected — USD base, cost already in USD-equivalent base (cost_currency == base) → posted==correct.
$u = CogsAudit::assess(
    ['id' => 9, 'order_no' => 'ORD-9', 'sell_currency' => 'USD', 'base_currency' => 'USD',
     'cost_currency' => 'USD', 'sales' => 825, 'cogs_native' => 540],
    conv('USD', $rates));
check('no currency mismatch → NOT_AFFECTED', $u['status'], CogsAudit::NOT_AFFECTED);
check('unaffected requires nothing', $u['requires_compensating'], false);

// 2b: unaffected — no cost recorded.
$nc = CogsAudit::assess(
    ['id' => 10, 'order_no' => 'ORD-10', 'sell_currency' => 'USD', 'base_currency' => 'USD',
     'cost_currency' => 'UGX', 'sales' => 500, 'cogs_native' => 0],
    conv('USD', $rates));
check('no cost → NOT_AFFECTED', $nc['status'], CogsAudit::NOT_AFFECTED);

// 4: missing FX rate → REQUIRES_RECONCILIATION (never invents).
$m = CogsAudit::assess(
    ['id' => 12, 'order_no' => 'ORD-12', 'sell_currency' => 'USD', 'base_currency' => 'USD',
     'cost_currency' => 'UGX', 'sales' => 825, 'cogs_native' => 2000000],
    conv('USD', $rates, ['UGX']));   // UGX rate unavailable
check('missing FX → REQUIRES_RECONCILIATION', $m['status'], CogsAudit::REQUIRES);
check('missing FX → not auto-remediable', $m['requires_compensating'], false);
check('missing FX → correct COGS null', $m['correct_cogs_base'], null);

// 10: partial quantities — smaller cogs_native, same mechanics.
$p = CogsAudit::assess(
    ['id' => 20, 'order_no' => 'ORD-20', 'sell_currency' => 'USD', 'base_currency' => 'USD',
     'cost_currency' => 'UGX', 'sales' => 412.5, 'cogs_native' => 1000000],
    conv('USD', $rates));
check('partial: correct COGS', $p['correct_cogs_base'], 270.27);
check('partial: overstatement', $p['cogs_overstatement'], 999729.73);

// 12: different historical rate → different correct COGS.
$early = CogsAudit::assess(['id'=>1,'order_no'=>'A','sell_currency'=>'USD','base_currency'=>'USD','cost_currency'=>'UGX','sales'=>825,'cogs_native'=>2000000], conv('USD', ['USD'=>1.0,'UGX'=>3700.0]));
$later = CogsAudit::assess(['id'=>2,'order_no'=>'B','sell_currency'=>'USD','base_currency'=>'USD','cost_currency'=>'UGX','sales'=>825,'cogs_native'=>2000000], conv('USD', ['USD'=>1.0,'UGX'=>4000.0]));
check('different rate → different correct COGS', $early['correct_cogs_base'] > $later['correct_cogs_base'], true);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
