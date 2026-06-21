<?php
/**
 * Framework-free QA for Status Intelligence v15-v17 — owner activity scorer + bands.
 * Run: php qa/owner_activity.php
 *
 * v17 bands: >=95 auto, 70-94 review, <70 feed.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$base = __DIR__ . '/../app/Services/Bot/Offers/';
require $base . 'ItemAliases.php';
require $base . 'OfferItemMatcher.php';
require $base . 'OwnerUpdateParser.php';
require $base . 'OwnerActivityScorer.php';
require $base . 'ActivityBand.php';

use App\Services\Bot\Offers\OwnerActivityScorer as SC;
use App\Services\Bot\Offers\ActivityBand as B;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }
function sc(string $s) { return App\Services\Bot\Offers\OwnerActivityScorer::score($s); }
function bandOf(string $s) { return App\Services\Bot\Offers\ActivityBand::of((int) App\Services\Bot\Offers\OwnerActivityScorer::score($s)['confidence']); }

/* --- band thresholds (pure) --- */
ok('95 -> auto',   B::of(95) === B::AUTO);
ok('94 -> review', B::of(94) === B::REVIEW);
ok('70 -> review', B::of(70) === B::REVIEW);
ok('69 -> feed',   B::of(69) === B::FEED);

/* --- very high confidence -> auto --- */
$r = sc('Hot Fafda available now');
ok('hot fafda available now -> available/auto (' . $r['confidence'] . ')', $r['event'] === 'available' && bandOf('Hot Fafda available now') === B::AUTO);
$r = sc('New Jalebi ready');
ok('new jalebi ready -> available/auto (' . $r['confidence'] . ')', $r['event'] === 'available' && bandOf('New Jalebi ready') === B::AUTO);
ok('garam garam fafda ready -> auto', sc('garam garam fafda ready')['event'] === 'available' && bandOf('garam garam fafda ready') === B::AUTO);

/* --- clear-but-moderate -> review (the safe default) --- */
$r = sc('Fafda sold out');
ok('fafda sold out -> sold_out/review (' . $r['confidence'] . ')', $r['event'] === 'sold_out' && bandOf('Fafda sold out') === B::REVIEW);
$r = sc('Only 5 thali left');
ok('only 5 thali left -> low_stock/review/qty5 (' . $r['confidence'] . ')', $r['event'] === 'low_stock' && $r['qty'] === 5 && bandOf('Only 5 thali left') === B::REVIEW);
$r = sc('Lunch started');
ok('lunch started -> ready/review (' . $r['confidence'] . ')', $r['event'] === 'ready' && bandOf('Lunch started') === B::REVIEW);
$r = sc('Lunch ready');
ok('lunch ready -> ready/review (' . $r['confidence'] . ')', $r['event'] === 'ready' && bandOf('Lunch ready') === B::REVIEW);
$r = sc('Thali price 15000');
ok('thali price 15000 -> price_change/review (' . $r['confidence'] . ')', $r['event'] === 'price_change' && $r['price'] === 15000 && bandOf('Thali price 15000') === B::REVIEW);
$r = sc("Today's batch ready");
ok('todays batch ready -> ready/review (' . $r['confidence'] . ')', $r['event'] === 'ready' && bandOf("Today's batch ready") === B::REVIEW);

/* --- caption-like / vague -> feed only --- */
ok('fresh jalebi emoji -> feed', bandOf('Fresh Jalebi ' . "\xF0\x9F\x98\x8D") === B::FEED);
ok('fresh out of kitchen -> feed', bandOf('Fresh out of kitchen') === B::FEED);
ok('question dampened -> not auto', bandOf('is fresh jalebi available now?') !== B::AUTO);

/* --- chatter -> no event --- */
ok('greeting -> no event', sc('good morning bhai')['event'] === null);
ok('thanks -> no event',   sc('thank you so much')['event'] === null);

echo "\n=== owner_activity: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
