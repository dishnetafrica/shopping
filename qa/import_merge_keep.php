<?php
/**
 * qa/import_merge_keep.php — proves the Merge import only overwrites columns that have a value in the
 * CSV; blank cells keep the existing photo / stock / cost / etc. Mirrors ProductImporter merge logic.
 * Run: php qa/import_merge_keep.php
 */

/** existing product + a CSV row ('' = blank cell) → resulting saved values */
function merge(array $existing, array $cell): array {
    $out = $existing;                 // start from what's in the DB
    $set = function ($k, $v) use (&$out) { $out[$k] = $v; };
    if ($cell['price']      !== '') $set('price', (float) $cell['price']);
    if ($cell['base_price'] !== '') $set('base_price', (float) $cell['base_price']);
    foreach (['category', 'keywords', 'sku', 'unit_label'] as $f) if ($cell[$f] !== '') $set($f, $cell[$f]);
    if ($cell['stock']     !== '') $set('stock', (int) $cell['stock']);
    if ($cell['image_url'] !== '') $set('image_url', $cell['image_url']);
    if ($cell['moq']       !== '') $set('moq', (int) $cell['moq']);
    if ($cell['pack_size'] !== '') $set('pack_size', (int) $cell['pack_size']);
    return $out;
}

$blankRow = ['price'=>'','base_price'=>'','category'=>'','keywords'=>'','sku'=>'','unit_label'=>'','stock'=>'','image_url'=>'','moq'=>'','pack_size'=>''];

$pass = 0; $fail = 0;
function check($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok  $l\n"; } else { $fail++; echo "  XX  $l\n"; } }

echo "=== import_merge_keep QA ===\n";

$existing = [
    'price' => 75000, 'base_price' => 60000, 'category' => 'Toilet Paper', 'keywords' => 'old',
    'sku' => 'TIS-EUR-150', 'unit_label' => 'carton', 'stock' => 100,
    'image_url' => 'https://cdn/photo.jpg', 'moq' => 1, 'pack_size' => 100,
];

// 1) the real case: CSV has price+unit+moq+pack, but blank image & stock & base_price
$row = array_merge($blankRow, ['price'=>'75000','unit_label'=>'carton','moq'=>'3','pack_size'=>'100','category'=>'Toilet Paper']);
$r = merge($existing, $row);
check('photo KEPT when Image blank',        $r['image_url'] === 'https://cdn/photo.jpg');
check('stock KEPT when Stock blank',        $r['stock'] === 100);
check('cost (base_price) KEPT when blank',  $r['base_price'] === 60000);
check('MOQ UPDATED to 3 (provided)',        $r['moq'] === 3);
check('price UPDATED (provided)',           $r['price'] === 75000.0);
check('unit UPDATED (provided)',            $r['unit_label'] === 'carton');

// 2) fully blank row (except name) changes nothing
$r2 = merge($existing, $blankRow);
check('all-blank row keeps everything identical', $r2 === $existing);

// 3) providing a new image DOES update it
$r3 = merge($existing, array_merge($blankRow, ['image_url'=>'https://cdn/new.png']));
check('new Image overwrites when provided', $r3['image_url'] === 'https://cdn/new.png');

// 4) providing stock 0 explicitly DOES set 0 (only blank is "keep")
$r4 = merge($existing, array_merge($blankRow, ['stock'=>'0']));
check('explicit stock 0 is applied (blank != 0)', $r4['stock'] === 0);

echo "\n$pass / " . ($pass + $fail) . " passed\n";
echo $fail === 0 ? "ALL GREEN\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
