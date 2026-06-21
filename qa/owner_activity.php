<?php
/**
 * Framework-free QA for Status Intelligence v15 — owner activity scorer.
 * Run: php qa/owner_activity.php
 *
 * Principle: a clear state change (stock word, number+left/price, or an availability verb with a
 * concrete subject) -> auto (>=90). Caption-like freshness messages -> confirm (60-89) or ignore.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$base = __DIR__ . '/../app/Services/Bot/Offers/';
require $base . 'ItemAliases.php';
require $base . 'OfferItemMatcher.php';
require $base . 'OwnerUpdateParser.php';
require $base . 'OwnerActivityScorer.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }
function band(int $c): string { return $c >= 90 ? 'auto' : ($c >= 60 ? 'confirm' : 'ignore'); }
function sc(string $s) { return App\Services\Bot\Offers\OwnerActivityScorer::score($s); }

/* --- clear state changes -> auto --- */
$r = sc('Fafda sold out');
ok('fafda sold out -> sold_out/auto (' . $r['confidence'] . ')', $r['event'] === 'sold_out' && band($r['confidence']) === 'auto');
$r = sc('Only 5 thali left');
ok('only 5 thali left -> low_stock/auto/qty5 (' . $r['confidence'] . ')', $r['event'] === 'low_stock' && $r['qty'] === 5 && band($r['confidence']) === 'auto');
$r = sc('Hot Fafda available now');
ok('hot fafda available now -> available/auto (' . $r['confidence'] . ')', $r['event'] === 'available' && $r['item'] === 'fafda' && band($r['confidence']) === 'auto');
$r = sc('New Jalebi ready');
ok('new jalebi ready -> available/auto (' . $r['confidence'] . ')', $r['event'] === 'available' && $r['item'] === 'jalebi' && band($r['confidence']) === 'auto');
$r = sc('Lunch started');
ok('lunch started -> ready/auto (' . $r['confidence'] . ')', $r['event'] === 'ready' && band($r['confidence']) === 'auto');
$r = sc('Lunch ready');
ok('lunch ready -> ready/auto (' . $r['confidence'] . ')', $r['event'] === 'ready' && band($r['confidence']) === 'auto');
$r = sc('garam garam fafda ready');
ok('garam garam fafda ready -> available/auto (' . $r['confidence'] . ')', $r['event'] === 'available' && $r['item'] === 'fafda' && band($r['confidence']) === 'auto');
$r = sc('Thali price 15000');
ok('thali price 15000 -> price_change/auto (' . $r['confidence'] . ')', $r['event'] === 'price_change' && $r['price'] === 15000 && band($r['confidence']) === 'auto');

/* --- ambiguous / caption-like -> NOT auto --- */
$r = sc("Today's batch ready");
ok('todays batch ready -> ready/confirm (' . $r['confidence'] . ')', $r['event'] === 'ready' && band($r['confidence']) === 'confirm');
$r = sc('Fresh Jalebi ' . "\xF0\x9F\x98\x8D");
ok('fresh jalebi emoji -> available/not-auto (' . $r['confidence'] . ')', $r['event'] === 'available' && $r['confidence'] < 90);
$r = sc('Fresh out of kitchen');
ok('fresh out of kitchen -> ignore (' . $r['confidence'] . ')', band($r['confidence']) === 'ignore');

/* --- question dampener: clear msg with ? -> not auto --- */
$r = sc('is fresh jalebi available now?');
ok('question dampened below auto (' . $r['confidence'] . ')', $r['confidence'] < 90);

/* --- pure chatter -> no event --- */
ok('greeting -> no event', sc('good morning bhai')['event'] === null);
ok('thanks -> no event',   sc('thank you so much')['event'] === null);

echo "\n=== owner_activity: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
