<?php declare(strict_types=1);
// Pure test: a customer who asks for the salesperson gets THEIR salesperson's name and number
// (App\Services\Isp\SalesContact). Framework-free. Customer texts are the live ones.   php qa/isp_sales_contact.php
require __DIR__ . '/../app/Services/Sales/Brain/Knowledge.php';
require __DIR__ . '/../app/Services/Isp/IspQuotePlan.php';
require __DIR__ . '/../app/Services/Isp/SupportContact.php';
require __DIR__ . '/../app/Services/Isp/SalesContact.php';

use App\Services\Isp\SalesContact as S;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== asking for a salesperson\n";
foreach (['Connect me to the sales person', 'connect me to the salesperson please', 'Can I talk to someone?', 'I want to speak with an agent',
    'Please call me', 'Give me your phone number', 'What is the contact for sales', 'Who is the sales rep?'] as $t) {
    chk("asks: \"{$t}\"", S::asks($t), true);
}
foreach (['I can proceed with the 20 mpbs', 'How much is the Starlink Mini?', 'Seralomin@gmail.com', 'My internet is not working', 'Thanks'] as $t) {
    chk("does not ask: \"{$t}\"", S::asks($t), false);
}
chk('decorative letters are read too', S::asks("\u{1D402}\u{1D428}\u{1D427}\u{1D427}\u{1D41E}\u{1D41C}\u{1D42D} me to the sales person"), true);

echo "== the number\n";
chk('stored digits → +digits', S::phone('211926134489'), '+211926134489');
chk('already formatted', S::phone('+211 926 134 489'), '+211926134489');
chk('nothing usable', [S::phone(null), S::phone(''), S::phone('12345')], ['', '', '']);

echo "== the reply\n";
$live = 'Of course! You can reach our sales team at +211 923 400 000. They will be happy to assist you.';
$out = S::ensure($live, 'Connect me to the sales person', 'Justus', '+211926134489');
chk('live reply: their own salesperson is added', str_ends_with($out, "\n\n\u{1F4DE} Your salesperson is *Justus* (+211926134489): you can call or WhatsApp them directly."), true);
chk('…and the office closed: when it opens', str_ends_with(S::ensure($live, 'connect me', 'Justus', '+211926134489', 'today at 9:00 AM'), 'you can call or WhatsApp them directly when the office opens (today at 9:00 AM).'), true);
chk('the reply already has the number (any spacing): unchanged', S::ensure('Call Justus on +211 926 134 489.', 'call me', 'Justus', '+211926134489'), 'Call Justus on +211 926 134 489.');
chk('they did not ask: unchanged', S::ensure('The Mini is a good fit.', 'How much is the Mini?', 'Justus', '+211926134489'), 'The Mini is a good fit.');
chk('a support reply: unchanged (a problem is not a sale)', S::ensure('Please contact our technical support team.', 'my wifi is not working, connect me to someone', 'Justus', '+211926134489'), 'Please contact our technical support team.');
chk('no number for the salesperson: unchanged', S::ensure($live, 'connect me', 'Justus', ''), $live);
chk('a hidden marker stays last', str_ends_with(S::ensure("Sure.\n<<QUOTE {\"items\":[]}>>", 'connect me to sales', 'Justus', '+211926134489'), "directly.\n<<QUOTE {\"items\":[]}>>"), true);

echo "== what the model is told\n";
$r = S::rule('Justus', '+211926134489');
chk('their salesperson, not the general line', [str_contains($r, "THIS CUSTOMER'S SALESPERSON: Justus (+211926134489)"), str_contains($r, 'not the general sales line')], [true, true]);
chk('a service problem is still support', str_contains($r, 'still Technical Support'), true);

echo "\n" . ($fail ? "FAILURES" : "ALL PASS") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
