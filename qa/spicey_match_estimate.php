<?php
/**
 * qa/spicey_match_estimate.php
 *
 * OFFLINE estimate of the bot's dish-matching accuracy on restaurant vocabulary.
 * Loads the real production CatalogueMatcher (pure PHP) over the Spicey Herbs menu
 * (built in-memory from qa/spicey-herbs-menu.csv) and checks whether each realistic
 * order phrase resolves to the expected dish.
 *
 * This is the MATCHER layer only (the dominant driver of order accuracy). The full
 * end-to-end number — multi-item parsing, cart state, checkout — comes from
 * `php artisan bot:replay qa/spicey-replay-tests.csv --tenant=<id>` in the live console.
 *
 * Run: php qa/spicey_match_estimate.php
 */
require __DIR__ . '/../app/Services/Bot/CatalogueMatcher.php';
require __DIR__ . '/../app/Support/OrderInstructions.php';

use App\Services\Bot\CatalogueMatcher;
use App\Support\OrderInstructions;

// ---- build in-memory catalogue from the menu CSV (active rows only) ----
$rows = [];
$fh = fopen(__DIR__ . '/spicey-herbs-menu.csv', 'r');
$h = fgetcsv($fh);
$idx = array_flip(array_map(fn($x) => strtolower(trim($x)), $h));
$id = 0;
while (($c = fgetcsv($fh)) !== false) {
    if (count(array_filter($c, fn($x) => trim((string)$x) !== '')) === 0) continue;
    $active = strtoupper(trim($c[$idx['active']] ?? 'TRUE')) === 'TRUE';
    if (! $active) continue;
    $rows[] = [
        'id'           => ++$id,
        'name'         => trim($c[$idx['name']]),
        'description'  => trim($c[$idx['description']] ?? ''),
        'category'     => trim($c[$idx['category']] ?? ''),
        'keywords'     => '',
        'product_type' => '',
        'price'        => (float) ($c[$idx['price']] ?? 0),
        'stock'        => 1,
    ];
}
fclose($fh);

// ---- realistic order phrases -> expected dish (substring, case-insensitive) ----
// null expected = SHOULD miss (e.g. item genuinely not on the menu).
$tests = [
    ['Butter Chicken', 'Butter Chicken'],
    ['butter chicken', 'Butter Chicken'],
    ['Chicken Biryani extra spicy', 'Chicken Biryani'],   // instruction split off first
    ['chicken biryani', 'Chicken Biryani'],
    ['Garlic Naan', 'Garlic'],
    ['garlic naan', 'Garlic'],
    ['Paneer Tikka', 'Paneer Tikka'],
    ['Chicken Changezi', 'Chicken Changezi'],
    ['Mutton Biryani boneless', 'Mutton Biryani Boneless'],
    ['Chicken Tikka Masala', 'Chicken Tikka Masala'],
    ['CTM', 'Chicken Tikka Masala'],
    ['Dal Makhani', 'Dal Makhani'],
    ['Veg Burger', 'Veg Burger'],
    ['chicken 65', "Chicken '65'"],
    ['Kadai Paneer', 'Kadai Paneer'],
    ['Hot and Sour Soup', 'Hot & Sour'],
    ['Chicken Fried Rice', 'Chicken Fried Rice'],
    ['Margarita Pizza', 'Margarita'],
    ['Gulab Jamun', 'Gulab Jamun'],
    ['Naan', 'Naan'],
    ['Coke', null],          // no beverages on the printed menu -> expected miss
    ['Mango Lassi', null],   // not on the menu -> expected miss
];

$matcher = new CatalogueMatcher();
$top1 = 0; $top3 = 0; $correctMiss = 0; $total = count($tests);
$fails = [];

foreach ($tests as [$phrase, $expect]) {
    [$dish, $note] = OrderInstructions::split($phrase);
    $q = $dish !== '' ? $dish : $phrase;
    $cands = $matcher->search($q, $rows);
    $names = array_map(fn($c) => $c['product']['name'] ?? '', array_slice($cands, 0, 3));

    if ($expect === null) {
        if (! $cands) { $correctMiss++; }
        else { $fails[] = "  ✗ \"{$phrase}\" should MISS but matched: " . ($names[0] ?? '?'); }
        continue;
    }

    $hit1 = isset($names[0]) && stripos($names[0], $expect) !== false;
    $hit3 = (bool) array_filter($names, fn($n) => stripos($n, $expect) !== false);
    if ($hit1) $top1++;
    if ($hit3) $top3++;
    if (! $hit3) {
        $fails[] = "  ✗ \"{$phrase}\" -> expected ~\"{$expect}\", got: " . ($names ? implode(' | ', $names) : '(no match)');
    } elseif (! $hit1) {
        $fails[] = "  ~ \"{$phrase}\" -> correct dish in top-3 but not top-1: " . implode(' | ', $names);
    }
}

$matchable = $total - 2; // two expected-miss cases
echo "Catalogue: " . count($rows) . " active dishes\n";
echo "Phrases tested: {$total}  (matchable: {$matchable}, expected-miss: 2)\n\n";
foreach ($fails as $f) echo $f . "\n";
echo "\n";
printf("Top-1 dish match : %d/%d  (%.0f%%)\n", $top1, $matchable, 100 * $top1 / $matchable);
printf("Top-3 dish match : %d/%d  (%.0f%%)\n", $top3, $matchable, 100 * $top3 / $matchable);
printf("Expected misses  : %d/2 handled correctly\n", $correctMiss);
