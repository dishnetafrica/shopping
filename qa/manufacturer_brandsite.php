<?php
/** qa/manufacturer_brandsite.php — manufacturer business type, brand-site gating, quick-order links. */
require __DIR__ . '/../app/Support/Vertical.php';
use App\Support\Vertical;

$pass = 0; $fail = 0;
function check($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok  $l\n"; } else { $fail++; echo "  XX  $l\n"; } }

echo "=== manufacturer_brandsite QA ===\n";

// --- vertical (real class) ---
check('manufacturer is a valid vertical', Vertical::isValid('manufacturer'));
check('manufacturer in LABELS', isset(Vertical::LABELS['manufacturer']));
check('manufacturer shows riders',  Vertical::decide('manufacturer', [], 'riders') === true);
check('manufacturer shows pos',     Vertical::decide('manufacturer', [], 'pos') === true);
check('manufacturer hides kitchen', Vertical::decide('manufacturer', [], 'kitchen_board') === false);
check('manufacturer hides item_options', Vertical::decide('manufacturer', [], 'item_options') === false);
check('grocery unchanged (no kitchen)', Vertical::decide('grocery', [], 'kitchen_board') === false);
check('restaurant unchanged (kitchen on)', Vertical::decide('restaurant', [], 'kitchen_board') === true);
check('per-tenant override still wins', Vertical::decide('manufacturer', ['pos' => false], 'pos') === false);

// --- brand-site enable (mirror of StorefrontController::brandSiteEnabled) ---
function brandEnabled(string $vertical, $flag): bool {
    if ($flag !== null && $flag !== '') return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    return $vertical === 'manufacturer';
}
check('manufacturer -> brand site on by default', brandEnabled('manufacturer', null) === true);
check('grocery -> brand site off', brandEnabled('grocery', null) === false);
check('explicit brand_site=true forces on for grocery', brandEnabled('grocery', '1') === true);
check('explicit brand_site=false forces off for manufacturer', brandEnabled('manufacturer', '0') === false);

// --- quick-order link parse (mirror of shop.html applyQuickOrder intent) ---
function parseQO(string $qs): array {
    parse_str(ltrim($qs, '?'), $o);
    return ['add' => $o['add'] ?? null, 'cat' => $o['cat'] ?? null];
}
$a = parseQO('?add=Europearl%20Toilet%20Paper%20Virgin%20150%20Sheets%20(100%20rolls%2Fcarton)');
check('?add parses product name', $a['add'] === 'Europearl Toilet Paper Virgin 150 Sheets (100 rolls/carton)' && $a['cat'] === null);
$c = parseQO('?cat=Toilet%20Paper');
check('?cat parses category', $c['cat'] === 'Toilet Paper' && $c['add'] === null);
check('no params -> nothing', parseQO('') === ['add' => null, 'cat' => null]);

echo "\n$pass / " . ($pass + $fail) . " passed\n";
echo $fail === 0 ? "ALL GREEN\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
