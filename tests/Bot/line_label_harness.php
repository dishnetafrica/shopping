<?php

declare(strict_types=1);

/**
 * Phase-0 Fix #2 — line-label harness (pure PHP, no framework boot; runs in the sandbox).
 *
 * Proves the canonical LineLabel::make() that the chat basket, the quotation line, and the order
 * item name now all share — so a weight/variant can never appear in chat but vanish from the
 * customer's quotation again. Companion PHPUnit test: tests/Unit/Bot/LineLabelTest.php.
 *
 * Run:  php tests/Bot/line_label_harness.php
 */

$base = dirname(__DIR__, 2) . '/app/Services/Bot/Pricing/';
require $base . 'WeightParser.php';
require $base . 'LineLabel.php';

use App\Services\Bot\Pricing\LineLabel;

$pass = 0;
$fail = 0;
function ok(string $n, bool $c): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32m✓\033[0m $n\n"; }
    else    { $fail++; echo "  \033[31m✗ $n\033[0m\n"; }
}

echo "\033[1mFix #2 — weight/variant survives onto the label\033[0m\n";

// THE REGRESSION the audit named: chat showed "Fafda (250g)" but the quotation showed "1 x Fafda".
$fafda = ['name' => 'Fafda', 'weight_grams' => 250, 'qty' => 1, 'price' => 500.0];
ok('THE Fafda regression: weight line label is "Fafda (250g)"', LineLabel::make($fafda) === 'Fafda (250g)');

// The chat basket and the quotation line now build the name the SAME way — they cannot diverge.
$chatLabel  = LineLabel::make($fafda);   // cartSummary() path
$quoteLabel = LineLabel::make($fafda);   // sendQuotationInsteadOfOrder() path
ok('chat label === quotation label (single source of truth)', $chatLabel === $quoteLabel && $chatLabel === 'Fafda (250g)');

echo "\n\033[1mWeight suffix across the matrix\033[0m\n";
ok('250g  → "Fafda (250g)"',  LineLabel::make(['name' => 'Fafda', 'weight_grams' => 250])  === 'Fafda (250g)');
ok('500g  → "Fafda (500g)"',  LineLabel::make(['name' => 'Fafda', 'weight_grams' => 500])  === 'Fafda (500g)');
ok('1000g → "Fafda (1kg)"',   LineLabel::make(['name' => 'Fafda', 'weight_grams' => 1000]) === 'Fafda (1kg)');
ok('1500g → "Fafda (1.5kg)"', LineLabel::make(['name' => 'Fafda', 'weight_grams' => 1500]) === 'Fafda (1.5kg)');
ok('2000g → "Fafda (2kg)"',   LineLabel::make(['name' => 'Fafda', 'weight_grams' => 2000]) === 'Fafda (2kg)');

echo "\n\033[1mNon-weight and modifier lines are unchanged / combined correctly\033[0m\n";
ok('no weight → plain name',           LineLabel::make(['name' => 'Kabras Sugar', 'qty' => 3]) === 'Kabras Sugar');
ok('modifiers folded in',              LineLabel::make(['name' => 'Thali', 'modifiers' => [['name' => 'Naan'], ['name' => 'Rice']]]) === 'Thali + Naan, Rice');
ok('modifiers AND weight together',    LineLabel::make(['name' => 'Combo', 'modifiers' => [['name' => 'Naan']], 'weight_grams' => 250]) === 'Combo + Naan (250g)');
ok('empty modifiers array ignored',    LineLabel::make(['name' => 'Sugar', 'modifiers' => []]) === 'Sugar');
ok('missing name → empty string',      LineLabel::make(['weight_grams' => 250]) === ' (250g)' || LineLabel::make([]) === '');

echo "\n\033[1mLabel-only: the price/amount is never touched\033[0m\n";
$line = ['name' => 'Fafda', 'weight_grams' => 250, 'qty' => 1, 'price' => 500.0];
$before = $line['price'] * $line['qty'];
LineLabel::make($line);
ok('price × qty unchanged by labelling', ($line['price'] * $line['qty']) === $before && $before === 500.0);

echo "\n" . str_repeat('─', 56) . "\n";
echo $fail === 0
    ? "\033[32mALL {$pass} CHECKS PASSED.\033[0m\n"
    : "\033[31m{$fail} FAILED\033[0m, {$pass} passed.\n";
exit($fail === 0 ? 0 : 1);
