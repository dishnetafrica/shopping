<?php
// Pure test: ProductMiss::term() — when does the bot announce "we don't stock X" vs stay quiet.
require __DIR__ . '/../app/Services/Bot/ProductMiss.php';

use App\Services\Bot\ProductMiss;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; printf("FAIL  %-44s got=%s want=%s\n", $label, var_export($got, true), var_export($want, true)); }
}

// --- Social / greeting / emoji => null (never echoed as a product) ---
check('the reported bug',      ProductMiss::term('Congratulations 👏👏'), null);
check('emoji only',            ProductMiss::term('👏👏'),                  null);
check('party emoji',           ProductMiss::term('🎉🎉🎉'),                null);
check('hi',                    ProductMiss::term('hi'),                    null);
check('hello there',           ProductMiss::term('hello there'),           null);
check('thank you',             ProductMiss::term('thank you'),             null);
check('good morning',          ProductMiss::term('Good morning'),          null);
check('happy birthday',        ProductMiss::term('happy birthday'),        null);
check('eid mubarak',           ProductMiss::term('Eid Mubarak'),           null);
check('gujlish kem cho',       ProductMiss::term('kem cho'),               null);
check('gujlish majama',        ProductMiss::term('majama'),                null);
check('gujlish saras',         ProductMiss::term('saras'),                 null);
check('ok noted',              ProductMiss::term('ok noted'),              null);
check('nice photo',            ProductMiss::term('nice photo'),            null);
check('question delivery',     ProductMiss::term('will you deliver tomorrow'), null);
check('sentence too long',     ProductMiss::term('please send me the address of your shop'), null);

// --- Genuine product misses => return the cleaned term ---
check('single product biryani', ProductMiss::term('biryani'),             'biryani');
check('two-word product',       ProductMiss::term('samosa chaat'),        'samosa chaat');
check('lead-in stripped',       ProductMiss::term('do you have paneer'),  'paneer');
check('looking for kaju',       ProductMiss::term('looking for kaju'),    'kaju');
check('punctuation stripped',   ProductMiss::term('badam?'),              'badam');

if ($fail === 0) {
    echo "\nALL GREEN: {$pass} passed, 0 failed.\n";
} else {
    echo "\n{$pass} passed, {$fail} FAILED.\n";
    exit(1);
}
