<?php declare(strict_types=1);
// Pure test of the automatic Internet-provider quotation: App\Services\Isp\IspQuotePlan (which products
// go on it, or why not), the WhatsApp caption and follow-up text (IspAutoQuote), and the quotation rules
// the model is given (IspSalesTurn::quoteRules). Framework-free. Product names are test values.
//   php qa/isp_autoquote.php
require __DIR__ . '/../app/Services/Travel/Conversation/ReplyGuard.php';
require __DIR__ . '/../app/Services/Sales/Brain/Knowledge.php';
require __DIR__ . '/../app/Services/Bot/TimeContext.php';
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

echo "== what the customer said about their setup (decorative letters included)\n";
chk('decorative Unicode letters read as plain text', P::plain("\u{1D67D}\u{1D698}\u{1D69D} \u{1D695}\u{1D692}\u{1D694}\u{1D68E} 250 \u{1D699}\u{1D68E}\u{1D698}\u{1D699}\u{1D695}\u{1D68E}"), 'Not like 250 people');
chk('…also without the intl extension', P::plainByHand("\u{1D67D}\u{1D698}\u{1D69D} \u{1D695}\u{1D692}\u{1D694}\u{1D68E} 250 \u{1D699}\u{1D68E}\u{1D698}\u{1D699}\u{1D695}\u{1D68E} \u{1D7F8}\u{1D7F6}"), 'Not like 250 people 20');
chk('"Not like 250 people" in decorative letters = 250 users', P::users(["\u{1D67D}\u{1D698}\u{1D69D} \u{1D695}\u{1D692}\u{1D694}\u{1D68E} 250 \u{1D699}\u{1D68E}\u{1D698}\u{1D699}\u{1D695}\u{1D68E}"]), 250);
chk('"3o users" typed with a letter o = 30', P::users(['it can hold 3o users']), 30);
chk('the largest number across messages', P::users(['for 20+ users', 'actually about 300 active users', 'for 1,000 students next year']), 1000);
chk('"users: 60" = 60', P::users(['users: 60']), 60);
chk('speeds, money and plain numbers are not users', P::users(['Fiber 50 Mbps please', '$250 budget', 'I have 2 kits']), 0);
chk('the customer\'s name from the hidden line', [P::customerName('<<QUOTE {"customer_name":"peter","items":[]}>>'), P::customerName('<<QUOTE {"customer_name":"mary  akech","items":[]}>>')], ['Peter', 'Mary Akech']);
chk('…not a phone number or nothing', [P::customerName('<<QUOTE {"customer_name":"+211 92","items":[]}>>'), P::customerName('<<QUOTE {"items":[]}>>')], ['', '']);

echo "== when no quotation is sent, the reply does not claim one\n";
chk('"here is your quotation" is removed, the rest kept', A::withoutQuoteClaims("Thank you, Peter! I will prepare your quotation for the kit and plan. Sure — here is your quotation \u{1F4C4}"), 'Thank you, Peter!');
chk('ordinary sentences stay', A::withoutQuoteClaims('Great choice. Which area are you in?'), 'Great choice. Which area are you in?');
chk('the large-setup line names the size, no amount', [sprintf(A::LARGE, 250), preg_match('/\$|USD/', A::LARGE)], ['For a setup of about 250 users you need a business plan, which your salesperson prices for you. They will prepare a custom quotation and contact you shortly.', 0]);

echo "== the bot promised a follow-up (someone must be told)\n";
chk('live: "I will confirm the prices … with the team. Please hold on"', S::promisesFollowUp('I will confirm the prices and availability for the MikroTik router and any necessary cables with the team. Please hold on for a moment.'), true);
chk('"your salesperson will confirm the exact price" (the guard\'s own note)', S::promisesFollowUp('Your salesperson will confirm the exact price in a written quotation.'), true);
chk('"we\'ll get back to you"', S::promisesFollowUp("Thanks! We'll get back to you shortly."), true);
chk('a question or an answer is not a promise', [S::promisesFollowUp('Which area are you in?'), S::promisesFollowUp('The Starlink Standard kit is a one-time charge.')], [false, false]);

echo "== outside office hours: the call is promised for when the office opens\n";
$T = App\Services\Bot\TimeContext::class;
$week = $T::parseHours('Mon–Sat 9 AM – 6 PM (closed Sundays)');
chk('DishNet\'s published hours are understood (the bracketed "closed Sundays" no longer hides them)', [$week['mon'] ?? null, $week['sat'] ?? null, $week['sun'] ?? null], [[[540, 1080]], [[540, 1080]], []]);
$z = new DateTimeZone('Africa/Juba');
$at = fn (string $d) => new DateTimeImmutable($d, $z);
$next = fn (string $d) => ($n = $T::nextOpen($week, $at($d))) ? $n->format('D H:i') : null;
chk('Tuesday 22:30 → Wednesday 9:00', $next('2026-11-17 22:30'), 'Wed 09:00');
chk('Wednesday 07:10 → the same morning, 9:00', $next('2026-11-18 07:10'), 'Wed 09:00');
chk('open (Wednesday noon) → now', $next('2026-11-18 12:00'), 'Wed 12:00');
chk('Saturday 19:00 and Sunday → Monday 9:00', [$next('2026-11-21 19:00'), $next('2026-11-22 10:00')], ['Mon 09:00', 'Mon 09:00']);
chk('unknown hours → nothing is said about timing', $T::nextOpen($T::parseHours(''), $at('2026-11-17 22:30')), null);
chk('the customer reads "tomorrow at 9:00 AM" / "today at 9:00 AM" / "on Monday at 9:00 AM"', [S::opensPhrase($at('2026-11-18 09:00'), $at('2026-11-17 22:30')), S::opensPhrase($at('2026-11-18 09:00'), $at('2026-11-18 07:10')), S::opensPhrase($at('2026-11-23 09:00'), $at('2026-11-21 19:00'))], ['tomorrow at 9:00 AM', 'today at 9:00 AM', 'on Monday at 9:00 AM']);
chk('the model is told the next opening when closed', str_contains($T::block($at('2026-11-17 22:30'), 'Africa/Juba', 'Mon–Sat 9 AM – 6 PM (closed Sundays)'), '- Next opening: Wednesday, 18 November, 9:00 AM'), true);
chk('…and not when open', str_contains($T::block($at('2026-11-18 12:00'), 'Africa/Juba', 'Mon–Sat 9 AM – 6 PM'), 'Next opening'), false);
chk('after the PDF at night: "will call you when our office opens (tomorrow at 9:00 AM)"', str_contains(A::afterText('Justus', null, 'tomorrow at 9:00 AM'), '*Justus*, your salesperson, will call you when our office opens (tomorrow at 9:00 AM) to confirm coverage'), true);
chk('…in office hours, unchanged', str_contains(A::afterText('Justus', null), 'your salesperson, will call you to confirm coverage'), true);
chk('hand-off at night: "shortly" becomes the opening time', A::timed(A::HANDOFF, 'tomorrow at 9:00 AM'), 'Your salesperson will prepare your quotation and send it to you when our office opens (tomorrow at 9:00 AM).');
chk('…in office hours, unchanged', A::timed(A::HANDOFF, null), A::HANDOFF);
chk('the rules tell the model not to promise a call now when closed', str_contains(S::rules(), 'when CURRENT TIME says "Open right now: NO", never say anyone will call'), true);

echo "== the rules the model is given\n";
$r = S::quoteRules();
chk('the model writes the hidden line, never the quotation', [str_contains($r, '<<QUOTE'), str_contains($r, 'Never type a quotation')], [true, true]);
chk('exact catalogue names; Starlink kit + plan; Fiber installation automatic', [str_contains($r, 'EXACT names from PRODUCTS'), str_contains($r, 'kit AND the monthly plan'), str_contains($r, 'installation is added automatically')], [true, true, true]);
chk('large and custom jobs go to the salesperson', str_contains($r, 'more than 5 kits'), true);
chk('50+ users on Starlink: no Residential, a Priority quotation from the salesperson', str_contains($r, 'do NOT recommend the Residential plan'), true);
chk('the hidden line carries the customer\'s name', str_contains($r, 'customer_name'), true);
chk('accessories: price what is in PRODUCTS, salesperson quotes the rest, never "I will check"', [str_contains($r, 'ACCESSORIES'), str_contains($r, 'never say you will check yourself')], [true, true]);
chk('no discount on a price objection', str_contains($r, 'do not offer a discount'), true);
chk('no amounts and no Uganda defaults in the rules', [preg_match('/\$\s?\d/', $r), stripos($r, 'UGX'), stripos($r, 'Kampala')], [0, false, false]);
chk('the general rules no longer say "salesperson takes it from here" (that line is used only when quotations are off)', str_contains(S::rules(), 'take it from here'), false);

echo "\n" . ($fail ? "FAILURES" : "ALL PASS") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
