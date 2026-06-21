<?php
/**
 * qa/daily_offers.php — pure-logic QA for the Status Intelligence engine.
 * No framework: requires the five pure classes directly. Run: php qa/daily_offers.php
 */

$base = __DIR__ . '/../app/Services/Bot/Offers/';
require $base . 'OfferTypeClassifier.php';
require $base . 'OfferQueryMatcher.php';
require $base . 'OfferRules.php';
require $base . 'OfferExtractor.php';
require $base . 'OfferFormatter.php';
require $base . 'StatusIngestGate.php';

use App\Services\Bot\Offers\OfferTypeClassifier as TC;
use App\Services\Bot\Offers\OfferQueryMatcher as QM;
use App\Services\Bot\Offers\OfferRules as R;
use App\Services\Bot\Offers\OfferExtractor as EX;
use App\Services\Bot\Offers\OfferFormatter as FMT;
use App\Services\Bot\Offers\StatusIngestGate as SG;

$pass = 0; $fail = 0;
function t($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    if ($ok) { $pass++; }
    else { $fail++; echo "  FAIL  $label  got=" . var_export($got, true) . " want=" . var_export($want, true) . "\n"; }
}
function ok($label, $cond) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "  FAIL  $label\n"; } }

/* ---------------------------------------------------------------- classifier */
t('thali->daily_thali',        TC::classify('Monday Lunch Menu Kathiyawadi Thali'), TC::DAILY_THALI);
t('weekend->weekend',          TC::classify('Weekend Special Chole Bhature'), TC::WEEKEND);
t('weekend+thali->thali',      TC::classify('Weekend Gujarati Thali'), TC::DAILY_THALI);
t('diwali->festival',          TC::classify('Diwali Special Sweets Box'), TC::FESTIVAL);
t('fresh today->fresh',        TC::classify('Fresh Today Hot Jalebi'), TC::FRESH);
t('garam garam->fresh',        TC::classify('Garam garam fafda jalebi'), TC::FRESH);
t('discount->special',         TC::classify('20% off on all namkeen'), TC::SPECIAL);
t('meal staples->thali',       TC::classify('Chapati Dal Rice Sabji Papad Chaas'), TC::DAILY_THALI);
t('plain offer->special',      TC::classify('Buy 1 Get 1'), TC::SPECIAL);

/* ------------------------------------------------------------- query matcher */
t('todays thali',  QM::detect("Today's thali")['kind'] ?? null, 'thali');
t('kathiyawadi',   QM::detect('Kathiyawadi thali')['kind'] ?? null, 'thali');
t('lunch menu',    QM::detect('Lunch menu')['kind'] ?? null, 'menu');
t('lunch today',   QM::detect('Lunch today')['kind'] ?? null, 'today');
t('su special che',QM::detect('Su special che')['kind'] ?? null, 'special');
t('aaje su che',   QM::detect('Aaje su che')['kind'] ?? null, 'menu');
t('whats special', QM::detect("what's special")['kind'] ?? null, 'special');
t('fresh today q', QM::detect('fresh today?')['kind'] ?? null, 'fresh');
t('non-offer rice',QM::detect('2 kg rice'), null);
t('non-offer hi',  QM::detect('hi'), null);

t('kind thali prefers daily', QM::typesForKind('thali')[0], TC::DAILY_THALI);
t('kind fresh prefers fresh',  QM::typesForKind('fresh')[0], TC::FRESH);

/* ------------------------------------------------------------- extractor (text) */
$ocr = 'Monday Lunch Menu Kathiyawadi Thali 15,000 UGX Dhokli Nu Shak Mag Sabji 5 Chapati Dal Rice Papad Salad Chaas';
$e = EX::fromText($ocr);
t('text price',    $e['price'], 15000);
t('text currency', $e['currency'], 'UGX');
t('text day',      $e['day'], 'monday');
t('text type',     $e['type'], TC::DAILY_THALI);
ok('text title has Kathiyawadi Thali', str_contains(mb_strtolower((string) $e['title']), 'kathiyawadi thali'));
ok('text found',   $e['found'] === true);

t('price 15,000 UGX',  EX::priceFrom('15,000 UGX'), 15000);
t('price UGX 7500',    EX::priceFrom('UGX 7500'), 7500);
t('price 250/-',       EX::priceFrom('250/-'), 250);
t('price skips phone', EX::priceFrom('call 0751590810 only 15000'), 15000);
t('cur ushs',          EX::currencyFrom('only 15000 ushs'), 'UGX');

/* ---------------------------------------------------------- extractor (vision) */
$v = EX::fromVision([
    'found' => true, 'title' => 'Kathiyawadi Thali', 'price' => 15000, 'currency' => 'UGX',
    'items' => ['Dhokli Nu Shak', 'Mag Sabji', '5 Chapati', 'Dal', 'Rice', 'Papad', 'Salad', 'Chaas'],
    'day' => 'Monday', 'offer_type' => 'daily_thali', 'confidence' => 92,
]);
t('vision title', $v['title'], 'Kathiyawadi Thali');
t('vision price', $v['price'], 15000);
t('vision type',  $v['type'], TC::DAILY_THALI);
t('vision day',   $v['day'], 'monday');
ok('vision items normalised', count($v['items']) >= 6 && in_array('Mag Sabji', $v['items'], true));
ok('vision keeps item count (5 Chapati)', in_array('5 Chapati', $v['items'], true));
ok('vision drops bare prices/units', ! in_array('UGX', $v['items'], true) && ! in_array('15000', $v['items'], true));

/* -------------------------------------------------------------------- rules */
$now = 1_000_000;
ok('active inside window',  R::isActiveAt(['is_active' => true, 'valid_from' => $now - 10, 'valid_until' => $now + 10], $now));
ok('inactive flag',         ! R::isActiveAt(['is_active' => false, 'valid_from' => null, 'valid_until' => null], $now));
ok('expired window',        ! R::isActiveAt(['is_active' => true, 'valid_from' => null, 'valid_until' => $now - 1], $now));
ok('not-yet window',        ! R::isActiveAt(['is_active' => true, 'valid_from' => $now + 1, 'valid_until' => null], $now));
ok('supersedes same type',  R::supersedes(TC::DAILY_THALI, TC::DAILY_THALI));
ok('keeps other type',      ! R::supersedes(TC::DAILY_THALI, TC::SPECIAL));

$offers = [
    ['type' => TC::SPECIAL,     'is_active' => true, 'valid_from' => $now - 5, 'valid_until' => $now + 100],
    ['type' => TC::DAILY_THALI, 'is_active' => true, 'valid_from' => $now - 5, 'valid_until' => $now + 100],
    ['type' => TC::FRESH,       'is_active' => true, 'valid_from' => $now - 5, 'valid_until' => $now + 100],
    ['type' => TC::SPECIAL,     'is_active' => false, 'valid_from' => null,     'valid_until' => null],
];
$sorted = R::activeSorted($offers, $now);
t('active count (3 live)',  count($sorted), 3);
t('thali ranked first',     $sorted[0]['type'], TC::DAILY_THALI);
t('fresh ranked second',    $sorted[1]['type'], TC::FRESH);

$preferred = R::activeSorted($offers, $now, QM::typesForKind('special'));
t('special-kind first is special', $preferred[0]['type'], TC::SPECIAL);

t('window thali ends EOD', R::defaultWindow(TC::DAILY_THALI, $now, $now + 3600, $now + 7200), [$now, $now + 3600]);
t('window festival open',  R::defaultWindow(TC::FESTIVAL, $now, $now + 3600, $now + 7200), [$now, null]);

/* ---------------------------------------------------------------- formatter */
$card = FMT::card($v, 'UGX');
ok('card has title + price', str_contains($card, 'Kathiyawadi Thali') && str_contains($card, 'UGX 15,000'));
$reply = FMT::customerReply([$v + ['type' => TC::DAILY_THALI]], 'UGX');
ok('customer reply has thali intro', str_contains($reply, "today's thali") && str_contains($reply, 'menu'));
$conf = FMT::ownerConfirm($v + ['type' => TC::DAILY_THALI], 'UGX');
ok('owner confirm saved msg', str_contains($conf, 'Saved') && str_contains($conf, 'replace it'));

/* ----------------------------------------------------------- status gate */
ok('status+image passes',        SG::isStatusImage('status@broadcast', true));
ok('status+text dropped',        ! SG::isStatusImage('status@broadcast', false));
ok('normal+image not status',    ! SG::isStatusImage('256770000000@s.whatsapp.net', true));
ok('group dropped',              ! SG::isStatusImage('123-456@g.us', true));
t('status sender = participant', SG::senderNumber('status@broadcast', '256771234567@s.whatsapp.net'), '256771234567');
t('normal sender = remote',      SG::senderNumber('256770000000@s.whatsapp.net', ''), '256770000000');

echo "\n=== daily_offers: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
