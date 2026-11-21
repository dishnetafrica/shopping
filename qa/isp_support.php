<?php declare(strict_types=1);
// Pure test: an existing customer with a problem gets the Technical Support number at once
// (App\Services\Isp\SupportContact, IspSalesTurn::openingLine). Framework-free. The bot replies are the live
// ones from 21 Nov 2026.   php qa/isp_support.php
require __DIR__ . '/../app/Services/Travel/Conversation/ReplyGuard.php';
require __DIR__ . '/../app/Services/Sales/Brain/Knowledge.php';
require __DIR__ . '/../app/Services/Bot/TimeContext.php';
require __DIR__ . '/../app/Services/Isp/IspQuotePlan.php';
require __DIR__ . '/../app/Services/Isp/SupportContact.php';
require __DIR__ . '/../app/Services/Isp/IspSalesTurn.php';

use App\Services\Isp\IspSalesTurn as S;
use App\Services\Isp\SupportContact as C;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$company = ['topic' => 'company', 'body' => "DishNet Africa Ltd is an internet service provider.\nDepartments: Sales +211 923 400 000 (Mon–Sat 9 AM – 6 PM) · Technical Support +211 921 443 002 (Mon–Sun 7 AM – 10 PM) · Accounts +211 921 443 009 (Mon–Sat 9 AM – 6 PM)."];
$c = C::fromKnowledge([['topic' => 'starlink', 'body' => 'Hardware: suggest the Mini first.'], $company]);

echo "== the number comes from the published knowledge\n";
chk('Technical Support, not Sales or Accounts, with its own hours', $c, ['phone' => '+211 921 443 002', 'hours' => 'Mon–Sun 7 AM – 10 PM']);
chk('the line the customer reads', C::line($c), 'Technical Support: +211 921 443 002 (Mon–Sun 7 AM – 10 PM)');
chk('"Technical Support: <number>" with no hours', C::fromKnowledge([['topic' => 'faq', 'body' => 'Technical support: +211921443002']]), ['phone' => '+211921443002', 'hours' => '']);
chk('nothing published: none, never made up', C::fromKnowledge([['topic' => 'starlink', 'body' => 'Contact our technical support team.']]), null);
chk('training questions are never customer knowledge', C::fromKnowledge([['topic' => 'quiz', 'body' => 'Technical Support +211 900 000 000']]), null);

echo "== what the model is told\n";
$r = C::rule($c);
chk('give the number in the first reply', [str_contains($r, 'In your FIRST reply give them the number now: Technical Support: +211 921 443 002 (Mon–Sun 7 AM – 10 PM)'), str_contains($r, 'call or WhatsApp it')], [true, true]);
chk('support keeps its own hours: no "outside business hours" / "tomorrow"', [str_contains($r, 'the OFFICE HOURS rule is for sales only'), str_contains($r, 'never say yourself when support is open, closed, "outside business hours" or available tomorrow')], [true, true]);
chk('…with no exception: support\'s next opening is not the sales office\'s (Codex on #176)', [str_contains($r, 'unless'), str_contains(C::rule(null), 'unless')], [false, false]);
chk('the hours go with the number, as written', str_contains($r, 'Give the support hours exactly as written with the number'), true);
chk('no troubleshooting, no "connect you", no "note your request"', [str_contains($r, 'Do not troubleshoot'), str_contains($r, 'do not offer to connect them, pass it on or note their request')], [true, true]);
chk('no number published: from the knowledge, never invented', str_contains(C::rule(null), 'Technical Support contact written in APPROVED SALES KNOWLEDGE (never make one up)'), true);
chk('the sales hours rule says it is for sales only', str_contains(S::rules(), 'OFFICE HOURS (sales only; Technical Support keeps its own, see SUPPORT)'), true);
chk('…and still forbids "we will call now" when sales is closed', str_contains(S::rules(), 'when CURRENT TIME says "Open right now: NO", never say anyone will call'), true);

echo "== the live replies get the number\n";
$live1 = "Since you're located next to the junction of Jebel Amianin in Sherikat, I will need to connect you with our technical support team to address your Wi-Fi issue.\n\nSince it's currently outside business hours, they will be available to assist you tomorrow at 9:00 AM. Is there anything else I can help you with in the meantime?";
$live2 = "I understand that your Wi-Fi is not working properly. To assist you better, I recommend contacting our technical support team.";
chk('live reply 1: the number is added', str_ends_with(C::ensure($live1, $c), "\n\n\u{1F4DE} Technical Support: +211 921 443 002 (Mon–Sun 7 AM – 10 PM)"), true);
chk('live reply 2: the number is added', str_ends_with(C::ensure($live2, $c), 'Technical Support: +211 921 443 002 (Mon–Sun 7 AM – 10 PM)'), true);
chk('already has the number (any spacing): unchanged', C::ensure('Please call our technical support team on +211921443002.', $c), 'Please call our technical support team on +211921443002.');
chk('a sales reply: unchanged', C::ensure('Starlink Mini suits a small office. Shall I send you a quotation?', $c), 'Starlink Mini suits a small office. Shall I send you a quotation?');
chk('no number published: unchanged', C::ensure($live2, null), $live2);
chk('a hidden marker stays last', str_ends_with(C::ensure("Our support team can help.\n<<QUOTE {\"items\":[]}>>", $c), "Technical Support: +211 921 443 002 (Mon–Sun 7 AM – 10 PM)\n<<QUOTE {\"items\":[]}>>"), true);

echo "== when the sales office opens: the exact words\n";
$tz = new DateTimeZone('Africa/Juba');
$at = fn (string $s) => new DateTimeImmutable($s, $tz);
$week = App\Services\Bot\TimeContext::parseHours('Mon–Sat 9 AM – 6 PM (closed Sundays)');
$sat = $at('2026-11-21 08:19');
chk('Saturday 08:19 in Juba: "today at 9:00 AM", never "tomorrow" (a Sunday)', S::opensPhrase(App\Services\Bot\TimeContext::nextOpen($week, $sat), $sat), 'today at 9:00 AM');
$satNight = $at('2026-11-21 19:00');
chk('Saturday evening: "on Monday at 9:00 AM"', S::opensPhrase(App\Services\Bot\TimeContext::nextOpen($week, $satNight), $satNight), 'on Monday at 9:00 AM');
chk('the model is given those words to use', str_contains(S::openingLine('today at 9:00 AM'), 'It opens today at 9:00 AM. When you say when the salesperson will contact them, use exactly "today at 9:00 AM" — do not work out the day yourself.'), true);

echo "\n" . ($fail ? "FAILURES" : "ALL PASS") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
