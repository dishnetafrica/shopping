<?php declare(strict_types=1);

/**
 * Pure harness for the customs-document helpers — the parts that are framework-free:
 *  - CustomsDocStyle::decimals()/kg()      (currency minor units, grams→kg display)
 *  - CustomsLineBuilder::parseGrams()      (free-text weight "2.89 kg" → grams)
 *  - CustomsLineBuilder::pack()            (subtotal / packages / net / gross aggregation)
 *
 * The PDF rendering itself needs Dompdf + a booted app, so it is verified on staging — this
 * proves the number-producing logic the renderers consume. Run: php qa/customs_docs.php
 */

require __DIR__ . '/../app/Services/Customs/CustomsDocStyle.php';
require __DIR__ . '/../app/Services/Customs/CustomsLineBuilder.php';

use App\Services\Customs\CustomsDocStyle;
use App\Services\Customs\CustomsLineBuilder;

$pass = 0; $fail = 0;
function check(string $label, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label — got " . var_export($got, true) . ", want " . var_export($want, true) . "\n"; }
}

echo "=== 1. CustomsDocStyle::decimals (UGX/SSP = 0, else 2) ===\n";
check('USD = 2', CustomsDocStyle::decimals('USD'), 2);
check('usd lowercase = 2', CustomsDocStyle::decimals('usd'), 2);
check('UGX = 0', CustomsDocStyle::decimals('UGX'), 0);
check('SSP = 0', CustomsDocStyle::decimals('SSP'), 0);
check('KES = 2', CustomsDocStyle::decimals('KES'), 2);

echo "\n=== 2. CustomsDocStyle::kg (grams → kg string) ===\n";
check('2890 g', CustomsDocStyle::kg(2890), '2.89 kg');
check('8670 g', CustomsDocStyle::kg(8670), '8.67 kg');
check('0 g = dash', CustomsDocStyle::kg(0), '—');

echo "\n=== 3. CustomsLineBuilder::parseGrams (free-text weight) ===\n";
$b = new CustomsLineBuilder();
$rp = new ReflectionMethod($b, 'parseGrams');
$rp->setAccessible(true);
$pg = fn (string $s) => $rp->invoke($b, $s);
check('"2.89 kg" = 2890', $pg('2.89 kg'), 2890);
check('"890 g" = 890', $pg('890 g'), 890);
check('"2.5kg" = 2500', $pg('2.5kg'), 2500);
check('"~0.3 kg (est)" = 300', $pg('~0.3 kg (est)'), 300);
check('"1.8 kg" = 1800', $pg('1.8 kg'), 1800);
check('bare "2" = 2000 (kg default)', $pg('2'), 2000);
check('bare "500" = 500 (grams)', $pg('500'), 500);
check('empty = 0', $pg(''), 0);
check('"no weight here" = 0', $pg('no weight here'), 0);

echo "\n=== 4. CustomsLineBuilder::pack (aggregation) ===\n";
$rpk = new ReflectionMethod($b, 'pack');
$rpk->setAccessible(true);
// 3 x Mini Kit @ 273.43, net 2890 g, no explicit gross (gross falls back to net)
$lines = [[
    'name' => 'Starlink Mini Kit', 'part_no' => '2535001', 'hs_code' => '8517.62.00',
    'origin' => 'USA', 'qty' => 3.0, 'unit' => 'PCS', 'unit_price' => 273.43,
    'amount' => 820.29, 'net_g' => 2890, 'gross_g' => 0,
]];
$out = $rpk->invoke($b, $lines, ['currency' => 'USD', 'doc_ref' => 'Q1']);
check('subtotal = 820.29', $out['subtotal'], 820.29);
check('packages = 3', $out['packages'], 3);
check('net_g = 8670 (3 x 2890)', $out['net_g'], 8670);
check('gross_g falls back to net = 8670', $out['gross_g'], 8670);
check('lines carried through', count($out['lines']), 1);
check('meta merged (currency)', $out['currency'], 'USD');

// mixed: an explicit gross overrides the net fallback
$lines2 = [
    ['name' => 'A', 'qty' => 2.0, 'amount' => 100.0, 'net_g' => 1000, 'gross_g' => 1200, 'part_no' => '', 'hs_code' => '', 'origin' => '', 'unit' => '', 'unit_price' => 50.0],
    ['name' => 'B', 'qty' => 1.0, 'amount' => 40.0,  'net_g' => 500,  'gross_g' => 0,    'part_no' => '', 'hs_code' => '', 'origin' => '', 'unit' => '', 'unit_price' => 40.0],
];
$out2 = $rpk->invoke($b, $lines2, ['currency' => 'UGX']);
check('mixed subtotal = 140', $out2['subtotal'], 140.0);
check('mixed packages = 3 (2+1)', $out2['packages'], 3);
check('mixed gross = 2900 (2*1200 + 1*500)', $out2['gross_g'], 2900);
check('mixed net = 2500 (2*1000 + 1*500)', $out2['net_g'], 2500);

echo "\n============================================================\n";
echo ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
