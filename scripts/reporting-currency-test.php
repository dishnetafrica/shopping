<?php

/**
 * Standalone unit test for the ReportingCurrency engine (no Laravel bootstrap needed).
 * Uses Fx::forTenant(null) → documented defaults UGX 3655, SSP 5180 per USD.
 */

spl_autoload_register(function ($class) {
    if (strncmp($class, 'App\\', 4) !== 0) return;
    $path = __DIR__ . '/../' . str_replace('\\', '/', preg_replace('/^App\\\\/', 'app/', $class)) . '.php';
    if (is_file($path)) require $path;
});

use App\Services\Money\ReportingCurrency;

$fail = 0;
$assert = function (bool $c, string $m) use (&$fail) { echo ($c ? '  ok  ' : '  FAIL') . " $m\n"; if (!$c) $fail++; };
$near = fn (float $a, float $b) => abs($a - $b) < 0.5;

// Base = UGX (default), consolidated mode.
$rc = ReportingCurrency::forTenant(null, null);
$assert($rc->consolidationCurrency() === 'UGX', 'default base is UGX');
$assert(!$rc->isOriginal(), 'default mode is consolidated');

// A USD 100 invoice consolidates to UGX 365,500.
$line = $rc->line(100, 'USD');
$assert($near($line['amount'], 365500.0) && $line['currency'] === 'UGX', 'USD 100 -> UGX 365,500 consolidated');

// A native UGX figure stays put.
$assert($near($rc->line(50000, 'UGX')['amount'], 50000.0), 'UGX stays UGX in base mode');

// Mixed-currency consolidation: UGX 3,655 + USD 1 = UGX 7,310.
$sum = $rc->consolidate([
    ['amount' => 3655, 'currency' => 'UGX'],
    ['amount' => 1, 'currency' => 'USD'],
]);
$assert($near($sum['amount'], 7310.0) && $sum['currency'] === 'UGX', 'mixed consolidate UGX+USD = UGX 7,310');

// Stored USD is preferred over recomputing (historical rate honoured).
$sumUsd = ReportingCurrency::forTenant(null, 'USD')->consolidate([
    ['amount' => 999999, 'currency' => 'UGX', 'usd' => 5.0],   // native amount ignored; stored USD wins
]);
$assert($near($sumUsd['amount'], 5.0) && $sumUsd['currency'] === 'USD', 'stored USD preferred over native reconvert');

// Original mode: values keep their own currency; KPI tiles still consolidate to base.
$orig = ReportingCurrency::forTenant(null, 'original');
$assert($orig->isOriginal(), 'override "original" => original mode');
$l = $orig->line(100, 'USD');
$assert($near($l['amount'], 100.0) && $l['currency'] === 'USD', 'original mode keeps USD native');
$assert($orig->consolidationCurrency() === 'UGX', 'original mode tiles still consolidate to base');

// Override to a specific display currency (USD).
$usd = ReportingCurrency::forTenant(null, 'USD');
$assert($near($usd->line(3655, 'UGX')['amount'], 1.0) && $usd->displayCurrency() === 'USD', 'override USD: UGX 3,655 -> $1');

// Unrateable currency with no stored USD is never corrupted (shown native).
$eur = $rc->line(100, 'EUR');
$assert($near($eur['amount'], 100.0), 'EUR with no USD stays 100 (never corrupted)');


// Regression: a converted UGX order total (770 x 3655) viewed in USD must read back as ~$770, not $2.8M.
$usd2=ReportingCurrency::forTenant(null,'USD');
$assert($near($usd2->line(2814350,'UGX')['amount'],770.0),'UGX 2,814,350 -> ~$770 in USD view (converted-order fix)');

echo $fail === 0 ? "\nREPORTING-CURRENCY PASS\n" : "\nREPORTING-CURRENCY FAILED ($fail)\n";
exit($fail === 0 ? 0 : 1);
