<?php
/**
 * Pure harness for CashOnHand::fold — proves it reproduces the v3 Cashbook "Cash by person" numbers
 * (the reader the Pay Advisor now shares). No Laravel; calls the real fold().
 *
 * Run:  php tests/cash_on_hand_harness.php
 */

require '/home/user/shopping/app/Services/Money/CashService.php';   // for IN_STAGES const
require '/home/user/shopping/app/Services/Finance/CashOnHand.php';
use App\Services\Finance\CashOnHand;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got) . "\n   want: " . json_encode($want) . "\n"; }
}

// Jayesh: funded UGX 15,000,000 in, paid a supplier UGX 4,501,735 out → holds 10,498,265 (the screen).
// Asish: USD 3,591.94 in. supplier_payment is an OUT stage; funding/customer_payment are IN.
$rows = [
    ['currency' => 'UGX', 'stage' => 'funding',          'holder' => 'Jayesh Bhai', 'native' => 15000000, 'usd' => 4100.00],
    ['currency' => 'UGX', 'stage' => 'supplier_payment', 'holder' => 'Jayesh Bhai', 'native' => 4501735,  'usd' => 1231.00],
    ['currency' => 'USD', 'stage' => 'customer_payment', 'holder' => 'Asish',       'native' => 3591.94,  'usd' => 3591.94],
    ['currency' => 'SSP', 'stage' => 'funding',          'holder' => 'Asish',       'native' => 480492,   'usd' => 74.00],
];
$f = CashOnHand::fold($rows);

check('Jayesh UGX = 10,498,265', $f['holders']['Jayesh Bhai']['UGX'], 10498265.0);
check('Jayesh holds no USD', $f['holders']['Jayesh Bhai']['USD'], 0.0);
check('Asish USD', $f['holders']['Asish']['USD'], 3591.94);
check('Asish SSP', $f['holders']['Asish']['SSP'], 480492.0);
check('UGX position native', $f['pos']['UGX']['native'], 10498265.0);

// An OUT stage reduces; a void/rejected row would never reach fold (filtered in the query).
$out = CashOnHand::fold([
    ['currency' => 'UGX', 'stage' => 'funding', 'holder' => 'X', 'native' => 1000000, 'usd' => 270],
    ['currency' => 'UGX', 'stage' => 'draw',    'holder' => 'X', 'native' => 400000,  'usd' => 108],
]);
check('draw reduces cash', $out['holders']['X']['UGX'], 600000.0);

// Blank holder folds to "Unassigned".
$un = CashOnHand::fold([['currency' => 'UGX', 'stage' => 'funding', 'holder' => '', 'native' => 500, 'usd' => 0.13]]);
check('blank holder → Unassigned', $un['holders']['Unassigned']['UGX'], 500.0);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
