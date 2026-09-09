<?php
/**
 * Pure harness for the technology storefront — no Laravel, no DB, runs in the sandbox:
 *
 *     php qa/technology_storefront.php
 *
 * Covers the two things that must never regress:
 *   1. STOREFRONT SELECTION — only a tenant explicitly on the technology vertical (or with
 *      technology_site=true) gets the new template; every existing vertical keeps its own.
 *   2. TENANT ISOLATION — TechTheme::build() reads nothing but the settings array it is
 *      handed, so one tenant's colours/copy/contacts can never appear on another's page.
 */

require __DIR__ . '/../app/Support/TechTheme.php';

// ---- minimal Vertical stand-in check: load the real class without Laravel -------------
// Vertical::of() calls $tenant->setting(), which our fake tenant implements.
require __DIR__ . '/../app/Support/Vertical.php';

use App\Support\TechTheme;
use App\Support\Vertical;

final class FakeTenant
{
    public function __construct(public array $settings = [], public string $name = 'Test Shop') {}

    public function setting(string $key, $default = null)
    {
        $cur = $this->settings;
        foreach (explode('.', $key) as $seg) {
            if (! is_array($cur) || ! array_key_exists($seg, $cur)) return $default;
            $cur = $cur[$seg];
        }
        return $cur;
    }
}

/**
 * Mirror of StorefrontController::technologyEnabled(). Kept in sync deliberately: this is
 * the decision the whole "existing tenants are safe" claim rests on, so it is asserted
 * here rather than only living inside a controller we cannot boot.
 */
function technologyEnabled(FakeTenant $t): bool
{
    $flag = $t->setting('technology_site', null);
    if ($flag !== null && $flag !== '') return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    return Vertical::of($t) === Vertical::TECHNOLOGY;
}

$pass = 0; $fail = 0;
function ok(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else       { $fail++; echo "  [FAIL] $label" . ($detail ? " — $detail" : '') . "\n"; }
}

echo "\n=== 1. Storefront selection ===\n";

// every shape of existing tenant must NOT get the technology storefront
$existing = [
    'legacy tenant, no settings at all'      => new FakeTenant([]),
    'grocery (explicit)'                     => new FakeTenant(['vertical' => 'grocery']),
    'restaurant (explicit)'                  => new FakeTenant(['vertical' => 'restaurant']),
    'restaurant (legacy restaurant_mode)'    => new FakeTenant(['restaurant_mode' => true]),
    'snacks (legacy feature_thali)'          => new FakeTenant(['feature_thali' => true]),
    'manufacturer / brand site'              => new FakeTenant(['vertical' => 'manufacturer']),
    'solar'                                  => new FakeTenant(['vertical' => 'solar']),
    'auto parts'                             => new FakeTenant(['vertical' => 'auto_parts']),
    'tenant with unrelated settings'         => new FakeTenant(['logo' => 'x.png', 'theme' => 'wholesale', 'loose_enabled' => true]),
    'tenant with a bogus vertical'           => new FakeTenant(['vertical' => 'not_a_real_vertical']),
];
foreach ($existing as $label => $t) {
    ok(technologyEnabled($t) === false, "NOT technology: $label");
}

// opt-in paths
ok(technologyEnabled(new FakeTenant(['vertical' => 'technology'])) === true, 'IS technology: vertical=technology');
ok(technologyEnabled(new FakeTenant(['technology_site' => true])) === true, 'IS technology: technology_site=true');
ok(technologyEnabled(new FakeTenant(['technology_site' => '1'])) === true, 'IS technology: technology_site="1"');
// explicit opt-OUT must beat the vertical, so a tenant can be moved back with one flag
ok(technologyEnabled(new FakeTenant(['vertical' => 'technology', 'technology_site' => false])) === false,
   'opt-out wins: technology vertical + technology_site=false');

echo "\n=== 2. Vertical registry is additive ===\n";
ok(in_array('technology', Vertical::ALL, true), 'technology is a registered vertical');
ok(Vertical::isValid('technology'), 'technology passes isValid()');
ok(count(Vertical::ALL) === 7, 'ALL has 7 verticals', 'got ' . count(Vertical::ALL));
foreach (['grocery', 'restaurant', 'snacks', 'manufacturer', 'solar', 'auto_parts'] as $v) {
    ok(in_array($v, Vertical::ALL, true), "pre-existing vertical still registered: $v");
}
// the feature matrix must not have changed for anyone else
ok(Vertical::decide('grocery', [], 'riders') === true,  'grocery still sees riders');
ok(Vertical::decide('grocery', [], 'kitchen_board') === false, 'grocery still hidden from kitchen_board');
ok(Vertical::decide('restaurant', [], 'kitchen_board') === true, 'restaurant still sees kitchen_board');
ok(Vertical::decide('technology', [], 'kitchen_board') === false, 'technology does not get kitchen_board');
ok(Vertical::decide('technology', [], 'riders') === true, 'technology sees riders');

echo "\n=== 3. Tenant isolation in TechTheme ===\n";

$ctxA = ['name' => 'Alpha IT', 'initials' => 'AI', 'base' => '/alpha', 'currency' => 'UGX',
         'waNumber' => '256700000001', 'logo' => '/a.png', 'favicon' => '', 'catalogueUrl' => '/alpha/catalogue',
         'orderUrl' => '/alpha/order', 'quoteUrl' => '/alpha/quote', 'shopUrl' => '/alpha/shop', 'url' => '/alpha'];
$ctxB = ['name' => 'Beta Systems', 'initials' => 'BS', 'base' => '/beta', 'currency' => 'KES',
         'waNumber' => '254700000002', 'logo' => '/b.png', 'favicon' => '', 'catalogueUrl' => '/beta/catalogue',
         'orderUrl' => '/beta/order', 'quoteUrl' => '/beta/quote', 'shopUrl' => '/beta/shop', 'url' => '/beta'];

$setA = [
    'theme_accent' => '#AA0000',
    'tech' => [
        'hero' => ['title' => 'Alpha hero', 'eyebrow' => 'ALPHA EYEBROW'],
        'contact' => ['phones' => ['+256 700 000 001'], 'emails' => ['a@alpha.test']],
        'featured_brands' => ['AlphaBrand'],
        'faq' => [['q' => 'Alpha question?', 'a' => 'Alpha answer.']],
    ],
];
$setB = [
    'theme_accent' => '#0000BB',
    'tech' => [
        'hero' => ['title' => 'Beta hero'],
        'contact' => ['phones' => ['+254 700 000 002'], 'emails' => ['b@beta.test']],
        'featured_brands' => ['BetaBrand'],
    ],
];

$A = TechTheme::build($setA, $ctxA);
$B = TechTheme::build($setB, $ctxB);
$blobA = json_encode($A);
$blobB = json_encode($B);

ok(! str_contains($blobB, 'Alpha'),        'B config contains no Alpha string');
ok(! str_contains($blobB, 'AA0000'),       'B config contains no Alpha accent');
ok(! str_contains($blobB, '256 700 000 001'), 'B config contains no Alpha phone');
ok(! str_contains($blobB, 'a@alpha.test'), 'B config contains no Alpha email');
ok(! str_contains($blobA, 'Beta'),         'A config contains no Beta string');
ok(! str_contains($blobA, '0000BB'),       'A config contains no Beta accent');
ok($A['colors']['accent'] === '#AA0000' && $B['colors']['accent'] === '#0000BB', 'each tenant keeps its own accent');
ok($A['currency'] === 'UGX' && $B['currency'] === 'KES', 'each tenant keeps its own currency');
ok($A['waNumber'] !== $B['waNumber'], 'each tenant keeps its own WhatsApp number');
ok($B['faq'] === [], 'B has no FAQ (A\'s FAQ did not leak)');

echo "\n=== 4. Defaults are tenant-neutral (no client baked into the code) ===\n";
$bare = TechTheme::build([], ['name' => 'Generic Co', 'initials' => 'GC', 'currency' => 'USD']);
$bareBlob = strtolower(json_encode($bare));
// NB: the generic words "solutions" and "services" ARE present — they are homepage
// SECTION KEYS (a "Business solutions" block any IT reseller would want), not a client
// name. The needles below are tenant-identifying strings only.
foreach (['secure solutions', 'secure-solutions', 'secure africa', 'secure-africa',
          'dishnet', 'family shoppers', 'shoppers', 'europearl', 'shree',
          'sophos', 'microsoft', 'bitdefender', 'ruijie',
          'kampala', 'uganda', 'juba', '+256', '+971', 'wa.me/'] as $needle) {
    ok(! str_contains($bareBlob, $needle), "default config free of \"$needle\"");
}
ok($bare['sections'] === TechTheme::SECTIONS, 'default section order is the full canonical list');
ok($bare['featuredCategories'] === [] && $bare['featuredBrands'] === [],
   'no categories or brands hardcoded — both derive from the live catalogue');

echo "\n=== 5. Config surface behaves ===\n";
$custom = TechTheme::build([
    'tech' => [
        'sections' => ['hero', 'brands', 'nope_not_real', 'faq'],
        'colors'   => ['ink' => '#111111', 'surface' => '#EEEEEE'],
        'radius'   => 4,
        'header_style' => 'bold',
        'card_style'   => 'elevated',
        'nav' => [['label' => 'Security', 'href' => 'cat:Security', 'children' => [['label' => 'Endpoint', 'href' => 'cat:Endpoint']]]],
        'footer_columns' => [['title' => 'Shop', 'links' => [['label' => 'All', 'href' => '/x']]]],
        'pages' => ['terms' => ['title' => 'Terms', 'blocks' => [['type' => 'h', 'text' => 'A'], ['type' => 'p', 'text' => 'B']]]],
        'rails' => ['software' => ['title' => 'Licences', 'categories' => ['Security'], 'limit' => 4]],
        'allow_cart' => false,
    ],
], ['name' => 'X', 'currency' => 'USD']);

ok($custom['sections'] === ['hero', 'brands', 'faq'], 'unknown section names are dropped', json_encode($custom['sections']));
ok($custom['colors']['ink'] === '#111111', 'ink token overridable');
ok($custom['layout']['radius'] === 4, 'radius overridable');
ok($custom['layout']['headerStyle'] === 'bold', 'header style overridable');
ok($custom['layout']['cardStyle'] === 'elevated', 'card style overridable');
ok(count($custom['nav']) === 1 && count($custom['nav'][0]['children']) === 1, 'two-level nav parsed');
ok($custom['footer'][0]['links'][0]['label'] === 'All', 'footer columns parsed');
ok(isset($custom['pages']['terms']) && count($custom['pages']['terms']['blocks']) === 2, 'content pages parsed');
ok($custom['rails']['software']['title'] === 'Licences' && $custom['rails']['software']['limit'] === 4, 'rails overridable');
ok($custom['commerce']['allowCart'] === false, 'commerce toggles honoured');

// hostile / malformed settings must not fatal
$junk = TechTheme::build([
    'tech' => ['sections' => 'not-an-array', 'nav' => 'nope', 'faq' => [['q' => 'only q']],
               'footer_columns' => [[]], 'pages' => ['../bad' => ['blocks' => []]], 'radius' => 'abc'],
], ['name' => 'Y']);
ok($junk['sections'] === TechTheme::SECTIONS, 'bad sections value falls back to defaults');
ok($junk['nav'] === [], 'bad nav value degrades to empty');
ok(is_int($junk['layout']['radius']), 'non-numeric radius coerced to int');
ok(! isset($junk['pages']['../bad']) && ! isset($junk['pages']['bad']), 'path-ish page slug rejected or sanitised');

echo "\n=== 5b. Inline landing-page blocks (rail / banner / newsletter / gallery) ===\n";

$page = TechTheme::build(['tech' => ['sections' => [
    'hero',
    ['type' => 'newsletter', 'title' => 'Join', 'text' => 'news', 'cta' => 'Go'],
    ['type' => 'rail', 'title' => 'Windows', 'categories' => ['Windows OS'], 'limit' => 6, 'alt' => true, 'sort' => 'new'],
    ['type' => 'banner', 'title' => 'Promo', 'text' => 'x', 'cta' => 'Shop', 'href' => 'cat:X', 'tone' => 'dark', 'align' => 'right'],
    ['type' => 'gallery', 'title' => 'G', 'images' => ['a.jpg', ['src' => 'b.jpg', 'caption' => 'B']]],
    ['type' => 'not_a_block', 'title' => 'nope'],      // unknown -> dropped
    ['type' => 'rail'],                                 // empty rail -> dropped
    ['type' => 'banner'],                               // empty banner -> dropped
    'faq',
]]], ['name' => 'X']);
$sec = $page['sections'];
ok(count($sec) === 6, 'unknown and empty blocks dropped', 'got ' . count($sec));
ok($sec[0] === 'hero' && $sec[5] === 'faq', 'built-in names still work alongside blocks');
ok(is_array($sec[1]) && $sec[1]['type'] === 'newsletter' && $sec[1]['cta'] === 'Go', 'newsletter block parsed');
ok(is_array($sec[2]) && $sec[2]['type'] === 'rail' && $sec[2]['limit'] === 6
   && $sec[2]['alt'] === true && $sec[2]['sort'] === 'new', 'rail block parsed with limit/alt/sort');
ok($sec[3]['type'] === 'banner' && $sec[3]['tone'] === 'dark' && $sec[3]['align'] === 'right', 'banner tone/align parsed');
ok($sec[4]['type'] === 'gallery' && count($sec[4]['images']) === 2, 'gallery images parsed');
// ORDER is preserved — this is what makes an arbitrary landing page reproducible
$types = array_map(fn ($x) => is_string($x) ? $x : $x['type'], $sec);
ok($types === ['hero', 'newsletter', 'rail', 'banner', 'gallery', 'faq'], 'section ORDER preserved exactly',
   implode(',', $types));
// hostile values must not fatal
$bad = TechTheme::build(['tech' => ['sections' => [
    ['type' => 'banner', 'tone' => 'nonsense', 'align' => 'nonsense', 'title' => 'T'],
    ['type' => 'gallery', 'images' => 'not-a-list'],
    ['type' => 'rail', 'title' => 'R', 'categories' => 'a,b', 'limit' => 9999],
]]], ['name' => 'X']);
$bs = $bad['sections'];
ok($bs[0]['tone'] === 'light' && $bs[0]['align'] === 'left', 'invalid tone/align fall back to defaults');
ok(count($bs) === 2, 'gallery with no valid images dropped', 'got ' . count($bs));
ok($bs[1]['limit'] === 24 && $bs[1]['categories'] === ['a', 'b'], 'rail limit clamped; comma string accepted');
// slides
$sl = TechTheme::build(['tech' => ['hero' => ['slides' => [
    ['title' => 'A', 'image' => 'a.jpg', 'cta' => 'Go', 'href' => 'cat:A'], ['title' => 'B'],
]]]], ['name' => 'X']);
ok(count($sl['hero']['slides']) === 2 && $sl['hero']['slides'][0]['cta'] === 'Go', 'hero slides parsed');
// blocks must not leak between tenants either
$t1 = TechTheme::build(['tech' => ['sections' => [['type' => 'banner', 'title' => 'AlphaPromo']]]], ['name' => 'A']);
$t2 = TechTheme::build(['tech' => ['sections' => [['type' => 'banner', 'title' => 'BetaPromo']]]], ['name' => 'B']);
ok(! str_contains(json_encode($t2), 'AlphaPromo'), 'blocks do not leak between tenants');

echo "\n=== 6. SEO helper carries no hardcoded vertical copy ===\n";
$seo = TechTheme::seo([], 'Generic Co');
ok($seo['title'] === 'Generic Co', 'title is just the name when nothing is set', $seo['title']);
ok(! str_contains(strtolower($seo['title'] . $seo['description']), 'paper'), 'no "Paper & Tissue" style hardcode');
$seo2 = TechTheme::seo(['meta_title' => 'Custom Title', 'meta_description' => 'Custom desc'], 'Generic Co');
ok($seo2['title'] === 'Custom Title' && $seo2['description'] === 'Custom desc', 'meta_title/meta_description override');
$seo3 = TechTheme::seo(['tagline' => 'IT for business'], 'Generic Co');
ok($seo3['title'] === 'Generic Co — IT for business', 'tagline composes the title', $seo3['title']);

echo "\n" . str_repeat('-', 60) . "\n";
echo ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
