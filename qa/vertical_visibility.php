<?php
// Pure-logic test of the tenant-vertical visibility matrix (App\Support\Vertical).
// Framework-free: tests Vertical::decide() directly with hand-built tuples — no DB, no auth.
require __DIR__ . '/../app/Support/Vertical.php';

use App\Support\Vertical;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; printf("FAIL  %-50s got=%s want=%s\n", $label, var_export($got, true), var_export($want, true)); }
}

// --- Grocery: riders + pos; NOT kitchen/modifiers/thali ---
check('grocery riders',        Vertical::decide('grocery', [], 'riders'),        true);
check('grocery pos',           Vertical::decide('grocery', [], 'pos'),           true);
check('grocery kitchen_board', Vertical::decide('grocery', [], 'kitchen_board'), false);
check('grocery item_options',  Vertical::decide('grocery', [], 'item_options'),  false);
check('grocery daily_thali',   Vertical::decide('grocery', [], 'daily_thali'),   false);

// --- Restaurant: kitchen + modifiers + riders + pos; thali off by default ---
check('restaurant kitchen_board', Vertical::decide('restaurant', [], 'kitchen_board'), true);
check('restaurant item_options',  Vertical::decide('restaurant', [], 'item_options'),  true);
check('restaurant riders',        Vertical::decide('restaurant', [], 'riders'),        true);
check('restaurant pos',           Vertical::decide('restaurant', [], 'pos'),           true);
check('restaurant daily_thali',   Vertical::decide('restaurant', [], 'daily_thali'),   false);

// --- Snacks: thali only; no riders/pos/kitchen/modifiers by default ---
check('snacks daily_thali',   Vertical::decide('snacks', [], 'daily_thali'),   true);
check('snacks riders',        Vertical::decide('snacks', [], 'riders'),        false);
check('snacks pos',           Vertical::decide('snacks', [], 'pos'),           false);
check('snacks kitchen_board', Vertical::decide('snacks', [], 'kitchen_board'), false);
check('snacks item_options',  Vertical::decide('snacks', [], 'item_options'),  false);

// --- Overrides force-ON (the "opt" cells) ---
check('snacks pos override on',          Vertical::decide('snacks',  ['pos' => true],          'pos'),          true);
check('snacks item_options override on', Vertical::decide('snacks',  ['item_options' => true], 'item_options'), true);
check('grocery thali override on',       Vertical::decide('grocery', ['daily_thali' => true],  'daily_thali'),  true);

// --- Overrides force-OFF (even where default-on) ---
check('restaurant kitchen override off', Vertical::decide('restaurant', ['kitchen_board' => false], 'kitchen_board'), false);
check('grocery riders override off',     Vertical::decide('grocery',    ['riders' => false],        'riders'),        false);

// --- Unknown feature => universal (never accidentally hide core nav) ---
check('grocery unknown=orders',   Vertical::decide('grocery', [], 'orders'),    true);
check('snacks unknown=dashboard', Vertical::decide('snacks',  [], 'dashboard'), true);

// --- Vertical validity ---
check('isValid grocery', Vertical::isValid('grocery'), true);
check('isValid bogus',   Vertical::isValid('factory'), false);
check('isValid null',    Vertical::isValid(null),      false);

if ($fail === 0) {
    echo "\nALL GREEN: {$pass} passed, 0 failed.\n";
} else {
    echo "\n{$pass} passed, {$fail} FAILED.\n";
    exit(1);
}
