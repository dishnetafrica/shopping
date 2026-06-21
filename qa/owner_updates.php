<?php
/**
 * Framework-free QA for Status Intelligence v14 — owner intent learning.
 * Run: php qa/owner_updates.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$base = __DIR__ . '/../app/Services/Bot/Offers/';
require $base . 'ItemAliases.php';
require $base . 'OfferItemMatcher.php';
require $base . 'OwnerUpdateParser.php';
require $base . 'StateQueryParser.php';

use App\Services\Bot\Offers\OwnerUpdateParser as O;
use App\Services\Bot\Offers\StateQueryParser as S;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

/* ----------------------------------------------------- OwnerUpdateParser */
function u(string $s) { return App\Services\Bot\Offers\OwnerUpdateParser::parse($s); }

$a = u('Only 5 Thali Left');
ok('only 5 thali left -> low_stock/thali/5', $a && $a['event'] === 'low_stock' && $a['item'] === 'thali' && $a['qty'] === 5);
$a = u('5 thali baki');
ok('5 thali baki -> low_stock/5', $a && $a['event'] === 'low_stock' && $a['qty'] === 5 && $a['item'] === 'thali');
$a = u('only 5 left');
ok('only 5 left -> low_stock default thali', $a && $a['event'] === 'low_stock' && $a['item'] === 'thali' && $a['qty'] === 5);

$a = u('Fafda Sold Out');
ok('fafda sold out -> sold_out/fafda', $a && $a['event'] === 'sold_out' && $a['item'] === 'fafda');
$a = u('Fafda finished');
ok('fafda finished -> sold_out', $a && $a['event'] === 'sold_out' && $a['item'] === 'fafda');
$a = u('no more jalebi');
ok('no more jalebi -> sold_out/jalebi', $a && $a['event'] === 'sold_out' && $a['item'] === 'jalebi');

$a = u('Fresh Jalebi Ready');
ok('fresh jalebi ready -> available/jalebi', $a && $a['event'] === 'available' && $a['item'] === 'jalebi');
$a = u('garam garam jalebi');
ok('garam garam jalebi -> available/jalebi', $a && $a['event'] === 'available' && $a['item'] === 'jalebi');
$a = u('Jalebi available');
ok('jalebi available -> available', $a && $a['event'] === 'available' && $a['item'] === 'jalebi');

$a = u('Lunch Ready');
ok('lunch ready -> ready/lunch', $a && $a['event'] === 'ready' && $a['item'] === 'lunch');
$a = u('dinner ready');
ok('dinner ready -> ready/dinner', $a && $a['event'] === 'ready' && $a['item'] === 'dinner');

$a = u('Thali price 15000');
ok('thali price 15000 -> price_change', $a && $a['event'] === 'price_change' && $a['item'] === 'thali' && $a['price'] === 15000);
$a = u('jalebi now 5000');
ok('jalebi now 5000 -> price_change/jalebi', $a && $a['event'] === 'price_change' && $a['item'] === 'jalebi' && $a['price'] === 5000);

ok('bare "done" -> null',  u('done') === null);
ok('bare "ready" -> null', u('ready') === null);
ok('chatter -> null',      u('ok thanks brother') === null);
ok('long message -> null', u('hey how are you doing today my friend right now ok') === null);

/* ------------------------------------------------------ StateQueryParser */
function s(string $x) { return App\Services\Bot\Offers\StateQueryParser::detect($x); }

$b = s('Lunch ready?');
ok('lunch ready? -> ready/lunch', $b && $b['kind'] === 'ready' && $b['item'] === 'lunch');
$b = s('Thali baki che?');
ok('thali baki che? -> remaining/thali', $b && $b['kind'] === 'remaining' && $b['item'] === 'thali');
$b = s('kitla thali baki');
ok('kitla thali baki -> remaining/thali', $b && $b['kind'] === 'remaining' && $b['item'] === 'thali');
$b = s('thali baki?');
ok('thali baki? -> remaining/thali', $b && $b['kind'] === 'remaining' && $b['item'] === 'thali');
$b = s('is jalebi ready');
ok('is jalebi ready -> ready/jalebi', $b && $b['kind'] === 'ready' && $b['item'] === 'jalebi');

ok('fafda che? -> null (item path)', s('fafda che?') === null);
ok('hello -> null',                  s('hello') === null);
ok('plain thali -> null',            s('thali') === null);

echo "\n=== owner_updates: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
