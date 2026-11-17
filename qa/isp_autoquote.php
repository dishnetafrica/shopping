<?php declare(strict_types=1);
// Pure test of the automatic Internet-provider quotation: App\Services\Isp\IspQuotePlan (which products
// go on it, or why not), the WhatsApp caption and follow-up text (IspAutoQuote), and the quotation rules
// the model is given (IspSalesTurn::quoteRules). Framework-free. Product names are test values.
//   php qa/isp_autoquote.php
require __DIR__ . '/../app/Services/Travel/Conversation/ReplyGuard.php';
require __DIR__ . '/../app/Services/Sales/Brain/Knowledge.php';
require __DIR__ . '/../app/Services/Isp/IspQuotePlan.php';
require __DIR__ . '/../app/Services/Isp/IspSalesTurn.php';
require __DIR__ . '/../app/Services/Isp/IspAutoQuote.php';

use App\Services\Isp\IspAutoQuote as A;
use App\Services\Isp\IspQuotePlan as P;
use App\Services\Isp\IspSalesTurn as S;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$cat = [
    'Starlink Standard'                          => ['billing' => 'one_off', 'service_line' => 'starlink'],
    'Starlink Mini (Gen 4)'                      => ['billing' => 'one_off', 'service_line' => 'starlink'],
    'Starlink Residential Plan'                  => ['billing' => 'monthly', 'service_line' => 'starlink'],
    'Starlink Roam Plan'                         => ['billing' => 'monthly', 'service_line' => 'starlink'],
    'Fiber Basic 20 Mbps'                        => ['billing' => 'monthly', 'service_line' => 'fiber'],
    'Fiber Plus 50 Mbps'                         => ['billing' => 'monthly', 'service_line' => 'fiber'],
    'Fiber Installation (includes Wi-Fi router)' => ['billing' => 'one_off', 'service_line' => 'fiber'],
    'Public IP Address'                          => ['billing' => 'monthly', 'service_line' => 'fiber'],
];
$names = array_keys($cat);

echo "== reading the hidden line\n";
chk('items and quantities', P::items('<<QUOTE {"items":[{"name":"Starlink Mini","qty":1},{"name":"Starlink Roam Plan","qty":"2"}]}>>'),
    [['name' => 'Starlink Mini', 'qty' => 1], ['name' => 'Starlink Roam Plan', 'qty' => 2]]);
chk('the shop-style quantity object is read too', P::items('<<QUOTE {"items":[{"name":"Fiber Basic 20 Mbps","quantity":{"value":1,"unit":"pcs"}}]}>>'), [['name' => 'Fiber Basic 20 Mbps', 'qty' => 1]]);
chk('a broken line gives nothing', P::items('<<QUOTE {items: Starlink>>'), []);
chk('a missing quantity is 1, never 0', P::items('<<QUOTE {"items":[{"name":"Starlink Standard","qty":0}]}>>'), [['name' => 'Starlink Standard', 'qty' => 1]]);

echo "== matching names to the catalogue\n";
chk('exact name, any case', P::resolve('starlink standard', $names), 'Starlink Standard');
chk('the words of one product', P::resolve('Starlink Mini', $names), 'Starlink Mini (Gen 4)');
chk('"Residential" alone is the residential plan', P::resolve('Residential', $names), 'Starlink Residential Plan');
chk('two candidates: no guess ("Fiber" could be either plan or the installation)', P::resolve('Fiber', $names), null);
chk('"Starlink" alone: no guess', P::resolve('Starlink', $names), null);
chk('a product we do not sell', P::resolve('Starlink Priority', $names), null);
chk('punctuation does not matter', P::resolve('Fiber Installation includes Wi Fi router', $names), 'Fiber Installation (includes Wi-Fi router)');

echo "== what goes on the quotation\n";
$p = P::plan([['name' => 'Starlink Standard', 'qty' => 1], ['name' => 'Starlink Residential Plan', 'qty' => 1]], $cat);
chk('Starlink kit + plan: quoted as asked, nothing added', [$p['ok'], $p['lines'], $p['added']], [true, [['name' => 'Starlink Standard', 'qty' => 1], ['name' => 'Starlink Residential Plan', 'qty' => 1]], []]);
$p = P::plan([['name' => 'Fiber Basic 20 Mbps', 'qty' => 1]], $cat);
chk('a Fiber plan brings its one-time installation, as on the plans page', [$p['ok'], array_column($p['lines'], 'name'), $p['added']],
    [true, ['Fiber Basic 20 Mbps', 'Fiber Installation (includes Wi-Fi router)'], ['Fiber Installation (includes Wi-Fi router)']]);
$p = P::plan([['name' => 'Fiber Plus 50 Mbps', 'qty' => 1], ['name' => 'Fiber Installation (includes Wi-Fi router)', 'qty' => 1]], $cat);
chk('…not twice', array_column($p['lines'], 'name'), ['Fiber Plus 50 Mbps', 'Fiber Installation (includes Wi-Fi router)']);
$p = P::plan([['name' => 'Public IP Address', 'qty' => 1]], $cat);
chk('a public IP add-on alone brings no installation', [$p['ok'], $p['added']], [true, []]);
$noInstall = $cat;
unset($noInstall['Fiber Installation (includes Wi-Fi router)']);
chk('no installation product in the catalogue: nothing is made up', P::plan([['name' => 'Fiber Basic 20 Mbps', 'qty' => 1]], $noInstall)['added'], []);
$p = P::plan([['name' => 'Starlink Standard', 'qty' => 1], ['name' => 'Starlink Priority', 'qty' => 1]], $cat);
chk('an unknown product stops the quotation (salesperson prepares it)', [$p['ok'], $p['reason'], $p['unknown']], [false, 'unknown', ['Starlink Priority']]);
chk('more than 5 of one product is a custom quotation', P::plan([['name' => 'Starlink Standard', 'qty' => 6]], $cat)['reason'], 'too_many');
chk('5 is still automatic', P::plan([['name' => 'Starlink Standard', 'qty' => 5]], $cat)['ok'], true);
chk('the same product twice is one line', P::plan([['name' => 'Starlink Standard', 'qty' => 2], ['name' => 'starlink standard', 'qty' => 1]], $cat)['lines'], [['name' => 'Starlink Standard', 'qty' => 3]]);
chk('…and its total counts toward the limit', P::plan([['name' => 'Starlink Standard', 'qty' => 3], ['name' => 'Starlink Standard', 'qty' => 3]], $cat)['reason'], 'too_many');
chk('no items: no quotation', P::plan([], $cat)['reason'], 'no_items');
chk('a hidden product (not in the visible catalogue) is unknown', P::plan([['name' => 'Starlink Standard', 'qty' => 1]], ['Starlink Mini (Gen 4)' => $cat['Starlink Mini (Gen 4)']])['reason'], 'unknown');

echo "== what the customer reads\n";
chk('one-time and monthly kept apart', A::caption('Q-0012', 'USD', 550, 50, 14, 2), "\u{1F4C4} Quotation Q-0012\nOne-time: USD 550.00\nMonthly: USD 50.00\nValid 14 days.");
chk('plan only: no one-time line', A::caption('Q-0013', 'USD', 0, 80, 14, 0), "\u{1F4C4} Quotation Q-0013\nMonthly: USD 80\nValid 14 days.");
$t = A::afterText('Justus', '+211 926 134 489');
chk('the salesperson is named, with their number', str_contains($t, '*Justus* (+211926134489), your salesperson,'), true);
chk('…and how to go ahead', str_contains($t, 'reply *YES*'), true);
chk('no salesperson yet: still a person, never a blank', str_contains(A::afterText(null, null), 'Your salesperson will call you'), true);
chk('the follow-up text states no amount', preg_match('/\d/', A::afterText('Justus', null)), 0);

echo "== was the PDF delivered?\n";
chk('Evolution accepted (status is a word, not an error code)', A::delivered(['key' => ['id' => 'X'], 'status' => 'PENDING']), true);
chk('Cloud API accepted', A::delivered(['messages' => [['id' => 'wamid.X']]]), true);
chk('Cloud API refused a non-link', A::delivered(['error' => 'cloud_api_requires_public_url']), false);
chk('Evolution refused', A::delivered(['status' => 400, 'error' => 'Bad Request']), false);
chk('the gateway flagged a failed send', A::delivered(['_ok' => false, '_status' => 500]), false);
chk('nothing back', A::delivered(null), false);

echo "== the rules the model is given\n";
$r = S::quoteRules();
chk('the model writes the hidden line, never the quotation', [str_contains($r, '<<QUOTE'), str_contains($r, 'Never type a quotation')], [true, true]);
chk('exact catalogue names; Starlink kit + plan; Fiber installation automatic', [str_contains($r, 'EXACT names from PRODUCTS'), str_contains($r, 'kit AND the monthly plan'), str_contains($r, 'installation is added automatically')], [true, true, true]);
chk('large and custom jobs go to the salesperson', str_contains($r, 'more than 5 kits'), true);
chk('no discount on a price objection', str_contains($r, 'do not offer a discount'), true);
chk('no amounts and no Uganda defaults in the rules', [preg_match('/\$\s?\d/', $r), stripos($r, 'UGX'), stripos($r, 'Kampala')], [0, false, false]);
chk('the general rules no longer say "salesperson takes it from here" (that line is used only when quotations are off)', str_contains(S::rules(), 'take it from here'), false);

echo "\n" . ($fail ? "FAILURES" : "ALL PASS") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
