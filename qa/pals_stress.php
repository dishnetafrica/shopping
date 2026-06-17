<?php
/**
 * Real-world stress test for the catalogue brain against a Pal's-style shop
 * (dry fruits + snacks + grocery + drinks). Pure logic — CatalogueMatcher + ThaliMenu only.
 * Mirrors the kinds of messages real Gujarati/English customers send.
 */
require __DIR__ . '/../app/Services/Bot/CatalogueMatcher.php';
require __DIR__ . '/../app/Services/Bot/ThaliMenu.php';

use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ThaliMenu;

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass,$fail; if($c)$pass++; else {$fail++; echo "  FAIL: $l\n";} }

$m = new CatalogueMatcher();

$cat = [
    // Dry Fruits
    ['name'=>'Cashew 250g','category'=>'Dry Fruits'],
    ['name'=>'Almond 250g','category'=>'Dry Fruits'],
    ['name'=>'Dates 500g','category'=>'Dry Fruits'],
    ['name'=>'Raisin 250g','category'=>'Dry Fruits'],
    ['name'=>'Walnut 200g','category'=>'Dry Fruits'],
    ['name'=>'Fig Anjeer 200g','category'=>'Dry Fruits'],
    ['name'=>'Pistachio 200g','category'=>'Dry Fruits'],
    // Snacks
    ['name'=>'Banana Wafer 100g','category'=>'Snacks'],
    ['name'=>'Aloo Sev 200g','category'=>'Snacks'],
    ['name'=>'Gathiya 250g','category'=>'Snacks'],
    ['name'=>'Chevdo 200g','category'=>'Snacks'],
    // Grocery
    ['name'=>'Basmati Rice 5kg','category'=>'Grocery'],
    ['name'=>'Sugar 1kg','category'=>'Grocery'],
    ['name'=>'Toor Dal 1kg','category'=>'Grocery'],
    ['name'=>'Cooking Oil 1L','category'=>'Grocery'],
    ['name'=>'Tea 250g','category'=>'Grocery'],
    // Beverages
    ['name'=>'Coca Cola 500ml','category'=>'Beverages'],
    ['name'=>'Mango Juice 1L','category'=>'Beverages'],
];

function top(CatalogueMatcher $m, array $c, string $q): string {
    $r = $m->search($q, $c); return $r[0]['product']['name'] ?? '(none)';
}
function cat(CatalogueMatcher $m, array $c, string $q): string {
    $r = $m->categoryByName($q, $c); return $r ? $r['category'] : '(none)';
}

echo "Pal's real-world stress test\n\n";

// ---- 1) Gujarati/Hindi product names -> right product ----
$g = [
    ['kaju','Cashew 250g'],['kajoo','Cashew 250g'],['badam','Almond 250g'],['baadam','Almond 250g'],
    ['khajur','Dates 500g'],['khajoor','Dates 500g'],['kharek','Dates 500g'],
    ['draksh','Raisin 250g'],['kismis','Raisin 250g'],['kishmish','Raisin 250g'],
    ['akhrot','Walnut 200g'],['anjeer','Fig Anjeer 200g'],['anjir','Fig Anjeer 200g'],['pista','Pistachio 200g'],
];
foreach ($g as [$q,$e]) ok(top($m,$cat=$cat,$q)===$e, "guj \"$q\" -> $e (got ".top($m,$cat,$q).')');

// ---- 2) English product names ----
foreach ([['cashew','Cashew 250g'],['almond','Almond 250g'],['dates','Dates 500g'],
          ['walnut','Walnut 200g'],['rice','Basmati Rice 5kg'],['sugar','Sugar 1kg'],
          ['cooking oil','Cooking Oil 1L'],['tea','Tea 250g'],['coca cola','Coca Cola 500ml']] as [$q,$e]) {
    ok(top($m,$cat,$q)===$e, "eng \"$q\" -> $e (got ".top($m,$cat,$q).')');
}

// ---- 3) quantities & phrases (should still resolve the product) ----
foreach ([['2 kg kaju','Cashew 250g'],['500g badam','Almond 250g'],['5 kaju','Cashew 250g'],
          ['mane kaju joiye','Cashew 250g'],['do you have badam','Almond 250g'],
          ['i want 2 dates','Dates 500g'],['need sugar 1kg','Sugar 1kg']] as [$q,$e]) {
    ok(top($m,$cat,$q)===$e, "phrase \"$q\" -> $e (got ".top($m,$cat,$q).')');
}

// ---- 4) typos (Damerau) ----
foreach ([['cashw','Cashew 250g'],['kajU','Cashew 250g'],['amond','Almond 250g'],
          ['walnt','Walnut 200g'],['sugr','Sugar 1kg']] as [$q,$e]) {
    ok(top($m,$cat,$q)===$e, "typo \"$q\" -> $e (got ".top($m,$cat,$q).')');
}

// ---- 5) category-name browse ----
foreach ([['dry fruits','Dry Fruits'],['dryfruits','Dry Fruits'],['dry fruit','Dry Fruits'],
          ['u have dryfruits','Dry Fruits'],['mewa','Dry Fruits'],['meva','Dry Fruits'],['nuts','Dry Fruits'],
          ['snacks','Snacks'],['snack','Snacks'],['grocery','Grocery'],['beverages','Beverages'],['drinks','Beverages']] as [$q,$e]) {
    ok(cat($m,$cat,$q)===$e, "cat \"$q\" -> $e (got ".cat($m,$cat,$q).')');
}
// dry fruits category returns all 7 items
$r = $m->categoryByName('dryfruits',$cat);
ok($r && count($r['products'])===7, 'dryfruits lists 7 items (got '.($r?count($r['products']):'null').')');
$r = $m->categoryByName('snacks',$cat);
ok($r && count($r['products'])===4, 'snacks lists 4 items (got '.($r?count($r['products']):'null').')');

// ---- 6) non-products must NOT resolve to a category ----
foreach (['confirm','please confirm','9 dish','ok','thanks','today i will send money',
          'i paid','my balance','masala','hello'] as $q) {
    ok($m->categoryByName($q,$cat)===null, "non-category \"$q\" -> null (got ".cat($m,$cat,$q).')');
}
// product words must NOT be treated as a category (leave to product search)
ok($m->categoryByName('rice',$cat)===null, 'rice is product not category');
ok($m->categoryByName('cashew',$cat)===null, 'cashew is product not category');

// ---- 7) payment-intent regex (mirrors BotBrain::looksLikePayment) ----
function pay(string $lc): bool {
    if (preg_match('/\b(i ?\'?ll|i will|today i will|i am going to|i\'?m going to|gonna)\s+(pay|send|deposit|transfer)\b/',$lc)) return true;
    if (preg_match('/\b(paid|sent the money|sent money|paying|sending money|transferred|deposited)\b/',$lc)) return true;
    if (preg_match('/\b(send|sending|sent|transfer(red|ring)?|deposit(ed|ing)?)\b[^.]*\b(money|cash|payment|amount|balance)\b/',$lc)) return true;
    if (preg_match('/\b(my )?(payment|balance|khata|khaata|udhaar|udhar|hisab|hisaab|baki|baaki|dues?|outstanding)\b/',$lc)) return true;
    if (preg_match('/\bmoney for (last|the)\b/',$lc)) return true;
    if (preg_match('/\blast (week|month)\b[^.]*\b(money|pay|paid|payment|bill|balance|due)\b/',$lc)) return true;
    if (preg_match('/\b(momo|mobile money|airtel money|mtn money)\b/',$lc)) return true;
    return false;
}
foreach (['today i will send money for last week','i will pay tomorrow','i paid yesterday',
          'i sent the money','my balance','khata clear','payment for last week','sending money now',
          'i will send money via momo'] as $q) ok(pay($q), "pay YES \"$q\"");
foreach (['how do i pay','do you have rice','2 kaju','menu','i want sugar','can you deliver today',
          'pay on delivery available','dry fruits'] as $q) ok(!pay($q), "pay no  \"$q\"");

// ---- 8) status-reply reconstruction -> menu query ----
foreach (['Today special lunch menu 1 Dish','todays menu','whats for lunch today special'] as $q)
    ok(ThaliMenu::isMenuQuery(strtolower($q)), "status/menu query YES \"$q\"");
ok(!ThaliMenu::isMenuQuery('kaju'), 'kaju is not a menu query');

// ---- 9) thali day/night + next-day rollover sanity ----
$A = ['enabled'=>true,'night_enabled'=>true,'switch_hour'=>16,'nextday_enabled'=>true,'nextday_hour'=>21,
      'days'=>['mon'=>['Dal','Rice'],'tue'=>['Kadhi','Rice']],
      'night_days'=>['mon'=>['Roti','Sabzi']]];
$e = ThaliMenu::effectiveForHour($A,'mon',9);  ok($e['day']==='mon'&&$e['session']==='day'&&!$e['rollover'],'thali mon 9 lunch');
$e = ThaliMenu::effectiveForHour($A,'mon',18); ok($e['day']==='mon'&&$e['session']==='night'&&!$e['rollover'],'thali mon 18 dinner');
$e = ThaliMenu::effectiveForHour($A,'mon',22); ok($e['day']==='tue'&&$e['session']==='day'&&$e['rollover'],'thali mon 22 -> tue lunch rollover');

// ---- 10) social / coordination chit-chat (mirrors BotBrain::socialReply) ----
function social(string $lc): string {
    $lc=trim($lc);
    if (preg_match('/\b(on my way|omw|i.?m coming|i am coming|coming now|let me come|i.?ll come|i will come|i.?m reaching|i am reaching|reaching|on the way|i.?ll be there|be there soon|coming over|just coming)\b/',$lc) || in_array($lc,['ok coming','ok i come','let me come','coming','i come'],true)) return 'coming';
    if (preg_match('/\b(see you|see u|c u|catch you|talk later|bye|good night|goodnight)\b/',$lc)) return 'bye';
    if (preg_match('/\b(good (morning|afternoon|evening)|gud (morning|mrng)|shubh|kem cho|kemcho|namaste|salaam|salam)\b/',$lc)) return 'greet';
    if (preg_match('/\b(how are you|how r u|how are u|hw r u|kaise ho|kem cho|how is it going)\b/',$lc)) return 'howru';
    return '';
}
foreach ([['ok let me come','coming'],['i am coming','coming'],['on my way','coming'],['omw','coming'],
          ['see you','bye'],['good morning','greet'],['kem cho','greet'],['how are you','howru']] as [$q,$e2]) {
    ok(social($q)===$e2, "social \"$q\" -> $e2 (got '".social($q)."')");
}
// real orders must NOT be treated as social
foreach (['do you have rice','2 kaju','menu','i want sugar','dry fruits','cashew'] as $q) {
    ok(social($q)==='', "not-social \"$q\"");
}

// ---- 11) Gujlish non-product intents (mirrors BotBrain::gujlishReply) ----
function guj(string $lc): string {
    if (preg_match('/\b(nathi|nthi|nai)\b[^.]*\b(levanu|levu|joiye|joitu|joi tu|lewu|joeye)\b/',$lc) || preg_match('/\b(aaje?|atyare|have|hamna|hamnaa)\s+(nahi|nathi|nai)\b/',$lc) || preg_match('/\b(pachi|paachi|baad ma|next time)\b/',$lc) || preg_match('/\bnathi joi/',$lc)) return 'decline';
    if (preg_match('/\b(kem cho|kemcho|kem chho|kaa cho|jai shree krishna|jai shri krishna|jsk|namaste|namaskar|ram ram|jai mataji)\b/',$lc)) return 'greet';
    if (preg_match('/\b(majama|maja ma|su chale|shu chale|kem chale|kem ave che)\b/',$lc)) return 'howru';
    if (preg_match('/^\s*(ha+|haan|saru|saaru|barabar|barobar|theek che|thik che|theek|thik|chalse|ok che|bahu saru)\s*[!.]*$/',$lc)) return 'affirm';
    if (preg_match('/\b(aabhar|abhar|dhanyavaad|dhanyavad|dhanyvad)\b/',$lc)) return 'thanks';
    return '';
}
foreach ([['aaj vakhte nathi levanu next time','decline'],['nathi joiye','decline'],['aaje nahi','decline'],
          ['pachi','decline'],['kem cho','greet'],['jsk','greet'],['majama','howru'],
          ['ha','affirm'],['saru','affirm'],['barabar','affirm'],['aabhar','thanks'],['dhanyavaad','thanks']] as [$q,$e3]) {
    ok(guj($q)===$e3, "gujlish \"$q\" -> $e3 (got '".guj($q)."')");
}
// Gujlish PRODUCT words must fall through (handled by synonyms), not caught as chit-chat
foreach (['kaju joiye','mane badam joiye','2 kaju','khajur','dry fruits','menu'] as $q) {
    ok(guj($q)==='', "gujlish-product falls through \"$q\"");
}

echo "\n==== Pal's stress test: PASS {$pass}  FAIL {$fail} ====\n";
exit($fail>0?1:0);
