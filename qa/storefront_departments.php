<?php
/**
 * qa/storefront_departments.php — proves the grocery department layer: the config-driven grouping
 * (deptModel), the activation gate (useDepartments — grocery + real groups only), the "More" bucket
 * that never drops a category, and deptOf(). Mirrors the shop.html logic. Run:
 *   php qa/storefront_departments.php
 */

/** @param string[] $cats existing categories (have products) */
function deptModel(array $groups, array $cats): array {
    if (! $groups) return [];
    $exists = array_flip($cats);
    $used = []; $depts = [];
    foreach ($groups as $dn => $subsRaw) {
        $subs = array_values(array_filter((array) $subsRaw, fn ($s) => isset($exists[$s])));
        foreach ($subs as $s) $used[$s] = 1;
        if ($subs) $depts[] = ['name' => $dn, 'subs' => $subs];
    }
    $more = array_values(array_filter($cats, fn ($c) => ! isset($used[$c])));
    if ($more) $depts[] = ['name' => 'More', 'subs' => $more];
    return $depts;
}
function useDepartments(string $vertical, array $groups, array $cats): bool {
    if ($vertical !== 'grocery') return false;
    foreach (deptModel($groups, $cats) as $d) if ($d['name'] !== 'More') return true;
    return false;
}
function deptOf(array $groups, array $cats, string $cat): ?string {
    foreach (deptModel($groups, $cats) as $d) if (in_array($cat, $d['subs'], true)) return $d['name'];
    return null;
}

$pass = 0; $fail = 0;
function check($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok  $l\n"; } else { $fail++; echo "  XX  $l\n"; } }

echo "=== storefront_departments QA ===\n";

$groups = [
    'Grocery & Kitchen'       => ['Vegetables & Fruits', 'Atta, Rice & Dal', 'Dairy, Bread & Eggs'],
    'Snacks & Drinks'         => ['Chips & Namkeen', 'Drinks & Juices'],
    'Beauty & Personal Care'  => ['Bath & Body', 'Hair Care'],
];
$cats = ['Vegetables & Fruits', 'Atta, Rice & Dal', 'Dairy, Bread & Eggs', 'Chips & Namkeen', 'Drinks & Juices', 'Bath & Body', 'Pet Supplies'];

// ---- activation gate (scope) ----
check('OFF for restaurant even with groups',  ! useDepartments('restaurant', $groups, $cats));
check('OFF for snacks even with groups',      ! useDepartments('snacks', $groups, $cats));
check('OFF for grocery with NO groups (electronics/pharmacy/hardware/fashion stay flat)', ! useDepartments('grocery', [], $cats));
check('OFF for grocery when groups match no real category', ! useDepartments('grocery', ['Dept' => ['Nonexistent']], $cats));
check('ON for grocery with real groups',        useDepartments('grocery', $groups, $cats));

// ---- model integrity ----
$m = deptModel($groups, $cats);
$names = array_map(fn ($d) => $d['name'], $m);
check('three configured departments + a More bucket', $names === ['Grocery & Kitchen', 'Snacks & Drinks', 'Beauty & Personal Care', 'More']);
check('ungrouped category (Pet Supplies) lands in More', end($m)['name'] === 'More' && $m[count($m)-1]['subs'] === ['Pet Supplies']);

// only existing categories appear as sub-tiles (Hair Care configured but no products -> dropped)
$bp = null; foreach ($m as $d) if ($d['name'] === 'Beauty & Personal Care') $bp = $d;
check('Beauty dept drops the empty "Hair Care" sub (no products)', $bp['subs'] === ['Bath & Body']);

// never drop a category — union of all subs == all existing cats
$allSubs = []; foreach ($m as $d) $allSubs = array_merge($allSubs, $d['subs']);
sort($allSubs); $catsSorted = $cats; sort($catsSorted);
check('every existing category is reachable (none dropped)', $allSubs === $catsSorted);

// deptOf
check('deptOf maps a category to its department', deptOf($groups, $cats, 'Drinks & Juices') === 'Snacks & Drinks');
check('deptOf maps an ungrouped category to More', deptOf($groups, $cats, 'Pet Supplies') === 'More');

echo "\n$pass / " . ($pass + $fail) . " passed\n";
echo $fail === 0 ? "ALL GREEN\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
