<?php
/**
 * Pure harness for SupplierStatement (no Laravel, runs in the sandbox).
 *
 * It mirrors the aggregation in App\Services\Finance\SupplierStatement::collect() and proves:
 *   1. USD mode is unchanged (uses usd_amount; nothing excluded).
 *   2. Native (UGX) mode uses native_amount and produces the correct running balance / totals.
 *   3. A native statement is single-currency: bills in other currencies are excluded and counted.
 *   4. A payment reduces the native balance owed.
 *   5. A date range folds pre-period movement into the opening balance and drops post-period rows.
 * It also calls the REAL csv() method to prove the export shape.
 *
 * Run:  php tests/supplier_statement_currency_harness.php
 */

require __DIR__ . '/../app/Services/Finance/SupplierStatement.php';

/** Mirrors collect()'s fold, including the optional [from,to] date range (dates as 'Y-m-d'). */
function fold(array $items, string $currency, float $openingNative = 0.0, string $openingCur = 'USD', ?string $from = null, ?string $to = null): array
{
    $mode = strtoupper(trim($currency)) ?: 'USD';
    $native = ($mode !== 'USD');
    $fromTs = $from ? strtotime($from . ' 00:00:00') : null;
    $toTs = $to ? strtotime($to . ' 23:59:59') : null;

    $opening = 0.0;
    if ($openingNative > 0) {
        if ($native) { if (strtoupper($openingCur) === $mode) { $opening = $openingNative; } }
        else { $opening = $openingNative; }
    }

    $bal = $opening; $charges = 0.0; $payments = 0.0; $excluded = 0; $others = []; $rows = [];
    foreach ($items as $c) {
        if ($native && strtoupper($c['currency']) !== $mode) {
            $excluded++; $others[strtoupper($c['currency'] ?: '?')] = true; continue;
        }
        $isPay = $c['stage'] === 'supplier_payment';
        $amt = $native ? (float) $c['native_amount'] : (float) $c['usd_amount'];
        $signed = $isPay ? -$amt : $amt;
        $ts = isset($c['date']) ? strtotime($c['date'] . ' 12:00:00') : null;
        if ($fromTs && $ts && $ts < $fromTs) { $bal += $signed; $opening = $bal; continue; }
        if ($toTs && $ts && $ts > $toTs) { continue; }
        $bal += $signed;
        if ($isPay) { $payments += $amt; } else { $charges += $amt; }
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
    ['date' => '2026-07-31', 'stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 1650000, 'usd_amount' => 440.00, 'pay_status' => 'credit'],
    ['date' => '2026-08-06', 'stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 4950000, 'usd_amount' => 1320.00, 'pay_status' => 'credit'],
    ['date' => '2026-08-07', 'stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 3300000, 'usd_amount' => 880.00, 'pay_status' => 'credit'],
    ['date' => '2026-08-14', 'stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 825000,  'usd_amount' => 220.00, 'pay_status' => 'credit'],
    ['date' => '2026-08-14', 'stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 1050000, 'usd_amount' => 280.00, 'pay_status' => 'credit'],
    ['date' => '2026-08-21', 'stage' => 'purchase', 'currency' => 'UGX', 'native_amount' => 4950000, 'usd_amount' => 1320.00, 'pay_status' => 'credit'],
];

// 1) UGX statement.
$ugx = fold($bills, 'UGX');
check('UGX charges total', $ugx['charges'], 16725000.0);
check('UGX balance owed',  $ugx['closing'], 16725000.0);
check('UGX payments',      $ugx['payments'], 0.0);
check('UGX excluded none', $ugx['excluded'], 0);

// 2) USD statement unchanged.
$usd = fold($bills, 'USD');
check('USD charges total', $usd['charges'], 4460.0);
check('USD balance owed',  $usd['closing'], 4460.0);

// 3) Mixed currency → UGX excludes the USD bill.
$mixed = array_merge($bills, [['date' => '2026-08-15', 'stage' => 'purchase', 'currency' => 'USD', 'native_amount' => 500, 'usd_amount' => 500.00, 'pay_status' => 'credit']]);
$ugxMixed = fold($mixed, 'UGX');
check('Mixed → UGX balance unchanged', $ugxMixed['closing'], 16725000.0);
check('Mixed → UGX excluded count',    $ugxMixed['excluded'], 1);
check('Mixed → UGX other currencies',  $ugxMixed['other_currencies'], ['USD']);

// 4) Payment reduces native balance.
$withPay = array_merge($bills, [['date' => '2026-08-22', 'stage' => 'supplier_payment', 'currency' => 'UGX', 'native_amount' => 5000000, 'usd_amount' => 1333.33, 'pay_status' => 'cash']]);
$paid = fold($withPay, 'UGX');
check('Payment: UGX payments total', $paid['payments'], 5000000.0);
check('Payment: UGX balance owed',   $paid['closing'], 11725000.0);

// 5) Date range: statement for August only. July's 1,650,000 folds into the opening balance;
//    the in-period rows run from there; balance owed still totals the full 16,725,000.
$aug = fold($bills, 'UGX', 0.0, 'USD', '2026-08-01', '2026-08-31');
check('Aug range opening carries July', $aug['opening'], 1650000.0);
check('Aug range charges (Aug only)',   $aug['charges'], 15075000.0); // 16,725,000 − 1,650,000
check('Aug range closing owed',         $aug['closing'], 16725000.0);
check('Aug range row count',            count($aug['rows']), 5);

// 5b) Upper bound excludes later rows: statement through 07 Aug.
$toEarly = fold($bills, 'UGX', 0.0, 'USD', null, '2026-08-07');
check('Through 07 Aug closing', $toEarly['closing'], 9900000.0); // 1.65M+4.95M+3.30M
check('Through 07 Aug rows',    count($toEarly['rows']), 3);

// 6) The REAL csv() method — proves the export shape and numbers.
$svc = new App\Services\Finance\SupplierStatement();
$d = [
    'business' => 'Family Shoppers', 'generated' => '21 Aug 2026, 22:11', 'supplier' => 'Nura Traders',
    'meta' => 'Net 30', 'currency' => 'UGX', 'native' => true, 'period' => 'All transactions',
    'opening' => 0.0, 'charges' => 16725000.0, 'payments' => 0.0, 'closing' => 16725000.0,
    'excluded' => 0, 'other_currencies' => [],
    'rows' => [
        ['when' => '2026-07-31', 'ref' => 'PUR-0351', 'desc' => 'Foil 10 boxes', 'charge' => 1650000.0, 'payment' => null, 'balance' => 1650000.0],
        ['when' => '2026-08-21', 'ref' => 'PUR-0653', 'desc' => 'Foil 30 cartons', 'charge' => 4950000.0, 'payment' => null, 'balance' => 16725000.0],
    ],
];
$csv = $svc->csv($d);
check('CSV has currency header', strpos($csv, '"Currency","UGX"') !== false, true);
check('CSV has a bill row',      strpos($csv, '"PUR-0351"') !== false, true);
check('CSV charge is unformatted number', strpos($csv, '1650000.00') !== false, true);
check('CSV closing balance row', strpos($csv, '"Balance owed",16725000.00,0.00,16725000.00') !== false, true);
check('CSV uses CRLF line ends', strpos($csv, "\r\n") !== false, true);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
