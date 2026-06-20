<?php
/**
 * qa/order_instructions.php — framework-free tests for OrderInstructions.
 * Run: php qa/order_instructions.php
 *
 * Heaviest emphasis on the collision cases: real Spicey Herbs dish names that contain
 * spice words must NOT be truncated, and "Add …" must never be eaten.
 */
require __DIR__ . '/../app/Support/OrderInstructions.php';

use App\Support\OrderInstructions;

$pass = 0; $fail = 0; $fails = [];
function eq(string $name, $got, $want) {
    global $pass, $fail, $fails;
    if ($got === $want) { $pass++; return; }
    $fail++; $fails[] = "  ✗ {$name}\n      got : " . json_encode($got) . "\n      want: " . json_encode($want);
}

/* ---- split: must NOT truncate dish names that contain spice words ---- */
foreach ([
    'hot & sour soup',
    'garlic naan',
    'black garlic chicken',
    'chicken garlic tikka',
    'chilly chicken dry',
    'add veg burger and coke',     // "add" must not be an instruction
    '2 butter chicken',
    'mushroom pepper fry',
    'cheese naan',
    'masala chips',
] as $dish) {
    [$d, $n] = OrderInstructions::split($dish);
    eq("no-split: {$dish} (dish)", $d, $dish);
    eq("no-split: {$dish} (note empty)", $n, '');
}

/* ---- split: must extract trailing instructions ---- */
[$d,$n] = OrderInstructions::split('chicken biryani extra spicy');
eq('biryani dish', $d, 'chicken biryani'); eq('biryani note', $n, 'extra spicy');

[$d,$n] = OrderInstructions::split('butter chicken no onion');
eq('bc dish', $d, 'butter chicken'); eq('bc note', $n, 'no onion');

[$d,$n] = OrderInstructions::split('paneer tikka less oil');
eq('pt dish', $d, 'paneer tikka'); eq('pt note', $n, 'less oil');

[$d,$n] = OrderInstructions::split('2 naan well done');
eq('naan dish', $d, '2 naan'); eq('naan note', $n, 'well done');

[$d,$n] = OrderInstructions::split('biryani no onion no garlic');
eq('multi dish', $d, 'biryani'); eq('multi note', $n, 'no onion no garlic');

[$d,$n] = OrderInstructions::split('chicken handi, less spicy please');
eq('comma dish', $d, 'chicken handi'); eq('comma note', $n, 'less spicy');

/* ---- isInstructionOnly: follow-up instructions ---- */
foreach (['less spicy','make it mild','no onion please','well done','not too spicy','medium spicy','keep it mild','very spicy'] as $s) {
    eq("instr-only TRUE: {$s}", OrderInstructions::isInstructionOnly($s), true);
}
/* ---- isInstructionOnly: real dishes must be FALSE ---- */
foreach (['chicken biryani','garlic naan','hot & sour soup','2 butter chicken','spicy chicken','chilly chicken','mushroom pepper fry'] as $s) {
    eq("instr-only FALSE: {$s}", OrderInstructions::isInstructionOnly($s), false);
}

/* ---- note() extraction ---- */
eq('note() inline', OrderInstructions::note('chicken biryani extra spicy'), 'extra spicy');
eq('note() only',   OrderInstructions::note('make it less spicy please'), 'less spicy');

echo "\n";
foreach ($fails as $f) echo $f . "\n";
echo "\n" . ($fail === 0 ? 'ALL GREEN' : 'FAILURES') . ": {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
