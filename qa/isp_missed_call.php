<?php declare(strict_types=1);
// Pure test of a missed WhatsApp call on an Internet provider's line (App\Services\Isp\MissedCall):
// reading Evolution's "call" event, what the caller reads, and the salesperson's alert.
//   php qa/isp_missed_call.php
require __DIR__ . '/../app/Services/Isp/MissedCall.php';

use App\Services\Isp\MissedCall as C;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}
$ev = fn (array $data, string $event = 'call') => ['event' => $event, 'instance' => 'dishnet', 'data' => $data];

echo "== reading the call event\n";
$c = C::parse($ev(['id' => 'CALL1', 'from' => '211984176391@s.whatsapp.net', 'status' => 'offer', 'isVideo' => false, 'isGroup' => false, 'date' => '2026-11-18T09:00:00Z']));
chk('a voice call: caller, call id, line', [$c['phone'], $c['call_id'], $c['instance'], $c['video'], $c['status']], ['211984176391', 'CALL1', 'dishnet', false, 'offer']);
chk('a hidden-number caller is keyed like their chat (lid digits)', C::parse($ev(['id' => 'C2', 'from' => '225683989098601@lid', 'status' => 'offer']))['phone'], '225683989098601');
chk('a device suffix is dropped', C::parse($ev(['id' => 'C3', 'from' => '211984176391:12@s.whatsapp.net']))['phone'], '211984176391');
chk('a video call is marked', C::parse($ev(['id' => 'C4', 'from' => '211984176391@s.whatsapp.net', 'isVideo' => true]))['video'], true);
chk('event names Evolution uses: CALL, call.upsert', [C::parse($ev(['id' => 'C5', 'from' => '211984176391@s.whatsapp.net'], 'CALL')) !== null, C::parse($ev(['id' => 'C5', 'from' => '211984176391@s.whatsapp.net'], 'call.upsert')) !== null], [true, true]);
chk('a list of calls: the first', C::parse($ev([['id' => 'C6', 'from' => '211984176391@s.whatsapp.net']]))['call_id'], 'C6');
chk('no call id: caller + time instead', C::parse($ev(['from' => '211984176391@s.whatsapp.net', 'date' => 'D1']))['call_id'], '211984176391:D1');
chk('a group call: ignored', [C::parse($ev(['id' => 'G', 'from' => '120363@g.us'])), C::parse($ev(['id' => 'G', 'from' => '211984176391@s.whatsapp.net', 'isGroup' => true]))], [null, null]);
chk('answered (accept) is read, so the webhook can mark the call answered', C::parse($ev(['id' => 'A', 'from' => '211984176391@s.whatsapp.net', 'status' => 'accept']))['status'], 'accept');
chk('it waits for the call to stop ringing before calling it missed', C::RING_SECONDS >= 45, true);
chk('WhatsApp\'s own account (0@): ignored', C::parse($ev(['id' => 'Z', 'from' => '0@s.whatsapp.net'])), null);
chk('a message or connection event: not a call', [C::parse(['event' => 'messages.upsert', 'data' => ['key' => []]]), C::parse(['event' => 'connection.update', 'data' => ['state' => 'open']]), C::parse([])], [null, null, null]);

echo "== what the caller reads\n";
$open = C::customerReply('Justus', null);
chk('office open: type here, Justus will call back', $open, "Sorry we missed your call \u{1F64F} We cannot take calls on this WhatsApp number. Please type your question here and we will answer right away. Justus from our sales team will also call you back.");
chk('office closed: call back when it opens', str_ends_with(C::customerReply('Justus', 'tomorrow at 9:00 AM'), 'Justus from our sales team will also call you back when the office opens (tomorrow at 9:00 AM).'), true);
chk('no salesperson yet: the sales team', str_contains(C::customerReply(null, null), 'Our sales team will also call you back.'), true);
chk('WhatsApp hides the caller\'s number: asked for one', str_ends_with(C::customerReply('Justus', null, true), 'Justus from our sales team will also call you back. Please send us the best phone number to reach you on.'), true);
chk('never a price or a time we cannot keep', preg_match('/\$|USD|SSP|shortly|minutes|today/i', $open . C::customerReply(null, 'tomorrow at 9:00 AM')), 0);

echo "== the salesperson's alert\n";
$a = C::alertText('211984176391', 'Peter', false, null, "I need internet for 250 people\nin Atlabara", '17 Nov', true, true);
chk('who, number, last message, call back', $a, "\u{1F4DE} *Missed WhatsApp call* — Peter +211984176391\nThey tried to call the business WhatsApp line. The bot told them you will call them back.\nTheir last message (17 Nov): \"I need internet for 250 people in Atlabara\"\nPlease call them back.");
$b = C::alertText('225683989098601', '225683989098601', true, 'tomorrow at 9:00 AM', null, null, false, false);
chk('a number as a name is not shown twice; video; closed; no owner', [str_contains($b, '*Missed WhatsApp video call* — +225683989098601'), str_contains($b, 'when the office opens (tomorrow at 9:00 AM)'), str_contains($b, '(no salesperson assigned yet)'), str_contains($b, 'The bot told them'), str_contains($b, 'last message')], [true, true, true, false, false]);
chk('hidden number: the alert shows the callable label, not the hidden id as "+"', str_contains(C::alertText('234239681409272', 'Jel', false, null, null, null, true, true, "WhatsApp hides this customer's number — reply to them in the ShopBot inbox (chat 234239681409272)"), "Missed WhatsApp call* — Jel WhatsApp hides this customer's number"), true);
chk('a long last message is cut', mb_strlen(C::alertText('1234567', null, false, null, str_repeat('a', 400), null, false, true)) < 400, true);

echo "\n" . ($fail ? 'FAILURES' : 'ALL PASS') . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
