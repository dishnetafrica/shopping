<?php
/**
 * qa/wholesale_units.php — proves the wholesale unit feature: per-piece price, pack/MOQ labels, the
 * MOQ-aware cart (first add jumps to MOQ; dropping below MOQ removes the line), and importer clamping.
 * Mirrors the storefront JS + importer logic. Run: php qa/wholesale_units.php
 */

function minq(array $p): int { return max(1, $p['moq'] ?? 1); }
function per_piece(array $p): ?int { $ps = $p['packSize'] ?? 0; return $ps >= 2 ? (int) round($p['price'] / $ps) : null; }
function pack_label(array $p): string {
    if (empty($p['unit']) && empty($p['packSize'])) return '';
    $t = ! empty($p['unit']) ? ucfirst($p['unit']) : 'Pack';
    if (! empty($p['packSize'])) $t .= ' · ' . $p['packSize'] . ' pcs';
    return $t;
}
// cart ops
function cart_inc(array &$cart, array $p): void { $k = $p['name']; if (! isset($cart[$k])) $cart[$k] = minq($p); else $cart[$k]++; }
function cart_dec(array &$cart, array $p): void { $k = $p['name']; if (! isset($cart[$k])) return; $cart[$k]--; if ($cart[$k] < minq($p)) unset($cart[$k]); }
// importer clamp (blank -> null, else >=1)
function imp(int|string $v): ?int { return $v === '' ? null : max(1, (int) $v); }

$pass = 0; $fail = 0;
function check($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok  $l\n"; } else { $fail++; echo "  XX  $l\n"; } }

echo "=== wholesale_units QA ===\n";

$carton = ['name' => 'Europearl TP 150', 'price' => 75000, 'packSize' => 100, 'unit' => 'carton', 'moq' => 1];
$napkin = ['name' => 'Napkin',           'price' => 95000, 'packSize' => 60,  'unit' => 'carton', 'moq' => 2];
$retail = ['name' => 'Milk 1L',          'price' => 90,    'packSize' => 0,   'unit' => '',        'moq' => 1]; // normal product, no opt-in

// per-piece
check('per-piece 75000/100 = 750',        per_piece($carton) === 750);
check('per-piece 95000/60 = 1583',        per_piece($napkin) === 1583);
check('no per-piece when packSize<2',     per_piece($retail) === null);

// labels
check('pack label "Carton · 100 pcs"',    pack_label($carton) === 'Carton · 100 pcs');
check('retail product shows no pack label', pack_label($retail) === '');

// minq
check('minq carton = 1', minq($carton) === 1);
check('minq napkin = 2', minq($napkin) === 2);
check('minq retail = 1', minq($retail) === 1);

// MOQ cart: normal product behaves exactly as before (0->1, remove at 0)
$c = [];
cart_inc($c, $retail); check('retail add -> qty 1', $c['Milk 1L'] === 1);
cart_dec($c, $retail); check('retail dec -> removed', ! isset($c['Milk 1L']));

// MOQ=2: first add jumps to 2, +1 -> 3, then -1 ->2, -1 -> below min -> removed
$c = [];
cart_inc($c, $napkin); check('MOQ-2 first add jumps to 2', $c['Napkin'] === 2);
cart_inc($c, $napkin); check('then +1 -> 3', $c['Napkin'] === 3);
cart_dec($c, $napkin); check('then -1 -> 2', $c['Napkin'] === 2);
cart_dec($c, $napkin); check('-1 below min -> removed', ! isset($c['Napkin']));

// importer clamp
check('importer blank -> null', imp('') === null);
check('importer "0" -> 1 (min)', imp('0') === 1);
check('importer "100" -> 100', imp('100') === 100);

echo "\n$pass / " . ($pass + $fail) . " passed\n";
echo $fail === 0 ? "ALL GREEN\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
