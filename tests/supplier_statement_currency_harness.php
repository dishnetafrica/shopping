<?php
/**
 * Pure harness for SupplierStatement's currency fold (no Laravel, runs in the sandbox).
 *
 * It mirrors the exact aggregation in App\Services\Finance\SupplierStatement::collect() and proves:
 *   1. USD mode is unchanged (uses usd_amount; nothing excluded).
 *   2. Native (UGX) mode uses native_amount and produces the correct running balance / totals.
 *   3. A native statement is single-currency: bills in other currencies are excluded and counted.
 *   4. A payment reduces the native balance owed.
 *
 * Run:  php tests/supplier_statement_currency_harness.php
 */

/** Mirrors collect()'s fold over the OrderCost rows for a given statement currency. */
function fold(array $items, string $currency, float $openingNative = 0.0, string $openingCur = 'USD'): array
{
    $mode = strtoupper(trim($currency)) ?: 'USD';
    $native = ($mode !== 'USD');

    $opening = 0.0;
    if ($openingNative > 0) {
        if ($native) {
            if (strtoupper($openingCur) === $mode) { $opening = $openingNative; }
        } else {
            $opening = $openingNative; // fixtures use USD opening for the USD case
        }
    }

    $bal = $opening; $charges = 0.0; $payments = 0.0; $excluded = 0; $others = []; $rows = [];
    foreach ($items as $c) {
        if ($native && strtoupper($c['currency']) !== $mode) {
            $excluded++;
            $others[strtoupper($c['currency'] ?: '?')] = true;
            continue;
        }
        $isPay = $c['stage'] === 'supplier_payment';
        $amt = $native ? (float) $c['native_amount'] : (float) $c['usd_amount'];
        if ($isPay) { $bal -= $amt; $payments += $amt; } else { $bal += $amt; $charges += $amt; }
        $rows[] = ['charge' => $isPay ? null : $amt, 'payment' => $isPay ? $amt : null, 'balance' => round($bal, 2)];
    }

    return [
        'currency' => $mode, 'native' => $native,
        'opening' => round($opening, 2), 'charges' => round($charges, 2),
        'payments' => round($payments, 2), 'closing' => round($bal, 2),
        'excluded' => $excluded, 'other_currencies' => array_keys($others), 'rows' => $rows,
    ];
}

$fail = 0;
function check(string $name, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got) . "\n   want: " . json_encode($want) . "\n"; }
}

// --- Fixture: supplier #9 (Nura), the six credit bills from the panel, all in UGX, chronological ---
$bills = [
    ['stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 1650000, 'usd_amount' => 440.00, 'pay_status' => 'credit'],
    ['stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 4950000, 'usd_amount' => 1320.00, 'pay_status' => 'credit'],
    ['stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 3300000, 'usd_amount' => 880.00, 'pay_status' => 'credit'],
    ['stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 825000,  'usd_amount' => 220.00, 'pay_status' => 'credit'],
    ['stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 1050000, 'usd_amount' => 280.00, 'pay_status' => 'credit'],
    ['stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 4950000, 'usd_amount' => 1320.00, 'pay_status' => 'credit'],
];

// 1) UGX statement: native amounts, running balance climbs to 16,725,000, nothing excluded.
$ugx = fold($bills, 'UGX');
check('UGX charges total', $ugx['charges'], 16725000.0);
check('UGX balance owed',  $ugx['closing'], 16725000.0);
check('UGX payments',      $ugx['payments'], 0.0);
check('UGX excluded none', $ugx['excluded'], 0);
check('UGX running balance last row', end($ugx['rows'])['balance'], 16725000.0);

// 2) USD statement unchanged: uses usd_amount, sums to the converted total.
$usd = fold($bills, 'USD');
check('USD charges total', $usd['charges'], 4460.0);   // 440+1320+880+220+280+1320
check('USD balance owed',  $usd['closing'], 4460.0);
check('USD excluded none', $usd['excluded'], 0);

// 3) Mixed currency: add one USD-billed charge. The UGX statement must EXCLUDE it (single-currency).
$mixed = array_merge($bills, [
    ['stage' => 'purchase', 'currency' => 'USD', 'native_amount' => 500, 'usd_amount' => 500.00, 'pay_status' => 'credit'],
]);
$ugxMixed = fold($mixed, 'UGX');
check('Mixed → UGX balance unchanged', $ugxMixed['closing'], 16725000.0);
check('Mixed → UGX excluded count',    $ugxMixed['excluded'], 1);
check('Mixed → UGX other currencies',  $ugxMixed['other_currencies'], ['USD']);
$usdMixed = fold($mixed, 'USD');
check('Mixed → USD consolidates all',  $usdMixed['closing'], 4960.0); // 4460 + 500

// 4) A payment reduces the native balance owed.
$withPay = array_merge($bills, [
    ['stage' => 'supplier_payment', 'currency' => 'UGX', 'native_amount' => 5000000, 'usd_amount' => 1333.33, 'pay_status' => 'cash'],
]);
$paid = fold($withPay, 'UGX');
check('Payment: UGX payments total', $paid['payments'], 5000000.0);
check('Payment: UGX balance owed',   $paid['closing'], 11725000.0); // 16,725,000 − 5,000,000

// 5) Opening balance carried only when it matches the native currency.
$openUgx = fold($bills, 'UGX', 2000000, 'UGX');
check('Opening (UGX) carried',   $openUgx['closing'], 18725000.0); // +2,000,000
$openUsdOnUgx = fold($bills, 'UGX', 2000000, 'USD');
check('Opening (USD) not carried on UGX stmt', $openUsdOnUgx['closing'], 16725000.0);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
