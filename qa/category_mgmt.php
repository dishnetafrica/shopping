<?php
/**
 * qa/category_mgmt.php  —  pure-logic QA for the category-management feature.
 * Framework-free: mirrors the exact algorithms used by
 *   - PanelApiController::categoryCreate/Rename/Delete (collision, reserved, remap)
 *   - seller.html renderCategories() (merge product cats + extra, apply saved order)
 * Run: php qa/category_mgmt.php
 */

$pass = 0; $fail = 0;
function ok($label, $got, $want) {
    global $pass, $fail;
    $g = json_encode($got); $w = json_encode($want);
    if ($g === $w) { $pass++; echo "  ok  $label\n"; }
    else { $fail++; echo "FAIL  $label\n        got : $g\n        want: $w\n"; }
}

/* ---- mirror of renderCategories ordering/merge ---- */
function display_order(array $counts, array $extra, array $order): array {
    $m = $counts;                                   // {name: count}
    foreach ($extra as $c) { $c = trim($c); if ($c !== '' && !array_key_exists($c, $m)) $m[$c] = 0; }
    $names = array_keys($m);
    $ord   = array_values(array_filter($order, fn($c) => array_key_exists($c, $m)));
    $rest  = array_values(array_filter($names, fn($c) => !in_array($c, $ord, true)));
    sort($rest, SORT_FLAG_CASE | SORT_STRING);
    return array_merge($ord, $rest);
}

/* ---- mirror of catExists (case-insensitive over products + extra) ---- */
function cat_exists(array $productCats, array $extra, string $name): bool {
    $n = mb_strtolower(trim($name));
    foreach ($productCats as $c) if (mb_strtolower(trim($c)) === $n) return true;
    foreach ($extra as $c) if (strcasecmp($c, $name) === 0) return true;
    return false;
}

/* ---- mirror of rename remap over an array (extra/order) ---- */
function remap(array $list, string $old, string $new): array {
    return array_values(array_unique(array_map(fn($e) => strcasecmp($e, $old) === 0 ? $new : $e, $list)));
}

echo "== ordering: saved order first, remainder alphabetical ==\n";
ok('order applied then alpha rest',
   display_order(['Snacks'=>5,'Dal'=>2,'Grocery'=>3], ['Sunday Special'], ['Grocery','Snacks']),
   ['Grocery','Snacks','Dal','Sunday Special']);
ok('no saved order -> pure alpha',
   display_order(['Banana'=>1,'Apple'=>2,'Cherry'=>1], [], []),
   ['Apple','Banana','Cherry']);
ok('stale order entries (not present) are ignored',
   display_order(['A'=>1,'B'=>1], [], ['Z','A','Y']),
   ['A','B']);

echo "== empty categories from extra show with count 0 ==\n";
$d = display_order(['Snacks'=>5], ['Sunday Special'], []);
ok('extra present in list', in_array('Sunday Special', $d, true), true);
ok('extra not duplicated when it also has products',
   display_order(['Snacks'=>5], ['Snacks'], []),
   ['Snacks']);

echo "== collision: case-insensitive, across products + extra ==\n";
ok('exact match exists',           cat_exists(['Snacks','Dal'], [], 'Snacks'), true);
ok('case-insensitive match',       cat_exists(['Snacks'], [], 'snacks'),       true);
ok('trim-insensitive match',       cat_exists(['Snacks'], [], '  Snacks '),    true);
ok('matches an empty extra',       cat_exists([], ['Sunday Special'], 'sunday special'), true);
ok('genuinely new name is free',   cat_exists(['Snacks'], ['Sweets'], 'Farsan'), false);

echo "== reserved name guard ==\n";
$reserved = fn($n) => strcasecmp(trim($n), 'Uncategorised') === 0;
ok('Uncategorised reserved',  $reserved('Uncategorised'), true);
ok('uncategorised reserved (case)', $reserved('uncategorised'), true);
ok('other name not reserved', $reserved('Snacks'), false);

echo "== rename remap over extra/order arrays ==\n";
ok('remap order list', remap(['Grocery','Snacks','Dal'], 'Snacks', 'Farsan'),
   ['Grocery','Farsan','Dal']);
ok('remap collapses dup after rename', remap(['Snacks','Farsan'], 'Snacks', 'Farsan'),
   ['Farsan']);
ok('remap leaves others untouched', remap(['A','B'], 'Z', 'Q'), ['A','B']);

echo "\n--------------------------------------------------\n";
echo "category_mgmt: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
