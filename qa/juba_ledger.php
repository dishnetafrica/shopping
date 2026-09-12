<?php declare(strict_types=1);

/**
 * Pure harness for App\Services\Inventory\JubaLedger::derive() / ::byName().
 *
 * No framework, no DB — mirrors the sandbox rule. It proves the drill-down deriver reconciles the
 * same way JubaStockCapability does: Current = Opening + Bought − Sold, On shelf = Current + Reserved,
 * with the date gate, invoice-over-order priority, re-count-replaces, reserved, and ambiguous-name
 * rules all behaving as the list screen behaves. Run: php qa/juba_ledger.php
 */

require __DIR__ . '/../app/Services/Inventory/PurchaseNameMatch.php';
require __DIR__ . '/../app/Services/Inventory/JubaLedger.php';

use App\Services\Inventory\JubaLedger;

$pass = 0; $fail = 0;
function check(string $label, $got, $want): void
{
    global $pass, $fail;
    $ok = $got === $want;
    if (! $ok && is_float($want)) { $ok = abs((float) $got - $want) < 0.0001; }
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else     { $fail++; echo "  [FAIL] $label — got " . json_encode($got) . ", want " . json_encode($want) . "\n"; }
}

$PID = 10;
$products = [
    ['id' => 10, 'name' => 'Aluminium Foil 45cm Golden', 'keywords' => "aluminium foil 45cm (carton of 6pc, min 5 carton)\naluminium foil 45cm"],
    ['id' => 11, 'name' => 'Cling Film 45cm', 'keywords' => ''],
    ['id' => 12, 'name' => 'Shared Wording', 'keywords' => 'ambiguous line'],
    ['id' => 13, 'name' => 'Other Product', 'keywords' => 'ambiguous line'],   // same alias as 12 → ambiguous
];
$byName = JubaLedger::byName($products);

echo "=== 1. byName: own name, alias, and ambiguity ===\n";
check('own name resolves', $byName[JubaLedger::norm('Aluminium Foil 45cm Golden')] ?? 'x', 10);
check('newline alias resolves', $byName[JubaLedger::norm('aluminium foil 45cm (carton of 6pc, min 5 carton)')] ?? 'x', 10);
$ambKey = JubaLedger::norm('ambiguous line');
check('alias claimed by two products is null (ambiguous)', array_key_exists($ambKey, $byName) && $byName[$ambKey] === null, true);

echo "\n=== 2. opening anchor: latest count REPLACES, earlier is history ===\n";
$base = [
    'productId' => $PID,
    'openings' => [
        ['product_id' => 10, 'qty' => 100.0, 'at' => '2026-08-01 09:00:00', 'by' => 'Ashish'],
        ['product_id' => 10, 'qty' => 70.0,  'at' => '2026-09-06 09:00:00', 'by' => 'Bhavin'],   // latest wins
        ['product_id' => 11, 'qty' => 500.0, 'at' => '2026-07-15 09:00:00', 'by' => 'Ashish'],   // firstOpen source
    ],
    'purchases' => [], 'byName' => $byName, 'invoiceLines' => [], 'orders' => [], 'orderItems' => [],
];
$r = JubaLedger::derive($base);
check('opening = latest count (70)', $r['summary']['opening'], 70.0);
check('one earlier count kept as history', count($r['opening']['history']), 1);
check('current with no activity = opening', $r['summary']['current'], 70.0);

echo "\n=== 3. date gate: purchase/sale AT or BEFORE the count is inside it ===\n";
$c = $base;
$c['purchases'] = [
    ['qty' => 150.0, 'unit' => 2.0, 'total' => 300.0, 'currency' => 'USD', 'supplier' => 'AVION', 'ref' => 'PO-1', 'user' => 'Bhavin', 'at' => '2026-08-20 10:00:00'], // before 09-06 count → history
    ['qty' => 40.0,  'unit' => 2.0, 'total' => 80.0,  'currency' => 'USD', 'supplier' => 'AVION', 'ref' => 'PO-2', 'user' => 'Bhavin', 'at' => '2026-09-10 10:00:00'], // after → bought since
];
$r = JubaLedger::derive($c);
check('bought since counts only the post-count purchase (40)', $r['summary']['bought'], 40.0);
check('current = 70 + 40 (pre-count purchase excluded)', $r['summary']['current'], 110.0);

echo "\n=== 4. sales: invoice matched by name, post-count only ===\n";
$c2 = $c;
$c2['invoiceLines'] = [
    ['name' => 'aluminium foil 45cm', 'qty' => 30.0, 'unit' => 5.0, 'total' => 150.0, 'order_id' => 0, 'ref' => 'INV-1', 'customer' => 'Ciano', 'currency' => 'USD', 'source' => 'panel', 'at' => '2026-09-12 12:00:00'],
    ['name' => 'aluminium foil 45cm', 'qty' => 99.0, 'unit' => 5.0, 'total' => 495.0, 'order_id' => 0, 'ref' => 'INV-0', 'customer' => 'Old',   'currency' => 'USD', 'source' => 'panel', 'at' => '2026-08-01 12:00:00'], // before count → inside
    ['name' => 'unmatched wording',   'qty' => 12.0, 'unit' => 5.0, 'total' => 60.0,  'order_id' => 0, 'ref' => 'INV-2', 'customer' => 'X',     'currency' => 'USD', 'source' => 'panel', 'at' => '2026-09-12 12:00:00'], // not this product
];
$r = JubaLedger::derive($c2);
check('sold since counts only post-count matched invoice (30)', $r['summary']['sold'], 30.0);
check('current = 70 + 40 − 30', $r['summary']['current'], 80.0);

echo "\n=== 5. invoice-over-order priority: an invoiced order is not double counted ===\n";
$c3 = $c2;
$c3['invoiceLines'][] = ['name' => 'aluminium foil 45cm', 'qty' => 10.0, 'unit' => 5.0, 'total' => 50.0, 'order_id' => 900, 'ref' => 'INV-9', 'customer' => 'Ciano', 'currency' => 'USD', 'source' => 'panel', 'at' => '2026-09-13 12:00:00'];
$c3['orders'] = [
    ['id' => 900, 'ref' => 'ORD-900', 'items' => [['product_id' => 10, 'qty' => 10.0]], 'customer' => 'Ciano', 'at' => '2026-09-13 11:00:00', 'status' => 'delivered'], // invoiced → skip
    ['id' => 901, 'ref' => 'ORD-901', 'items' => [['product_id' => 10, 'qty' => 5.0]],  'customer' => 'Neeraj', 'at' => '2026-09-14 11:00:00', 'status' => 'delivered'], // uninvoiced → counts
];
$r = JubaLedger::derive($c3);
check('sold = 30 (inv) + 10 (inv on ORD-900) + 5 (ORD-901) = 45, ORD-900 not doubled', $r['summary']['sold'], 45.0);
check('current = 110 − 45', $r['summary']['current'], 65.0);

echo "\n=== 6. reserved: uninvoiced order at a pre-dispatch status ===\n";
$c4 = $c3;
$c4['orders'][] = ['id' => 902, 'ref' => 'ORD-902', 'items' => [['product_id' => 10, 'qty' => 8.0]], 'customer' => 'Held', 'at' => '2026-09-15 11:00:00', 'status' => 'confirmed']; // reserved
$r = JubaLedger::derive($c4);
check('sold includes reserved order (45 + 8 = 53)', $r['summary']['sold'], 53.0);
check('reserved = 8', $r['summary']['reserved'], 8.0);
check('current = 110 − 53 = 57 (free to sell)', $r['summary']['current'], 57.0);
check('on shelf = current + reserved = 65', $r['summary']['onShelf'], 65.0);

echo "\n=== 7. order_items fallback only when no invoice and no items_json ===\n";
$c5 = $base;
$c5['orderItems'] = [
    ['product_id' => 10, 'qty' => 6.0, 'order_id' => 950, 'ref' => 'ORD-950', 'customer' => 'Tbl', 'at' => '2026-09-11 10:00:00', 'status' => 'delivered'],
];
$r = JubaLedger::derive($c5);
check('order_items line counts (sold 6)', $r['summary']['sold'], 6.0);
check('current = 70 − 6', $r['summary']['current'], 64.0);
// now the same order also has an invoice → order_items must be ignored
$c5b = $c5;
$c5b['invoiceLines'] = [['name' => 'aluminium foil 45cm', 'qty' => 6.0, 'unit' => 5.0, 'total' => 30.0, 'order_id' => 950, 'ref' => 'INV-950', 'customer' => 'Tbl', 'currency' => 'USD', 'source' => 'panel', 'at' => '2026-09-11 12:00:00']];
$r = JubaLedger::derive($c5b);
check('invoiced order counts once, not twice (sold 6)', $r['summary']['sold'], 6.0);

echo "\n=== 9. cross-product fromJson dedup: an order handled by items_json (other product) skips its order_items ===\n";
// order 960: items_json sells product 11 (post 11's 2026-07-15 count → countable) AND has an
// order_items row for product 10 (target) — the target row must be skipped, same as the screen.
$c9 = $base;   // openings: 10@70 (09-06), 11@500 (07-15)
$c9['orders'] = [
    ['id' => 960, 'ref' => 'ORD-960', 'items' => [['product_id' => 11, 'qty' => 5.0]], 'customer' => 'Mix', 'at' => '2026-09-20 10:00:00', 'status' => 'delivered'],
];
$c9['orderItems'] = [
    ['product_id' => 10, 'qty' => 9.0, 'order_id' => 960, 'ref' => 'ORD-960', 'customer' => 'Mix', 'at' => '2026-09-20 10:00:00', 'status' => 'delivered'],
];
$r = JubaLedger::derive($c9);
check('target order_items skipped (order was handled by items_json) → sold 0', $r['summary']['sold'], 0.0);
// order 961: items_json line for product 11 dated BEFORE 11's count → NOT countable → no fromJson →
// the target order_items line (post 10's count) is processed.
$c9b = $base;
$c9b['orders'] = [
    ['id' => 961, 'ref' => 'ORD-961', 'items' => [['product_id' => 11, 'qty' => 3.0]], 'customer' => 'Old', 'at' => '2026-07-01 10:00:00', 'status' => 'delivered'],
];
$c9b['orderItems'] = [
    ['product_id' => 10, 'qty' => 4.0, 'order_id' => 961, 'ref' => 'ORD-961', 'customer' => 'Old', 'at' => '2026-09-21 10:00:00', 'status' => 'delivered'],
];
$r = JubaLedger::derive($c9b);
check('no fromJson (json line pre-count) → target order_items counts (sold 4)', $r['summary']['sold'], 4.0);

echo "\n=== 8. ledger running balance ends exactly at current ===\n";
$r = JubaLedger::derive($c4);
$last = end($r['ledger']);
check('ledger closing balance == current', $last['balance'], $r['summary']['current']);
check('ledger opens with the anchor count', $r['ledger'][0]['type'], 'count');

echo "\n============================================================\n";
echo ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
