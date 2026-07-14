<?php

/**
 * ACCOUNTANT AUDIT — replays a full day of cashbook entries and reconciles them TWO independent ways:
 *
 *   Method A  = the app's real machinery: build the exact OrderCost rows each /papi endpoint creates,
 *               then compute the cash position the way CashbookCapability does (CashService::IN_STAGES sign rule).
 *   Method B  = plain double-entry bookkeeping: apply each operation's intended debit/credit by hand.
 *
 * If A === B to the cent for every person and currency, the stage/row machinery equals honest bookkeeping.
 * Then we check the conservation laws an accountant relies on (conversions/transfers move value, never create it),
 * supplier & customer balances, and working capital. Pure PHP, no Laravel needed.
 */

$FX = ['USD' => 1.0, 'UGX' => 3655.0, 'SSP' => 5180.0]; // native per 1 USD (documented defaults)
$IN_STAGES = ['funding', 'cash_in', 'customer_payment'];

$rows = [];          // Method A: the OrderCost rows the endpoints would write
$B = [];             // Method B: balances[person][currency] by hand
$debt = [];          // supplier payables[name][currency]  (what WE owe)
$recv = [];          // receivables[customer][currency]     (what THEY owe us)
$nid = 0;
$fail = 0;
$log = [];

$fx = function (string $cur) use ($FX) { return $FX[$cur] ?? 1.0; };
$usd = function (float $native, float $rate) { return $rate > 0 ? $native / $rate : 0.0; };
$bAdd = function (string $p, string $cur, float $amt) use (&$B) { $B[$p][$cur] = ($B[$p][$cur] ?? 0.0) + $amt; };

// --- row writer: mirrors what each endpoint stores (stage, currency, native, rate_to_usd, holder, supplier) ---
$row = function (string $stage, string $cur, float $native, float $rate, string $holder, $sup = null) use (&$rows, &$nid, $usd) {
    $rows[] = ['id' => ++$nid, 'stage' => $stage, 'currency' => $cur, 'native' => $native,
        'rate' => $rate, 'usd' => round($usd($native, $rate), 6), 'holder' => $holder, 'supplier' => $sup, 'void' => false];
    return $nid;
};

// ============================ OPERATIONS (endpoint logic on the left, bookkeeping on the right) =========
$moneyIn = function (string $p, string $cur, float $amt, ?float $rate = null) use ($row, $bAdd, $fx) {
    $r = $rate ?? $fx($cur); $row('cash_in', $cur, $amt, $r, $p);          // A: funding/cash-in row (+)
    $bAdd($p, $cur, +$amt);                                                 // B: person gains cash
};
$expense = function (string $p, string $cur, float $amt, ?float $rate = null) use ($row, $bAdd, $fx) {
    $r = $rate ?? $fx($cur); $row('cash_out', $cur, $amt, $r, $p);          // A: cash-out row (−)
    $bAdd($p, $cur, -$amt);                                                 // B: person loses cash
};
$paySupplier = function (string $p, string $sup, string $cur, float $amt, ?float $rate = null) use ($row, $bAdd, &$debt, $fx) {
    $r = $rate ?? $fx($cur); $row('supplier_payment', $cur, $amt, $r, $p, $sup);
    $bAdd($p, $cur, -$amt);                                                 // B: cash out of payer
    $debt[$sup][$cur] = ($debt[$sup][$cur] ?? 0.0) - $amt;                  // B: we owe the supplier less
};
$convert = function (string $src, ?string $rcv, string $from, string $to, float $amt, float $rate) use ($row, $bAdd, $fx, $usd) {
    // convert() endpoint: out leg (from) + in leg (to), both valued at the SAME usd = usdValue.
    $usdValue = $usd($amt, $fx($from));
    $toAmt = round($amt * $rate, 2);
    $rateFrom = ($from === 'USD') ? 1.0 : $fx($from);
    $rateTo = $usdValue > 0 ? $toAmt / $usdValue : $fx($to);
    $row('cash_out', $from, $amt, $rateFrom, $src);                        // A: src spends `from`
    $row('cash_in', $to, $toAmt, $rateTo, $src);                          // A: src receives `to`
    $bAdd($src, $from, -$amt); $bAdd($src, $to, +$toAmt);                   // B
    if ($rcv !== null && strcasecmp($rcv, $src) !== 0) {                    // split: hand the `to` to someone else (transfer)
        $r = $fx($to);
        $row('cash_out', $to, $toAmt, $r, $src);                           // A: src hands out
        $row('cash_in', $to, $toAmt, $r, $rcv);                            // A: rcv receives
        $bAdd($src, $to, -$toAmt); $bAdd($rcv, $to, +$toAmt);              // B
    }
    return $toAmt;
};
$transfer = function (string $a, string $b, string $cur, float $amt) use ($row, $bAdd, $fx) {
    $r = $fx($cur);
    $row('cash_out', $cur, $amt, $r, $a); $row('cash_in', $cur, $amt, $r, $b);
    $bAdd($a, $cur, -$amt); $bAdd($b, $cur, +$amt);
};
$customerPay = function (string $p, string $customer, string $ordCur, float $amt) use ($row, $bAdd, &$recv, $fx) {
    // record-payment books the CASH leg in UGX (endpoint hardcodes UGX), and reduces the order balance in ORDER units.
    $row('customer_payment', 'UGX', $amt, $fx('UGX'), $p);                  // A: cash in (UGX)
    $bAdd($p, 'UGX', +$amt);                                                // B: cash in (UGX)
    $recv[$customer][$ordCur] = ($recv[$customer][$ordCur] ?? 0.0) - $amt;  // B: receivable down (order currency)
};
$editHolder = function (int $id, string $newHolder) use (&$rows, $bAdd) {
    foreach ($rows as &$r) {
        if ($r['id'] === $id) {
            $sign = in_array($r['stage'], ['funding', 'cash_in', 'customer_payment'], true) ? 1 : -1;
            $bAdd($r['holder'], $r['currency'], -$sign * $r['native']);     // B: undo from old holder
            $bAdd($newHolder, $r['currency'], +$sign * $r['native']);       // B: redo under new holder
            $r['holder'] = $newHolder;                                      // A: row now belongs to new holder
        }
    }
};
$void = function (int $id) use (&$rows, $bAdd, &$debt, &$recv) {
    foreach ($rows as &$r) {
        if ($r['id'] === $id) {
            $r['void'] = true;                                             // A: excluded from position
            $sign = in_array($r['stage'], ['funding', 'cash_in', 'customer_payment'], true) ? 1 : -1;
            $bAdd($r['holder'], $r['currency'], -$sign * $r['native']);     // B: reverse cash effect
            if ($r['stage'] === 'supplier_payment' && $r['supplier']) { $debt[$r['supplier']][$r['currency']] += $r['native']; }
        }
    }
};

// ===================================== OPENING BALANCES ================================================
$debt = ['Nura' => ['UGX' => 4000000.0], 'Acme Ltd' => ['UGX' => 500000.0], 'Kampala Traders' => ['USD' => 300.0]];
$recv = ['Shop 58' => ['UGX' => 612000.0], 'Juba Mart' => ['UGX' => 1200000.0]]; // customers owe us (UGX)
$openWC_note = 'Opening: we owe suppliers, customers owe us; cash starts at 0.';

// ===================================== A REALISTIC DAY =================================================
$log[] = "1. Jayesh receives \$2,000 funding (from Juba)";
$moneyIn('Jayesh', 'USD', 2000);
$log[] = "2. Jayesh converts \$2,000 -> UGX @ 3660  (= 7,320,000 UGX), keeps it himself";
$convert('Jayesh', null, 'USD', 'UGX', 2000, 3660);
$log[] = "3. Jayesh pays Nura 4,000,000 UGX (supplier, on account)";
$paySupplier('Jayesh', 'Nura', 'UGX', 4000000);
$log[] = "4. Jayesh pays Mr Tomu 300,000 UGX transport (expense)";
$expense('Jayesh', 'UGX', 300000);
$log[] = "5. Asish receives \$500 funding";
$moneyIn('Asish', 'USD', 500);
$log[] = "6. Asish converts \$500 -> UGX @ 3655, but hands the UGX to Bhavin (SPLIT)";
$toB = $convert('Asish', 'Bhavin', 'USD', 'UGX', 500, 3655);
$log[] = "7. Shop 58 pays 200,000 UGX against their order (customer receipt, held by Bhavin)";
$customerPay('Bhavin', 'Shop 58', 'UGX', 200000);
$log[] = "8. MISTAKE then FIX: a 100,000 UGX transport expense booked under Bhavin, corrected to Jayesh";
$badId = $expense('Bhavin', 'UGX', 100000) ? $nid : $nid; // capture id of the row just written
$editHolder($nid, 'Jayesh');
$log[] = "9. MISTAKE then DELETE: a duplicate \$50 expense on Asish, then voided";
$expense('Asish', 'USD', 50);
$void($nid);

// ===================================== METHOD A: app position =========================================
$perCurNative = []; $perCurUsd = []; $perPerson = []; $perPersonUsd = [];
foreach ($rows as $r) {
    if ($r['void']) { continue; }
    $sign = in_array($r['stage'], $IN_STAGES, true) ? 1 : -1;
    $perCurNative[$r['currency']] = ($perCurNative[$r['currency']] ?? 0) + $sign * $r['native'];
    $perCurUsd[$r['currency']] = ($perCurUsd[$r['currency']] ?? 0) + $sign * $r['usd'];
    $perPerson[$r['holder']][$r['currency']] = ($perPerson[$r['holder']][$r['currency']] ?? 0) + $sign * $r['native'];
    $perPersonUsd[$r['holder']] = ($perPersonUsd[$r['holder']] ?? 0) + $sign * $r['usd'];
}
$combinedUsd = round(array_sum($perCurUsd), 2);

// ===================================== CHECKS =========================================================
$assert = function (bool $c, string $m, string $detail = '') use (&$fail) {
    echo ($c ? "  ok   " : "  FAIL ") . $m . ($detail ? "   [$detail]" : '') . "\n";
    if (! $c) { $fail++; }
};
$eq = fn (float $a, float $b) => abs($a - $b) < 0.01;

echo "\n===== THE DAY =====\n";
foreach ($log as $l) { echo "  $l\n"; }

echo "\n===== CHECK 1: app stage-math (A) vs double-entry bookkeeping (B), per person & currency =====\n";
$people = array_unique(array_merge(array_keys($perPerson), array_keys($B)));
foreach ($people as $p) {
    foreach (['USD', 'UGX', 'SSP'] as $cur) {
        $a = round($perPerson[$p][$cur] ?? 0, 2);
        $b = round($B[$p][$cur] ?? 0, 2);
        if (abs($a) < 0.01 && abs($b) < 0.01) { continue; }
        $assert($eq($a, $b), "$p $cur", "app=" . number_format($a, 2) . "  books=" . number_format($b, 2));
    }
}

echo "\n===== CHECK 2: cross-foot — people add up to the currency totals =====\n";
foreach (['USD', 'UGX', 'SSP'] as $cur) {
    $sum = 0.0; foreach ($people as $p) { $sum += $perPerson[$p][$cur] ?? 0; }
    $assert($eq(round($sum, 2), round($perCurNative[$cur] ?? 0, 2)), "sum(persons $cur) = currency total $cur",
        number_format($sum, 2) . " = " . number_format($perCurNative[$cur] ?? 0, 2));
}

echo "\n===== CHECK 3: conservation — conversions & transfers create no value (USD in = USD out) =====\n";
// Every cash_in that came from a cash_out (conversion/transfer legs) nets to zero across the pair.
// Prove it by checking total USD cash only moved by the REAL external events (funding in, expense out, payments).
$externalUsd = 2000 + 500                                   // funding in
    - (4000000 / 3655)                                      // Nura supplier payment (UGX->usd)
    - (300000 / 3655)                                       // Tomu expense
    - (100000 / 3655)                                       // corrected transport expense (still an outflow)
    + (200000 / 3655);                                      // Shop 58 receipt (UGX cash in)
$assert($eq($combinedUsd, round($externalUsd, 2)), "combined USD cash = only real inflows/outflows",
    "app=" . number_format($combinedUsd, 2) . "  expected=" . number_format($externalUsd, 2));

echo "\n===== CHECK 4: the split conversion landed with the right people =====\n";
$assert($eq($perPerson['Bhavin']['UGX'] ?? 0, 200000 + $toB), "Bhavin holds the converted UGX + the receipt",
    "UGX=" . number_format($perPerson['Bhavin']['UGX'] ?? 0));
$assert($eq($perPerson['Asish']['USD'] ?? 0, 0), "Asish's USD is fully spent by the split conversion", "USD=" . number_format($perPerson['Asish']['USD'] ?? 0, 2));
$assert($eq($perPerson['Asish']['UGX'] ?? 0, 0), "Asish holds no UGX (it went to Bhavin)", "UGX=" . number_format($perPerson['Asish']['UGX'] ?? 0));

echo "\n===== CHECK 5: supplier & customer balances updated correctly =====\n";
$assert($eq($debt['Nura']['UGX'] ?? 0, 0), "Nura fully paid (4,000,000 -> 0)", number_format($debt['Nura']['UGX'] ?? 0));
$assert($eq($debt['Acme Ltd']['UGX'] ?? 0, 500000), "Acme still owed 500,000 (untouched)", number_format($debt['Acme Ltd']['UGX'] ?? 0));
$assert($eq($recv['Shop 58']['UGX'] ?? 0, 412000), "Shop 58 receivable 612,000 -> 412,000", number_format($recv['Shop 58']['UGX'] ?? 0));

echo "\n===== CHECK 6: the correction & the delete =====\n";
$assert($eq($perPerson['Bhavin']['UGX'] ?? 0, 200000 + $toB), "corrected 100k expense no longer sits on Bhavin", '');
$jaysExpectedUgx = 7320000 - 4000000 - 300000 - 100000; // convert-in − Nura − Tomu − corrected transport
$assert($eq($perPerson['Jayesh']['UGX'] ?? 0, $jaysExpectedUgx), "Jayesh UGX = 7,320,000 − 4,000,000 − 300,000 − 100,000",
    number_format($perPerson['Jayesh']['UGX'] ?? 0) . " vs " . number_format($jaysExpectedUgx));
$assert($eq($perPersonUsd['Asish'] ?? 0, 0), "voided \$50 expense left no trace on Asish", number_format($perPersonUsd['Asish'] ?? 0, 2));

echo "\n===== CHECK 7: working capital identity (cash + receivables − payables), and how each event moved it =====\n";
$recvUsd = 0.0; foreach ($recv as $c) { foreach ($c as $cur => $v) { $recvUsd += $v / $FX[$cur]; } }
$payUsd = 0.0; foreach ($debt as $s) { foreach ($s as $cur => $v) { $payUsd += $v / $FX[$cur]; } }
$wc = round($combinedUsd + $recvUsd - $payUsd, 2);
// Independent expectation: opening WC + funding in − expenses out (supplier/customer settlements are WC-neutral).
$openWC = (612000 / 3655 + 1200000 / 3655) - (4000000 / 3655 + 500000 / 3655 + 300 / 1); // opening AR − AP, cash 0
$expWC = $openWC + 2000 + 500 - (300000 / 3655) - (100000 / 3655);                        // +funding −real expenses
$assert($eq($wc, round($expWC, 2)), "working capital = opening + funding − expenses (settlements neutral)",
    "app=" . number_format($wc, 2) . "  expected=" . number_format($expWC, 2));

echo "\n===== POSITION AS THE CASHBOOK SHOWS IT =====\n";
foreach (['USD', 'UGX', 'SSP'] as $cur) {
    if (abs($perCurNative[$cur] ?? 0) < 0.01) { continue; }
    echo "  $cur cash: " . number_format($perCurNative[$cur], 2) . "  (~$" . number_format($perCurUsd[$cur], 2) . ")\n";
}
echo "  Combined USD cash: $" . number_format($combinedUsd, 2) . "\n";
echo "  Receivables: $" . number_format($recvUsd, 2) . "   Payables: $" . number_format($payUsd, 2) . "\n";
echo "  WORKING CAPITAL: $" . number_format($wc, 2) . "\n";
echo "  Held by —\n";
foreach ($perPerson as $p => $bycur) {
    $parts = [];
    foreach ($bycur as $cur => $v) { if (abs($v) > 0.01) { $parts[] = number_format($v) . " $cur"; } }
    if ($parts) { echo "    $p: " . implode(', ', $parts) . "  (~$" . number_format($perPersonUsd[$p], 2) . ")\n"; }
}

echo "\n" . ($fail === 0 ? "ACCOUNTANT AUDIT PASS — every entry reconciles to the cent.\n" : "ACCOUNTANT AUDIT FAILED ($fail)\n");
exit($fail === 0 ? 0 : 1);
