<?php
// Pure test of Vertical::of() (legacy inference) and Vertical::shows() (override layering),
// the path the bot + storefront wiring rely on. Stubs the tenant's setting() API — no framework.
require __DIR__ . '/../app/Support/Vertical.php';

use App\Support\Vertical;

/** Minimal stand-in matching the $tenant->setting($key, $default) contract. */
class StubTenant
{
    public function __construct(private array $s) {}
    public function setting($key, $default = null)
    {
        return array_key_exists($key, $this->s) ? $this->s[$key] : $default;
    }
}

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; printf("FAIL  %-48s got=%s want=%s\n", $label, var_export($got, true), var_export($want, true)); }
}

// --- of(): explicit vertical wins ---
check('explicit grocery',    Vertical::of(new StubTenant(['vertical' => 'grocery'])),    'grocery');
check('explicit restaurant', Vertical::of(new StubTenant(['vertical' => 'restaurant'])), 'restaurant');
check('explicit snacks',     Vertical::of(new StubTenant(['vertical' => 'snacks'])),     'snacks');
check('bogus vertical falls back to grocery', Vertical::of(new StubTenant(['vertical' => 'factory'])), 'grocery');

// --- of(): legacy inference when no explicit vertical ---
check('legacy restaurant_mode -> restaurant', Vertical::of(new StubTenant(['restaurant_mode' => true])), 'restaurant');
check('legacy feature_thali -> snacks',       Vertical::of(new StubTenant(['feature_thali' => true])),   'snacks');
check('no flags -> grocery',                  Vertical::of(new StubTenant([])),                          'grocery');
check('explicit overrides legacy',            Vertical::of(new StubTenant(['vertical' => 'grocery', 'restaurant_mode' => true])), 'grocery');

// --- shows(): end-to-end (resolve + matrix + overrides) ---
$rest = new StubTenant(['vertical' => 'restaurant']);
check('restaurant shows kitchen_board', Vertical::shows($rest, 'kitchen_board'), true);
check('restaurant shows item_options',  Vertical::shows($rest, 'item_options'),  true);
check('restaurant hides daily_thali',   Vertical::shows($rest, 'daily_thali'),   false);

$groc = new StubTenant(['vertical' => 'grocery']);
check('grocery hides kitchen_board', Vertical::shows($groc, 'kitchen_board'), false);
check('grocery shows riders',        Vertical::shows($groc, 'riders'),        true);

$snk = new StubTenant(['vertical' => 'snacks']);
check('snacks shows daily_thali', Vertical::shows($snk, 'daily_thali'), true);
check('snacks hides riders',      Vertical::shows($snk, 'riders'),      false);

// --- shows(): per-feature override layered on top of vertical ---
$snkPos = new StubTenant(['vertical' => 'snacks', 'feature_pos' => true]);
check('snacks + feature_pos override shows pos', Vertical::shows($snkPos, 'pos'), true);

$restNoKitchen = new StubTenant(['vertical' => 'restaurant', 'feature_kitchen_board' => false]);
check('restaurant + override hides kitchen', Vertical::shows($restNoKitchen, 'kitchen_board'), false);

// --- legacy feature_thali maps onto daily_thali regardless of vertical ---
$grocThali = new StubTenant(['vertical' => 'grocery', 'feature_thali' => true]);
check('grocery + legacy feature_thali shows thali', Vertical::shows($grocThali, 'daily_thali'), true);

if ($fail === 0) {
    echo "\nALL GREEN: {$pass} passed, 0 failed.\n";
} else {
    echo "\n{$pass} passed, {$fail} FAILED.\n";
    exit(1);
}
