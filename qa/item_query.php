<?php
/**
 * Framework-free QA for Status Intelligence v12 — menu/item awareness.
 * Run: php qa/item_query.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$base = __DIR__ . '/../app/Services/Bot/Offers/';
require $base . 'ItemAliases.php';
require $base . 'ItemQueryParser.php';
require $base . 'OfferItemMatcher.php';
require $base . 'PriceQueryParser.php';
require $base . 'OfferTypeClassifier.php';
require $base . 'OfferRules.php';

use App\Services\Bot\Offers\ItemAliases as A;
use App\Services\Bot\Offers\ItemQueryParser as P;
use App\Services\Bot\Offers\OfferItemMatcher as M;
use App\Services\Bot\Offers\PriceQueryParser as PR;
use App\Services\Bot\Offers\OfferRules as R;

$pass = 0; $fail = 0;
function ok(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; }
    else { $fail++; echo "  FAIL: $label\n"; }
}
function eq(string $label, $got, $want): void {
    ok($label . "  (got " . json_encode($got) . ")", $got === $want);
}

/* ---------------------------------------------------------- ItemAliases */
eq('chaas -> chaas',              A::concepts('chaas'), ['chaas']);
eq('Chaas -> chaas',              A::concepts('Chaas'), ['chaas']);
eq('buttermilk -> chaas',         A::concepts('buttermilk'), ['chaas']);
eq('chhash -> chaas',             A::concepts('chhash'), ['chaas']);
eq('roti -> chapati',             A::concepts('roti'), ['chapati']);
eq('5 Chapati drops count',       A::concepts('5 Chapati'), ['chapati']);
eq('Dal Rice -> dal,rice',        A::concepts('Dal Rice'), ['dal', 'rice']);
eq('dal chawal -> dal,rice',      A::concepts('dal chawal'), ['dal', 'rice']);
eq('Dhokli Nu Shak drops nu',     A::concepts('Dhokli Nu Shak'), ['dhokli', 'sabji']);
eq('Mag Sabji',                   A::concepts('Mag Sabji'), ['mag', 'sabji']);
eq('tameta sev',                  A::concepts('tameta sev'), ['tameta', 'sev']);

ok('isKnownFood chaas',           A::isKnownFood('chaas'));
ok('isKnownFood tameta sev',      A::isKnownFood('tameta sev'));
ok('isKnownFood roti',            A::isKnownFood('roti'));
ok('kem NOT food',                ! A::isKnownFood('kem'));
ok('hello NOT food',              ! A::isKnownFood('hello'));

/* ------------------------------------------------------ ItemQueryParser */
function p(string $s) { return App\Services\Bot\Offers\ItemQueryParser::detect($s); }

eq('chaas che -> presence/chaas',        p('Chaas che?'),       ['type' => 'presence', 'item' => 'chaas']);
eq('chaas male -> presence/chaas',       p('Chaas male?'),      ['type' => 'presence', 'item' => 'chaas']);
eq('tameta sev che',                     p('Tameta Sev che?'),  ['type' => 'presence', 'item' => 'tameta sev']);
eq('rice included che',                  p('Rice included che?'),['type' => 'presence', 'item' => 'rice']);
eq('chapati ketli -> count',             p('Chapati ketli?'),   ['type' => 'count', 'item' => 'chapati']);
eq('chapati ketla -> count',             p('chapati ketla'),    ['type' => 'count', 'item' => 'chapati']);
eq('is there raita',                     p('is there raita?'),  ['type' => 'presence', 'item' => 'raita']);
eq('is rice included',                   p('is rice included'), ['type' => 'presence', 'item' => 'rice']);
eq('how many chapati',                   p('how many chapati'), ['type' => 'count', 'item' => 'chapati']);

ok('su che -> null (whole menu)',        p('su che?') === null);
ok('aaje su che -> null',                p('aaje su che?') === null);
ok('thali che -> null (menu word)',      p('thali che?') === null);
ok('special che -> null',                p('su special che?') === null);
ok('plain product (no q-word) -> null',  p('jalebi') === null);
ok('greeting kem che -> item kem',       (p('kem che?') ?? [])['item'] === 'kem');  // parsed, rejected later by food check

/* ------------------------------------------------------ OfferItemMatcher */
$items = ['Dhokli Nu Shak', 'Mag Sabji', '5 Chapati', 'Dal Rice', 'Papad', 'Salad', 'Chaas'];

$mC = M::find('chaas', $items);
ok('chaas matches',                      $mC !== null && $mC['display'] === 'Chaas');
$mR = M::find('rice', $items);
ok('rice matches Dal Rice',              $mR !== null && $mR['display'] === 'Dal Rice');
$mD = M::find('dal', $items);
ok('dal matches Dal Rice',               $mD !== null && $mD['display'] === 'Dal Rice');
$mCh = M::find('chapati', $items);
ok('chapati matches + count 5',          $mCh !== null && $mCh['count'] === 5 && $mCh['display'] === '5 Chapati');
$mRoti = M::find('roti', $items);
ok('roti (alias) matches 5 Chapati',     $mRoti !== null && $mRoti['count'] === 5);
$mBm = M::find('buttermilk', $items);
ok('buttermilk (alias) matches Chaas',   $mBm !== null && $mBm['display'] === 'Chaas');
$mDc = M::find('dal chawal', $items);
ok('dal chawal matches Dal Rice',        $mDc !== null && $mDc['display'] === 'Dal Rice');
ok('tameta sev NOT in menu',             M::find('tameta sev', $items) === null);
ok('jalebi NOT in menu',                 M::find('jalebi', $items) === null);
ok('paneer NOT in menu',                 M::find('paneer', $items) === null);

/* --------------------------------------------- v13: price query detection */
ok('ketla ni che -> price',              PR::detect('Ketla ni che?'));
ok('ketla na -> price',                  PR::detect('ketla na?'));
ok('kitla nu -> price',                  PR::detect('kitla nu'));
ok('how much -> price',                  PR::detect('how much?'));
ok('price -> price',                     PR::detect('price?'));
ok('bhav -> price',                      PR::detect('su bhav che'));
ok('rate -> price',                      PR::detect('rate ketlo'));
ok('chaas che NOT price',                ! PR::detect('chaas che?'));
ok('chapati ketli NOT price',            ! PR::detect('chapati ketli?'));   // how-MANY, handled as item
ok('plain hi NOT price',                 ! PR::detect('hello'));

// "ketla ni che" must NOT parse as an item (so it cleanly reaches the price path)
ok('ketla ni che -> not an item',        p('ketla ni che?') === null);
ok('ketla na -> not an item',            p('ketla na?') === null);

/* ----------------------------------------- v13: conversation context pick */
$o7  = ['id' => 7, 'title' => 'Kathiyawadi Thali'];
$o9  = ['id' => 9, 'title' => 'Weekend Special'];
$set = [$o7, $o9];
eq('pin 9 active -> picks 9',            R::pickContext($set, 9)['id'], 9);
eq('pin 7 -> picks 7',                   R::pickContext($set, 7)['id'], 7);
eq('pin stale/absent -> top active',     R::pickContext($set, 999)['id'], 7);
eq('no pin -> top active',               R::pickContext($set, null)['id'], 7);
ok('empty set -> null',                  R::pickContext([], 7) === null);

echo "\n=== item_query: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
