<?php
/**
 * qa/weight_reply.php — pure-logic QA for the sold-by-weight size-reply interpreter.
 * No framework: requires the two pricing helpers directly. Run: php qa/weight_reply.php
 */

require __DIR__ . '/../app/Services/Bot/Pricing/WeightParser.php';
require __DIR__ . '/../app/Services/Bot/Pricing/WeightReply.php';

use App\Services\Bot\Pricing\WeightReply;

$OFFERED = [250, 500, 1000];               // Jalebi card sizes
$NAME    = ['jalebi', '1', 'kg'];          // tokens of "Jalebi 1 Kg"

$pass = 0; $fail = 0;
function t($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    if ($ok) { $pass++; }
    else { $fail++; echo "  FAIL  $label  got=" . var_export($got, true) . " want=" . var_export($want, true) . "\n"; }
}
function g($text, $offered, $name) { return WeightReply::grams($text, $offered, $name); }

// --- bare number matching an offered size -> grams ---
t('bare 250',            g('250', $OFFERED, $NAME), 250);
t('bare 500',            g('500', $OFFERED, $NAME), 500);
t('bare 1000',           g('1000', $OFFERED, $NAME), 1000);

// --- list indices must NEVER become grams ---
t('index 1 -> null',     g('1', $OFFERED, $NAME), null);
t('index 2 -> null',     g('2', $OFFERED, $NAME), null);
t('index 3 -> null',     g('3', $OFFERED, $NAME), null);

// --- arbitrary bare number not on the card -> null (don't guess grams) ---
t('bare 750 -> null',    g('750', $OFFERED, $NAME), null);
t('bare 99 -> null',     g('99', $OFFERED, $NAME), null);

// --- explicit weight tokens ---
t('250g',                g('250g', $OFFERED, $NAME), 250);
t('250 g',               g('250 g', $OFFERED, $NAME), 250);
t('250gm',               g('250gm', $OFFERED, $NAME), 250);
t('250 gram',            g('250 gram', $OFFERED, $NAME), 250);
t('500 grams',           g('500 grams', $OFFERED, $NAME), 500);
t('1kg',                 g('1kg', $OFFERED, $NAME), 1000);
t('1 kg',                g('1 kg', $OFFERED, $NAME), 1000);
t('0.5kg',               g('0.5kg', $OFFERED, $NAME), 500);
t('0.25 kg',             g('0.25 kg', $OFFERED, $NAME), 250);
t('explicit 300g (off-card, priceable)', g('300g', $OFFERED, $NAME), 300);
t('explicit 750g',       g('750g', $OFFERED, $NAME), 750);

// --- size + focal product name still counts as a size reply for this item ---
t('250 gram jalebi',     g('250 gram jalebi', $OFFERED, $NAME), 250);
t('jalebi 500g',         g('jalebi 500g', $OFFERED, $NAME), 500);
t('add 250g please',     g('add 250g please', $OFFERED, $NAME), 250);
t('i want 1kg',          g('i want 1kg', $OFFERED, $NAME), 1000);

// --- a DIFFERENT product named -> not a size reply for this item ---
t('250g rice -> null',   g('250g rice', $OFFERED, $NAME), null);
t('500g sugar -> null',  g('500g sugar', $OFFERED, $NAME), null);

// --- no weight at all -> null (normal flow) ---
t('jalebi -> null',      g('jalebi', $OFFERED, $NAME), null);
t('hi -> null',          g('hi', $OFFERED, $NAME), null);
t('checkout -> null',    g('checkout', $OFFERED, $NAME), null);
t('empty -> null',       g('', $OFFERED, $NAME), null);
t('whitespace -> null',  g('   ', $OFFERED, $NAME), null);

// --- a product with REAL variants (e.g. 100/200/500) gates the bare-number rule to those ---
$VAR = [100, 200, 500]; $N2 = ['kaju', 'katli'];
t('variant bare 200',    g('200', $VAR, $N2), 200);
t('variant bare 250 -> null (not offered)', g('250', $VAR, $N2), null);
t('variant 100g',        g('100g', $VAR, $N2), 100);

echo "\n=== weight_reply: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
