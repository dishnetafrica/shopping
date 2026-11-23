<?php declare(strict_types=1);
// Pure test of App\Services\Bot\MissedReply — when a customer whose reply went missing gets a late one.
//   php qa/missed_reply.php
require __DIR__ . '/../app/Services/Bot/MissedReply.php';

use App\Services\Bot\MissedReply as M;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$now = 1_800_000_000;
$base = ['unread' => 1, 'agent_active' => false, 'last_inbound_at' => $now - 600, 'human_until' => 0, 'muted' => false,
    'text' => 'Can it work immediately?', 'done_for' => 0, 'tries_for' => 0, 'tries' => 0, 'bot_finished' => false];
$w = fn (array $o) => M::skipReason($o + $base, $now);

echo "== gets a late reply\n";
chk('unanswered for 10 minutes', $w([]), null);
chk('unanswered for 23 hours', $w(['last_inbound_at' => $now - 23 * 3600]), null);
chk('a failed earlier try (1 of 3) is retried', $w(['tries_for' => $now - 600, 'tries' => 1]), null);
chk('an earlier customer message was recovered, this newer one was not', $w(['done_for' => $now - 9000]), null);

echo "== never gets one\n";
chk('already answered', $w(['unread' => 0]), 'answered');
chk('staff took the chat over', $w(['agent_active' => true]), 'a person is handling this chat');
chk('someone replied from the phone recently', $w(['human_until' => $now + 60]), 'replied from the phone recently');
chk('muted (staff / internal number)', $w(['muted' => true]), 'number is muted');
chk('the bot answered or chose not to', $w(['bot_finished' => true]), 'the bot already handled it (answered or chose not to)');
chk('2 minutes old — the normal reply may still come', $w(['last_inbound_at' => $now - 120]), 'too recent — the normal reply may still be on its way');
chk('older than the 24 h window', $w(['last_inbound_at' => $now - 25 * 3600]), 'too old');
chk('this message was already recovered', $w(['done_for' => $now - 600]), 'already recovered');
chk('3 failed tries — stop', $w(['tries_for' => $now - 600, 'tries' => 3]), 'gave up after 3 tries');
chk('a photo / voice placeholder only', $w(['text' => '[image]']), 'no text to answer');
chk('empty', $w(['text' => '   ']), 'no text to answer');

echo "== did the bot finish? (its step log)\n";
chk('the real failure of 16 Nov: started, ai_usage, then the retry dropped as duplicate', M::botFinished([['stage' => 'queued', 'detail' => null], ['stage' => 'started', 'detail' => null], ['stage' => 'ai_usage', 'detail' => 'in=9737 out=9'], ['stage' => 'skipped', 'detail' => 'duplicate message id']]), false);
chk('a normal reply', M::botFinished([['stage' => 'started', 'detail' => null], ['stage' => 'ai_replied', 'detail' => null]]), true);
chk('a person took over (genuine skip)', M::botFinished([['stage' => 'skipped', 'detail' => 'a person is handling this chat (Take over)']]), true);
chk('nothing logged at all (job lost)', M::botFinished([]), false);
chk('already recovered once', M::botFinished([['stage' => 'missed_replied', 'detail' => null]]), true);

echo "== paused for someone on the phone (DishNet, 23 Nov 2026: an email sent by a customer ready to pay waited two days)\n";
$heldLog = [['stage' => 'queued', 'detail' => null], ['stage' => 'started', 'detail' => null], ['stage' => 'email_captured', 'detail' => 'seralomin@gmail.com'], ['stage' => 'skipped', 'detail' => M::HELD]];
chk('the live step log: a pause, not an answer', [M::botFinished($heldLog), M::held($heldLog)], [false, true]);
chk('the exact words the message job logs', M::HELD, 'owner is handling this chat (recent manual reply)');
chk('a Take over is still a person handling it (finished, not held)', [M::botFinished([['stage' => 'skipped', 'detail' => 'a person is handling this chat (Take over)']]), M::held([['stage' => 'skipped', 'detail' => 'a person is handling this chat (Take over)']])], [true, false]);
chk('a muted number is still a genuine skip', M::botFinished([['stage' => 'skipped', 'detail' => 'number is muted (no auto-reply)']]), true);
chk('held, while the pause lasts: wait', $w(['human_until' => $now + 300, 'bot_finished' => false]), 'replied from the phone recently');
chk('held, the pause is over and nobody replied: answer it late', $w(['human_until' => $now - 60, 'bot_finished' => false]), null);
chk('held and recovered once: never twice', $w(['human_until' => $now - 60, 'done_for' => $now - 600]), 'already recovered');
chk('nothing to read in it ("[image]"): left for the person', $w(['human_until' => $now - 60, 'text' => '[image]']), 'no text to answer');

echo "== settings\n";
chk('window can be shortened (2 h)', M::skipReason(['last_inbound_at' => $now - 3 * 3600] + $base, $now, ['window_min' => 120]), 'too old');
chk('wait can be longer (15 min)', M::skipReason($base, $now, ['wait_min' => 15]), 'too recent — the normal reply may still be on its way');
chk('a wait below 2 minutes is raised to 2', M::skipReason(['last_inbound_at' => $now - 90] + $base, $now, ['wait_min' => 0]), 'too recent — the normal reply may still be on its way');
chk('words with brackets inside still count', M::hasWords('Price [Starlink] please'), true);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
