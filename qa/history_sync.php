<?php declare(strict_types=1);
// Pure test of copying a WhatsApp line's history into ShopBot (App\Services\WhatsApp\HistorySync): times in
// every shape Evolution stores them, the text of each kind of message, and the match that stops a message
// ShopBot already logged live from being copied twice. Framework-free.
//   php qa/history_sync.php
require __DIR__ . '/../app/Services/WhatsApp/HistorySync.php';

use App\Services\WhatsApp\HistorySync as H;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== times\n";
chk('seconds', H::timestamp(1763400000), 1763400000);
chk('a numeric string', H::timestamp('1763400000'), 1763400000);
chk('{low, high} (the shape the old sync read as 1970)', H::timestamp(['low' => 1763400000, 'high' => 0]), 1763400000);
chk('milliseconds', H::timestamp(1763400000123), 1763400000);
chk('an ISO date', H::timestamp('2026-11-17T18:40:00Z'), strtotime('2026-11-17T18:40:00Z'));
chk('nothing', [H::timestamp(null), H::timestamp(''), H::timestamp('not a date')], [0, 0, 0]);

echo "== text\n";
chk('plain text', H::text(['message' => ['conversation' => 'hello']]), 'hello');
chk('a reply with a link preview', H::text(['message' => ['extendedTextMessage' => ['text' => 'price?']]]), 'price?');
chk('a disappearing message is unwrapped', H::text(['message' => ['ephemeralMessage' => ['message' => ['conversation' => 'hi']]]]), 'hi');
chk('a photo with a caption keeps the caption', H::text(['message' => ['imageMessage' => ['caption' => 'my roof']]]), 'my roof');
chk('a photo, a voice note, a location without text: labelled', [H::text(['message' => ['imageMessage' => []]]), H::text(['message' => ['audioMessage' => []]]), H::text(['message' => ['locationMessage' => []]])], ['📷 Photo', '🎤 Voice message', '📍 Location']);
chk('a reaction or an empty record: nothing (not a message)', [H::text(['message' => ['reactionMessage' => ['text' => '👍']]]), H::text([])], ['', '']);

echo "== already logged live (no duplicates)\n";
$ref = new ReflectionClass(H::class);
$key = $ref->getMethod('liveKey');
$key->setAccessible(true);
$live = [$key->invoke(null, '211911000001', 'out', 'Welcome to DishNet!') => [1763400000]];
chk('same chat, direction and text within 3 minutes', H::loggedLive($live, '211911000001', 'out', 'Welcome to DishNet!', 1763400100), true);
chk('…spacing differences do not matter', H::loggedLive($live, '211911000001', 'out', "Welcome  to\nDishNet!", 1763400100), true);
chk('more than 3 minutes apart: a different message', H::loggedLive($live, '211911000001', 'out', 'Welcome to DishNet!', 1763400400), false);
chk('another chat or the other direction: different', [H::loggedLive($live, '211911000002', 'out', 'Welcome to DishNet!', 1763400000), H::loggedLive($live, '211911000001', 'in', 'Welcome to DishNet!', 1763400000)], [false, false]);

echo "\n" . ($fail ? 'FAILURES' : 'ALL PASS') . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
