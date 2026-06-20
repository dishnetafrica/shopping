<?php
/**
 * qa/replay_csv.php — framework-free tests for the bot:replay pure core.
 * Run:  php qa/replay_csv.php
 *
 * Covers ChatReplayCsv (header/positional detection, direction normalisation, quoted
 * commas + newlines, BOM, blank skipping, inbound grouping) and the BotMiss capture sink.
 * No Laravel, no vendor — only the two pure classes are required directly.
 */

require __DIR__ . '/../app/Support/ChatReplayCsv.php';
require __DIR__ . '/../app/Support/BotMiss.php';

use App\Support\ChatReplayCsv;
use App\Support\BotMiss;

$pass = 0; $fail = 0; $fails = [];
function check(string $name, $got, $want) {
    global $pass, $fail, $fails;
    $ok = $got === $want;
    if ($ok) { $pass++; }
    else {
        $fail++;
        $fails[] = "  ✗ {$name}\n      got : " . json_encode($got) . "\n      want: " . json_encode($want);
    }
}

/* ---- 1. Header row, columns in a non-standard order ---- */
$csv = "conversation_id,direction,timestamp,text\n"
     . "+256700111222,in,2026-06-19 10:01,kaju 1kg\n"
     . "+256700111222,out,2026-06-19 10:01,Added cashew 1kg\n";
$p = ChatReplayCsv::parse($csv);
check('header: row count', count($p), 2);
check('header: maps cid', $p[0]['cid'], '+256700111222');
check('header: maps text by name not position', $p[0]['text'], 'kaju 1kg');
check('header: maps ts', $p[0]['ts'], '2026-06-19 10:01');
check('header: dir in', $p[0]['dir'], 'in');
check('header: dir out', $p[1]['dir'], 'out');

/* ---- 2. Headerless positional CSV ---- */
$csv = "2026-06-19 11:00,conv-7,inbound,badam 250gm\n"
     . "2026-06-19 11:00,conv-7,outbound,Added almond 250g\n";
$p = ChatReplayCsv::parse($csv);
check('positional: cid', $p[0]['cid'], 'conv-7');
check('positional: text', $p[0]['text'], 'badam 250gm');
check('positional: dir inbound->in', $p[0]['dir'], 'in');
check('positional: dir outbound->out', $p[1]['dir'], 'out');

/* ---- 3. Direction synonyms ---- */
check('dir received', ChatReplayCsv::normaliseDir('Received'), 'in');
check('dir customer', ChatReplayCsv::normaliseDir('customer'), 'in');
check('dir arrow in', ChatReplayCsv::normaliseDir('→'), 'in');
check('dir sent', ChatReplayCsv::normaliseDir('Sent'), 'out');
check('dir bot', ChatReplayCsv::normaliseDir('bot'), 'out');
check('dir verbose inbound', ChatReplayCsv::normaliseDir('Inbound message'), 'in');
check('dir unknown', ChatReplayCsv::normaliseDir('weird'), '?');

/* ---- 4. Quoted field with embedded comma ---- */
$csv = "timestamp,conversation_id,direction,text\n"
     . "t1,c1,in,\"1kg sugar, 2 packet sev, oil\"\n";
$p = ChatReplayCsv::parse($csv);
check('quoted comma preserved', $p[0]['text'], '1kg sugar, 2 packet sev, oil');

/* ---- 5. Quoted field with embedded newline ---- */
$csv = "timestamp,conversation_id,direction,text\n"
     . "t1,c1,in,\"line one\nline two\"\n"
     . "t2,c1,in,bas\n";
$p = ChatReplayCsv::parse($csv);
check('embedded newline: count', count($p), 2);
check('embedded newline: text', $p[0]['text'], "line one\nline two");
check('embedded newline: next row intact', $p[1]['text'], 'bas');

/* ---- 6. Blank-text rows dropped ---- */
$csv = "timestamp,conversation_id,direction,text\n"
     . "t1,c1,in,kaju\n"
     . "t2,c1,in,   \n"          // whitespace only -> dropped
     . "t3,c1,out,\n";           // empty -> dropped
$p = ChatReplayCsv::parse($csv);
check('blank rows dropped', count($p), 1);
check('blank rows: kept the real one', $p[0]['text'], 'kaju');

/* ---- 7. BOM on first cell tolerated, header still detected ---- */
$csv = "\xEF\xBB\xBFtimestamp,conversation_id,direction,text\n"
     . "t1,c9,in,pista\n";
$p = ChatReplayCsv::parse($csv);
check('BOM: row count', count($p), 1);
check('BOM: cid clean', $p[0]['cid'], 'c9');
check('BOM: text clean', $p[0]['text'], 'pista');

/* ---- 8. inboundByConversation: groups + drops out, preserves order ---- */
$csv = "timestamp,conversation_id,direction,text\n"
     . "t1,A,in,hi\n"
     . "t2,A,out,hello\n"
     . "t3,A,in,kaju 1kg\n"
     . "t4,B,in,thali 2\n";
$p = ChatReplayCsv::parse($csv);
$g = ChatReplayCsv::inboundByConversation($p);
check('group: conversations', array_keys($g), ['A', 'B']);
check('group: A inbound count (out dropped)', count($g['A']), 2);
check('group: A order preserved', array_map(fn($r) => $r['text'], $g['A']), ['hi', 'kaju 1kg']);
check('group: B inbound count', count($g['B']), 1);

/* ---- 9. Empty input ---- */
check('empty string', ChatReplayCsv::parse(''), []);
check('whitespace only', ChatReplayCsv::parse("\n  \n"), []);

/* ---- 10. BotMiss capture sink ---- */
check('capture off by default', BotMiss::isCapturing(), false);
BotMiss::startCapture();
check('capture on after start', BotMiss::isCapturing(), true);
check('capture count starts 0', BotMiss::capturedCount(), 0);
BotMiss::record(2, '  Switch  ');          // trims + lowercases
BotMiss::record(2, 'travel dot com', 'travel dot com pls');
BotMiss::record(2, '');                     // empty -> ignored
BotMiss::record(2, str_repeat('x', 130));   // too long -> ignored
check('capture count after 2 valid', BotMiss::capturedCount(), 2);
$cap = BotMiss::captured();
check('capture: normalised term', $cap[0]['term'], 'switch');
check('capture: sample carried', $cap[1]['sample'], 'travel dot com pls');
BotMiss::stopCapture();
check('capture off after stop', BotMiss::isCapturing(), false);
check('captured cleared after stop', BotMiss::capturedCount(), 0);

/* ---- report ---- */
echo "\n";
foreach ($fails as $f) echo $f . "\n";
echo "\n" . ($fail === 0 ? "ALL GREEN" : "FAILURES") . ": {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
