<?php declare(strict_types=1);
// Pure test: is a WhatsApp line receiving messages late? (App\Services\WhatsApp\DeliveryLag) The case is
// DishNet's of 24 Nov 2026: every customer message reached ShopBot ~7 hours late while the line said "open".
//   php qa/delivery_lag.php
require __DIR__ . '/../app/Services/WhatsApp/DeliveryLag.php';

use App\Services\WhatsApp\DeliveryLag as L;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$now = 1_800_000_000;
$rec = fn (string $id, int $agoMin, array $msg, string $jid = '211900000001@s.whatsapp.net', bool $me = false, $ts = null) =>
    ['key' => ['id' => $id, 'remoteJid' => $jid, 'fromMe' => $me], 'message' => $msg, 'messageTimestamp' => $ts ?? ($now - $agoMin * 60)];

echo "== what counts: a customer's plain text only\n";
$store = [
    $rec('A', 30, ['conversation' => 'What are the available data plans for Starlink?']),
    $rec('B', 40, ['extendedTextMessage' => ['text' => 'Price please']]),
    $rec('C', 50, ['ephemeralMessage' => ['message' => ['conversation' => 'Hello']]]),
    $rec('D', 30, ['conversation' => 'our reply'], '211900000001@s.whatsapp.net', true),
    $rec('E', 30, ['conversation' => 'group chat'], '1203630@g.us'),
    $rec('F', 30, ['conversation' => 'a status'], 'status@broadcast'),
    $rec('G', 30, ['stickerMessage' => []]),
    $rec('H', 30, ['reactionMessage' => ['text' => '👍']]),
    $rec('I', 30, ['protocolMessage' => ['editedMessage' => ['conversation' => 'edited']]]),
    $rec('J', 30, ['conversation' => '   ']),
    $rec('A', 30, ['conversation' => 'What are the available data plans for Starlink?']),   // stored twice
    $rec('K', 30, ['conversation' => 'hidden number'], '106863098109996@lid'),
    $rec('M', 0, ['conversation' => 'ms time'], '211900000002@s.whatsapp.net', false, ($now - 1800) * 1000),
    $rec('N', 0, ['conversation' => 'low/high time'], '211900000003@s.whatsapp.net', false, ['low' => $now - 1800, 'high' => 0]),
];
chk('text, extended text, disappearing text, hidden numbers, any time format; once each',
    array_column(L::customerTexts($store), 'id'), ['A', 'B', 'C', 'K', 'M', 'N']);

echo "== late or not\n";
$msgs = L::customerTexts($store);
chk('all received: nothing missing', L::assess($msgs, array_fill_keys(['A', 'B', 'C', 'K', 'M', 'N'], true), $now), ['checked' => 6, 'missing' => 0, 'oldest_min' => 0]);
$a = L::assess($msgs, ['A' => true], $now);
chk('five never reached ShopBot, the oldest 50 minutes ago', $a, ['checked' => 6, 'missing' => 5, 'oldest_min' => 50]);
chk('…that is late', L::late($a), true);
chk('one missing is not an alarm (a single odd message)', L::late(L::assess([['id' => 'x', 'ts' => $now - 1800]], [], $now)), false);
chk('younger than 10 minutes: may still be on its way, not counted', L::assess([['id' => 'x', 'ts' => $now - 300], ['id' => 'y', 'ts' => $now - 400]], [], $now), ['checked' => 0, 'missing' => 0, 'oldest_min' => 0]);
chk('older than 3 hours: not counted (the line is judged on now)', L::assess([['id' => 'x', 'ts' => $now - 4 * 3600], ['id' => 'y', 'ts' => $now - 5 * 3600]], [], $now)['checked'], 0);
chk('the live case: 7 hours late shows as every recent message missing',
    L::late(L::assess([['id' => 'p', 'ts' => $now - 20 * 60], ['id' => 'q', 'ts' => $now - 90 * 60], ['id' => 'r', 'ts' => $now - 170 * 60]], [], $now)), true);

echo "== what the business reads\n";
$t = L::alertText('DishNet', '+211920000000', ['checked' => 6, 'missing' => 5, 'oldest_min' => 150]);
chk('which line, how many, how long', [str_contains($t, 'DishNet: WhatsApp +211920000000 is receiving messages late'), str_contains($t, '5 customer messages'), str_contains($t, 'waited 2.5 hours')], [true, true, true]);
chk('what to do: restart, then re-link', [str_contains($t, 'restart the line'), str_contains($t, 're-link it'), str_contains($t, 'Linked devices')], [true, true, true]);
chk('minutes when under 2 hours', str_contains(L::alertText('X', 'y', ['missing' => 2, 'oldest_min' => 25]), 'waited 25 minutes'), true);

echo "\n" . ($fail ? "FAILURES" : "ALL PASS") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
