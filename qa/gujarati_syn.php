<?php
/** Gujarati/Hindi product-word matching (CatalogueMatcher::SYN). Pure logic. */
require __DIR__ . '/../app/Services/Bot/CatalogueMatcher.php';

use App\Services\Bot\CatalogueMatcher;

$m = new CatalogueMatcher();
$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass,$fail; if($c)$pass++; else {$fail++; echo "  FAIL: $l\n";} }

$cat = [
    ['name' => 'Cashew 250g',     'category' => 'Dry Fruits'],
    ['name' => 'Almond 250g',     'category' => 'Dry Fruits'],
    ['name' => 'Dates 500g',      'category' => 'Dry Fruits'],
    ['name' => 'Raisin 200g',     'category' => 'Dry Fruits'],
    ['name' => 'Walnut 250g',     'category' => 'Dry Fruits'],
    ['name' => 'Fig (Anjeer) 250g','category' => 'Dry Fruits'],
    ['name' => 'Pistachio 200g',  'category' => 'Dry Fruits'],
    ['name' => 'Sugar 1kg',       'category' => 'Grocery'],
    ['name' => 'Cooking Oil 1L',  'category' => 'Grocery'],
    ['name' => 'Basmati Rice 5kg','category' => 'Grocery'],
];

function top(CatalogueMatcher $m, array $cat, string $q): string {
    $r = $m->search($q, $cat);
    return $r[0]['product']['name'] ?? '(none)';
}

echo "Gujarati/Hindi synonym test\n";

$cases = [
    ['kaju',          'Cashew 250g'],
    ['kajoo',         'Cashew 250g'],
    ['badam',         'Almond 250g'],
    ['baadam',        'Almond 250g'],
    ['khajur',        'Dates 500g'],
    ['khajoor',       'Dates 500g'],
    ['kharek',        'Dates 500g'],
    ['draksh',        'Raisin 200g'],
    ['kismis',        'Raisin 200g'],
    ['kishmish',      'Raisin 200g'],
    ['akhrot',        'Walnut 250g'],
    ['anjeer',        'Fig (Anjeer) 250g'],
    ['anjir',         'Fig (Anjeer) 250g'],
    ['pista',         'Pistachio 200g'],
    // in phrases
    ['2 kaju aapo',   'Cashew 250g'],
    ['kismis joiye',  'Raisin 200g'],
    ['mane badam',    'Almond 250g'],
    // existing grocery still fine
    ['sakar',         'Sugar 1kg'],
    ['tel',           'Cooking Oil 1L'],
    ['chokha',        'Basmati Rice 5kg'],
];

foreach ($cases as [$q, $exp]) {
    ok(top($m, $cat, $q) === $exp, "\"$q\" -> $exp (got " . top($m, $cat, $q) . ')');
}

// tokens() expands the synonyms
$tok = $m->tokens('kaju badam khajur draksh');
ok($tok === ['cashew','almond','dates','raisin'], 'tokens expand: ' . implode(',', $tok));

echo "\n==== gujarati synonym test: PASS {$pass}  FAIL {$fail} ====\n";
exit($fail > 0 ? 1 : 0);
