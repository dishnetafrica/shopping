<?php declare(strict_types=1);

/**
 * Pure, sandbox-runnable proof of CatalogueMatcher::ambiguityCandidates() — the guard that stops the
 * bot silently quoting the WRONG SKU when a generic term (e.g. "aluminium foil") maps to many products
 * at very different prices.  php tests/Bot/quote_ambiguity_harness.php
 */

require __DIR__ . '/../../app/Services/Bot/CatalogueMatcher.php';

use App\Services\Bot\CatalogueMatcher;

$m = new CatalogueMatcher();
$pass = 0; $fail = 0;

function hit(string $name, float $price, float $score): array {
    return ['product' => ['name' => $name, 'price' => $price], 'score' => $score];
}
function check(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  \e[32mPASS\e[0m  %s\n", $name); }
    else { $fail++; printf("  \e[31mFAIL\e[0m  %s\n", $name); }
}

echo "\nCatalogueMatcher ambiguity guard\n" . str_repeat('─', 60) . "\n";

// The real bug: bare "aluminium foil" — 8 near-tied SKUs, $33…$155, no distinguishing word → AMBIGUOUS.
$foil = [
    hit('Aluminium Foil 45CM*150/300 3KG Carton Of 6pc', 137, 245),
    hit('Fresh Wrap Aluminium Foil 100M*45cm (Carton Of 6PC)', 114, 244),
    hit('ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON)', 33, 243),
    hit('Fresh Wrap Aluminium Foil 150M*30cm (Carton Of 6PC)', 155, 242),
];
$amb = $m->ambiguityCandidates($foil);
check('bare "aluminium foil" is flagged ambiguous', $amb !== null);
check('  → candidates returned cheapest-first (33 first)', $amb && (int) $amb[0]['price'] === 33);
check('  → the $33 GOLDEN and a pricier foil both offered', $amb && count($amb) >= 2 && (int) end($amb)['price'] > 33);

// A distinguishing word ("golden") pulls the right SKU clear (+120) → NOT ambiguous, quote it.
$golden = [
    hit('ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON)', 33, 365),
    hit('Aluminium Foil 45CM*150/300 3KG Carton Of 6pc', 137, 245),
    hit('Fresh Wrap Aluminium Foil 100M*45cm (Carton Of 6PC)', 114, 244),
];
check('"aluminium foil golden" → clear leader, not ambiguous', $m->ambiguityCandidates($golden) === null);

// Exact-name match (score >= 1000) is never ambiguous.
$exact = [hit('ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON)', 33, 1243), hit('Other Foil', 137, 243)];
check('exact name match → not ambiguous', $m->ambiguityCandidates($exact) === null);

// Near-tie but NARROW price spread (1.2×) → fine, quote the leader.
$narrow = [hit('Brand A Sugar 50kg', 100, 245), hit('Brand B Sugar 50kg', 118, 244)];
check('near-tie, narrow spread (<3×) → not ambiguous', $m->ambiguityCandidates($narrow) === null);

// A single hit is never ambiguous.
check('single hit → not ambiguous', $m->ambiguityCandidates([hit('Only Foil', 33, 245)]) === null);

// A clear head-noun leader ("rice" +250 type score) beats the tied field → not ambiguous.
$rice = [hit('Basmati Rice 25kg', 60, 495), hit('Rice Crackers 200g', 3, 245), hit('Rice Powa 1kg', 5, 244)];
check('"rice" with a clear leader → not ambiguous', $m->ambiguityCandidates($rice) === null);

echo str_repeat('─', 60) . "\n";
printf("%d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
