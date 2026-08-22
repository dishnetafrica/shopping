<?php
/**
 * Pure harness for the product-card caption + category-link picker (no Laravel).
 *   - ProductImageResponder::richCaption — the "name / size · pack / price · stock" WhatsApp card.
 *   - AiBrain::pickCategoryLink — which live category to hand off to the website.
 *
 * Run:  php tests/bot_cards_and_links_harness.php
 */

require __DIR__ . '/../app/Services/Bot/ProductImageResponder.php';
require __DIR__ . '/../app/Services/Bot/AiBrain.php';
use App\Services\Bot\ProductImageResponder;
use App\Services\Bot\AiBrain;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (! $ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (! $ok) { echo "   got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n   want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}

// ---- richCaption ----
check('full card',
    ProductImageResponder::richCaption('Aluminum Foil Container 8389E', '215 × 115 × 48 mm', 1000, 'carton', 61000, 5, 'UGX'),
    "Aluminum Foil Container 8389E\n215 × 115 × 48 mm · Carton of 1000\nUGX 61,000 · In stock");
check('price only, stock untracked (null) → In stock',
    ProductImageResponder::richCaption('Golden Foil', '', 0, '', 80000, null, 'UGX'),
    "Golden Foil\nUGX 80,000 · In stock");
check('explicit zero stock → no In stock claim',
    ProductImageResponder::richCaption('Golden Foil', '', 0, '', 80000, 0, 'UGX'),
    "Golden Foil\nUGX 80,000");
check('no price → omit price',
    ProductImageResponder::richCaption('Small Tray', '10cm', 6, 'box', 0, 3, 'UGX'),
    "Small Tray\n10cm · Box of 6\nIn stock");

// ---- pickCategoryLink ----
$counts = ['Aluminium Foil' => 8, 'Cling Film' => 1, 'Containers' => 4];
check('named category with >1 product',
    AiBrain::pickCategoryLink($counts, 'do you have aluminium foil? pictures please'),
    ['category' => 'Aluminium Foil', 'count' => 8]);
check('single-product category not linked',
    AiBrain::pickCategoryLink($counts, 'show me cling film'),
    null);
check('unnamed category → null',
    AiBrain::pickCategoryLink($counts, 'i want rice'),
    null);
check('prefers the bigger of two named categories',
    AiBrain::pickCategoryLink(['Foil' => 3, 'Aluminium Foil' => 8], 'looking for aluminium foil'),
    ['category' => 'Aluminium Foil', 'count' => 8]);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
