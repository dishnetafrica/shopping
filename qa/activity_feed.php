<?php
/**
 * Framework-free QA for Status Intelligence v16 — passive owner learning.
 * Run: php qa/activity_feed.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$base = __DIR__ . '/../app/Services/Bot/Offers/';
require $base . 'ItemAliases.php';
require $base . 'OfferItemMatcher.php';
require $base . 'OwnerUpdateParser.php';
require $base . 'OwnerActivityScorer.php';
require $base . 'ActivitySource.php';

use App\Services\Bot\Offers\ActivitySource as SRC;
use App\Services\Bot\Offers\OwnerActivityScorer as SC;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

/* ------------------------------------------------------- source classify */
ok('status image -> owner_status',   SRC::classify(true, false, true) === SRC::STATUS);
ok('forwarded image -> owner_forward', SRC::classify(false, true, true) === SRC::FORWARD);
ok('direct image -> owner_image',    SRC::classify(false, false, true) === SRC::IMAGE);
ok('plain text -> owner_message',    SRC::classify(false, false, false) === SRC::MESSAGE);
ok('forwarded text -> owner_message', SRC::classify(false, true, false) === SRC::MESSAGE);
ok('status beats forward',           SRC::classify(true, true, true) === SRC::STATUS);

/* ------------------------------ image OCR text -> business-state events --- */
// (offer-vs-state: a menu poster yields no state event; offer extraction handles it)
$r = SC::score('Fresh Jalebi Ready');
ok('poster "Fresh Jalebi Ready" -> available', $r['event'] === 'available' && $r['confidence'] >= 60);
$r = SC::score('Only 5 Thali Left');
ok('poster "Only 5 Thali Left" -> low_stock/5', $r['event'] === 'low_stock' && $r['qty'] === 5 && $r['confidence'] >= 60);
$r = SC::score('Fafda Sold Out');
ok('poster "Fafda Sold Out" -> sold_out', $r['event'] === 'sold_out' && $r['confidence'] >= 60);
$r = SC::score("Today's Lunch Menu");
ok('poster "Today\'s Lunch Menu" -> no state event (offer path)', $r['event'] === null);

echo "\n=== activity_feed: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
