<?php
// Phase-1 regression: the Intent Router must keep every audit fallback example OUT of the
// product-search path (the only "we don't stock" path) unless it is a genuine product.
require __DIR__ . '/../app/Services/Bot/ProductAlias.php';
require __DIR__ . '/../app/Services/Bot/GujlishDictionary.php';
require __DIR__ . '/../app/Services/Bot/OrderIntentRouter.php';

use App\Services\Bot\OrderIntentRouter as R;
use App\Services\Bot\ProductAlias;

$pass = 0; $fail = 0;
function ck($label, $got, $want) {
    global $pass, $fail;
    $ok = is_array($want) ? in_array($got, $want, true) : ($got === $want);
    if ($ok) { $pass++; }
    else { $fail++; printf("FAIL  %-46s got=%-14s want=%s\n", $label, $got, is_array($want)?implode('|',$want):$want); }
}
function intent($t){ return R::classify($t)['intent']; }
function prod($t){ return R::classify($t)['product']; }

// ---------- A. Every fallback-triggering message from the audit ----------
// Each MUST classify to a non-fallback intent (or product_search for a real product).
$NONPRODUCT = [R::HUMAN,R::CONFIRM,R::REMOVAL,R::ADDITION,R::QUANTITY,R::PRICE,R::DELIVERY,R::MENU,R::GREETING,R::SOCIAL,R::UNKNOWN];

$audit = [
  ['Heloo',                         R::GREETING],
  ['Hlo bhabi',                     [R::GREETING,R::SOCIAL]],
  ['Kaise ho',                      R::GREETING],
  ['Jai Swaminarayan🙏',            R::GREETING],
  ['Hello Will you be coming tomorrow', R::DELIVERY],
  ['Nathi lavanu',                  R::REMOVAL],
  ['Bhaiya abhi group mese number nikal do', R::HUMAN],
  ['I need 5 pcs',                  R::QUANTITY],
  ['I want 500 gm',                 R::QUANTITY],
  ['200gram',                       R::QUANTITY],
  ['1 Dish',                        R::QUANTITY],
  ['9 Dish',                        R::QUANTITY],
  ['2 packets 1 kg',                R::QUANTITY],
  ['We need 3kgs',                  R::QUANTITY],
  ['Hi Need cheese 1kg',            R::QUANTITY],
  ['20kg mavo 15kg panir',          R::QUANTITY],
  ['Good morning You please add 1/2 kg lato ghee also', R::ADDITION],
  ['Hello I want गुलाब jamun 5pcs Kala jamun 5pcs And jalebi 5pcs', R::QUANTITY],
  ['I want give order',             R::CONFIRM],
  ['I made my list',                R::CONFIRM],
  ['Please take my order',          R::CONFIRM],
  ['Please confirm',                R::CONFIRM],
  ['Please bring khakra also',      R::ADDITION],
  ['Jab aavu tab add karna',        R::ADDITION],
  ['Amount',                        R::PRICE],
  ['Price',                         R::PRICE],
  ['Samosha have',                  [R::PRODUCT_SEARCH,R::DELIVERY]],
  ['Hello Do you have dhokla flour ?', [R::PRODUCT_SEARCH,R::DELIVERY,R::MENU]],
  ['Hello ! Aje bapore 2 tiffin joiye che. Maro boda vali leva avse', [R::DELIVERY,R::MENU,R::QUANTITY]],
  ['Manue',                         R::MENU],
  ['Bhabhi',                        R::SOCIAL],
  ['Sorry for delay',               R::SOCIAL],
  ['Aa tamaru chhe',                R::SOCIAL],
  ['Bhej diya apne?',               R::DELIVERY],
  ['One',                           R::QUANTITY],
  ['Please send me lunch  At Kikubo', [R::DELIVERY,R::MENU,R::UNKNOWN]],
  ['Clove Cinnamon  Cardamom Ani stars Biryani leaves', [R::PRODUCT_SEARCH,R::UNKNOWN]],
  ['Fuel your day with flavor for royalty, delicious all way through,silver springs', [R::UNKNOWN,R::PRODUCT_SEARCH]],
  ['https://mycloudbss.com/pals',   R::UNKNOWN],
  // real products the matcher fumbled — must reach product_search (alias-resolved)
  ['I want gulab jamuns',           R::PRODUCT_SEARCH],
  ['Aadu',                          R::PRODUCT_SEARCH],
  ['Samosa',                        R::PRODUCT_SEARCH],
  ['Salted pista',                  R::PRODUCT_SEARCH],
  ['Bateta Tameta',                 R::PRODUCT_SEARCH],
  ['Cloves Canisters',              R::PRODUCT_SEARCH],
];
echo "=== A. Audit fallback examples ===\n";
foreach ($audit as [$msg, $want]) ck(mb_substr($msg,0,42), intent($msg), $want);

// ---------- B. Required phrase support ----------
echo "=== B. Required phrases ===\n";
ck('GUJ Nathi lavanu',  intent('Nathi lavanu'),  R::REMOVAL);
ck('GUJ Kem cho',       intent('Kem cho'),        R::GREETING);
ck('GUJ Aa tamaru che', intent('Aa tamaru che'),  R::SOCIAL);
ck('GUJ Aadu',          intent('Aadu'),           R::PRODUCT_SEARCH);
ck('GUJ Bhabhi',        intent('Bhabhi'),         R::SOCIAL);
ck('HIN Kaise ho',      intent('Kaise ho'),       R::GREETING);
ck('HIN Add karna',     intent('Add karna'),      R::ADDITION);
ck('HIN Bhaiya',        intent('Bhaiya'),         R::SOCIAL);
ck('ENG Bring also',    intent('Bring sev also'), R::ADDITION);
ck('ENG Remove',        intent('Remove sev'),     R::REMOVAL);
ck('ENG Confirm',       intent('Confirm'),        R::CONFIRM);

// ---------- C. Multilingual aliases ----------
echo "=== C. Aliases ===\n";
ck('alias gulab jamun',  ProductAlias::canonical('gulab jamuns'), 'gulab jamun');
ck('alias aadu->ginger', ProductAlias::canonical('aadu'),         'ginger');
ck('alias samosha',      ProductAlias::canonical('samosha'),      'samosa');
ck('alias bateta',       ProductAlias::canonical('bateta'),       'potato');
ck('alias tameta',       ProductAlias::canonical('tameta'),       'tomato');
ck('alias panir',        ProductAlias::canonical('panir'),        'paneer');
ck('alias khakra',       ProductAlias::canonical('khakra'),       'khakhra');
ck('alias dhokla',       ProductAlias::canonical('dokla'),        'dhokla');
ck('product slot aadu',  prod('Aadu'),                            'ginger');
ck('product slot khakra',prod('Please bring khakra also'),        'khakhra');

// ---------- D. Guards: real orders still flow to product search / engine ----------
echo "=== D. Real orders unaffected ===\n";
ck('rice search',        intent('rice'),           R::PRODUCT_SEARCH);
ck('sev product',        intent('sev'),            R::PRODUCT_SEARCH);
ck('checkout',           intent('checkout'),       [R::CONFIRM,R::PRODUCT_SEARCH]);
ck('nice paneer order',  intent('nice paneer'),    R::PRODUCT_SEARCH);  // social word must not starve a product

// ---------- Summary + fallback projection ----------
$fallbackable = 0;
foreach ($audit as [$msg, $want]) { if (intent($msg) === R::PRODUCT_SEARCH) $fallbackable++; }
echo "\n--- Of 46 audit messages, still routed to product_search (catalogue/alias resolves these): {$fallbackable} ---\n";

if ($fail === 0) echo "\nALL GREEN: {$pass} passed, 0 failed.\n";
else { echo "\n{$pass} passed, {$fail} FAILED.\n"; exit(1); }
