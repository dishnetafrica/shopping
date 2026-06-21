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
    if ($got === $want) { $pass++; }
    else { $fail++; echo "  FAIL  $label  got=" . var_export($got, true) . " want=" . var_export($want, true) . "\n"; }
}
function g($text, $offered, $name) { return WeightReply::grams($text, $offered, $name); }

// --- bare number matching an offered size ---
t('bare 250',            g('250', $OFFERED, $NAME), 250);
t('bare 500',            g('500', $OFFERED, $NAME), 500);
t('bare 1000',           g('1000', $OFFERED, $NAME), 1000);

// --- list indices must NEVER become grams ---
t('index 1 -> null',     g('1', $OFFERED, $NAME), null);
t('index 2 -> null',     g('2', $OFFERED, $NAME), null);
t('index 3 -> null',     g('3', $OFFERED, $NAME), null);

// --- arbitrary bare number not on the card -> null ---
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
t('0.5 kg',              g('0.5 kg', $OFFERED, $NAME), 500);
t('1.5 kg',              g('1.5 kg', $OFFERED, $NAME), 1500);
t('off-card 300g',       g('300g', $OFFERED, $NAME), 300);
t('explicit 750g',       g('750g', $OFFERED, $NAME), 750);

// --- fraction of a kg (absolute) ---
t('half kg',             g('half kg', $OFFERED, $NAME), 500);
t('half kilo',           g('half kilo', $OFFERED, $NAME), 500);
t('half a kg',           g('half a kg', $OFFERED, $NAME), 500);
t('1/2 kg',              g('1/2 kg', $OFFERED, $NAME), 500);
t('quarter kg',          g('quarter kg', $OFFERED, $NAME), 250);
t('quarter kilo',        g('quarter kilo', $OFFERED, $NAME), 250);
t('1/4 kg',              g('1/4 kg', $OFFERED, $NAME), 250);
t('three quarter kg',    g('three quarter kg', $OFFERED, $NAME), 750);
t('3/4 kg',              g('3/4 kg', $OFFERED, $NAME), 750);
t('full kg',             g('full kg', $OFFERED, $NAME), 1000);
t('one kg (word)',       g('one kg', $OFFERED, $NAME), 1000);
t('two kg (word)',       g('two kg', $OFFERED, $NAME), 2000);

// --- fraction + focal product name still counts ---
t('half kg jalebi',      g('half kg jalebi', $OFFERED, $NAME), 500);
t('quarter kg jalebi',   g('quarter kg jalebi', $OFFERED, $NAME), 250);
t('i want half kg',      g('i want half kg', $OFFERED, $NAME), 500);

// --- bare relative words vs largest offered (1kg ladder) ---
t('bare half',           g('half', $OFFERED, $NAME), 500);
t('bare quarter',        g('quarter', $OFFERED, $NAME), 250);
t('bare full',           g('full', $OFFERED, $NAME), 1000);
t('bare whole',          g('whole', $OFFERED, $NAME), 1000);
t('bare three quarter',  g('three quarter', $OFFERED, $NAME), 750);

// --- size + DIFFERENT product -> null ---
t('250g rice -> null',   g('250g rice', $OFFERED, $NAME), null);
t('half kg rice -> null', g('half kg rice', $OFFERED, $NAME), null);
t('half rice -> null',   g('half rice', $OFFERED, $NAME), null);

// --- no weight at all -> null ---
t('jalebi -> null',      g('jalebi', $OFFERED, $NAME), null);
t('hi -> null',          g('hi', $OFFERED, $NAME), null);
t('checkout -> null',    g('checkout', $OFFERED, $NAME), null);
t('empty -> null',       g('', $OFFERED, $NAME), null);
t('three (bare) -> null', g('three', $OFFERED, $NAME), null);

// --- a product with REAL variants gates bare numbers + anchors relatives to its max ---
$VAR = [100, 200, 500]; $N2 = ['kaju', 'katli'];
t('variant bare 200',    g('200', $VAR, $N2), 200);
t('variant bare 250 -> null', g('250', $VAR, $N2), null);
t('variant 100g',        g('100g', $VAR, $N2), 100);
t('variant bare full=500', g('full', $VAR, $N2), 500);
t('variant bare half=250', g('half', $VAR, $N2), 250);
t('variant half kg (abs)=500', g('half kg', $VAR, $N2), 500);

echo "\n=== weight_reply: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
