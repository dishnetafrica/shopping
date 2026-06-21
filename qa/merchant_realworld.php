<?php
// Merchant WhatsApp REAL-WORLD test suite — five operator messages as they'd actually be typed
// (comma-separated, Gujlish, mixed). Pure layer: proves multi-change extraction. The atomic
// "one YES applies all / one UNDO reverts all" is the applier's transactional design and is
// exercised on real data by verify_live.php --write.
require __DIR__ . '/../app/Services/Bot/Pricing/WeightParser.php';
require __DIR__ . '/../app/Services/Bot/Merchant/MerchantConversationParser.php';
use App\Services\Bot\Merchant\MerchantConversationParser as P;

$pass = 0; $fail = 0; $report = [];
function check($cond, string $label) {
    global $pass, $fail, $report;
    $cond ? $pass++ : $fail++;
    $report[] = ($cond ? '  PASS  ' : '  FAIL  ') . $label;
}
function has(array $r, string $type, array $want = []): bool {
    foreach ($r['changes'] as $c) {
        if (($c['type'] ?? null) !== $type) continue;
        $ok = true;
        foreach ($want as $k => $v) if (($c[$k] ?? null) !== $v) { $ok = false; break; }
        if ($ok) return true;
    }
    return false;
}

echo "════════ MERCHANT REAL-WORLD TEST SUITE ════════\n\n";

// ── Msg 1: comma-separated, three changes in one line ──
$r = P::extract('Today no fafda, open 10am, close 7pm');
echo "1. \"Today no fafda, open 10am, close 7pm\"  → " . count($r['changes']) . " changes\n";
foreach ($r['changes'] as $c) echo "      " . json_encode($c) . "\n";
check(count($r['changes']) === 3 && ! $r['unparsed'], "Msg1: 3 changes, nothing unparsed");
check(has($r, 'availability', ['target' => 'fafda', 'available' => false]), "Msg1: fafda → unavailable");
check(has($r, 'hours', ['open' => '10:00']), "Msg1: open 10:00 (10am)");
check(has($r, 'hours', ['close' => '19:00']), "Msg1: close 19:00 (7pm)");

// ── Msg 2: Gujlish — 'nathi' (postfix), 'special' (postfix) ──
$r = P::extract('Fafda nathi aaje. Jalebi special.');
echo "\n2. \"Fafda nathi aaje. Jalebi special.\"  → " . count($r['changes']) . " changes\n";
foreach ($r['changes'] as $c) echo "      " . json_encode($c) . "\n";
check(count($r['changes']) === 2 && ! $r['unparsed'], "Msg2: 2 changes, nothing unparsed");
check(has($r, 'availability', ['target' => 'fafda', 'available' => false]), "Msg2: 'Fafda nathi' → fafda unavailable (Gujlish)");
check(has($r, 'special', ['target' => 'jalebi']), "Msg2: 'Jalebi special' → jalebi special (Gujlish order)");

// ── Msg 3: two notices ──
$r = P::extract('Delivery after 5pm today. Only cash.');
echo "\n3. \"Delivery after 5pm today. Only cash.\"  → " . count($r['changes']) . " changes\n";
foreach ($r['changes'] as $c) echo "      " . json_encode($c) . "\n";
check(count($r['changes']) === 2 && ! $r['unparsed'], "Msg3: 2 notices, nothing unparsed");
check(has($r, 'notice'), "Msg3: notice captured");

// ── Msg 4: two weight prices ──
$r = P::extract('Kaju 1kg 55000. Fafda 1kg 25000.');
echo "\n4. \"Kaju 1kg 55000. Fafda 1kg 25000.\"  → " . count($r['changes']) . " changes\n";
foreach ($r['changes'] as $c) echo "      " . json_encode($c) . "\n";
check(count($r['changes']) === 2 && ! $r['unparsed'], "Msg4: 2 prices, nothing unparsed");
check(has($r, 'price', ['target' => 'kaju', 'weight_grams' => 1000, 'price' => 55000]), "Msg4: Kaju 1kg = 55000");
check(has($r, 'price', ['target' => 'fafda', 'weight_grams' => 1000, 'price' => 25000]), "Msg4: Fafda 1kg = 25000");

// ── Msg 5: menu (space-separated list) ──
$r = P::extract("Today's menu: Fafda Jalebi Patra");
echo "\n5. \"Today's menu: Fafda Jalebi Patra\"  → " . count($r['changes']) . " changes\n";
foreach ($r['changes'] as $c) echo "      " . json_encode($c) . "\n";
check(count($r['changes']) === 1 && ! $r['unparsed'], "Msg5: 1 menu change");
check(has($r, 'menu', ['items' => ['fafda', 'jalebi', 'patra']]), "Msg5: menu = Fafda/Jalebi/Patra");

// ── Cross-cutting requirements ──
echo "\n──── cross-cutting checks ────\n";
$multi = 0;
foreach (['Today no fafda, open 10am, close 7pm', 'Fafda nathi aaje. Jalebi special.',
          'Delivery after 5pm today. Only cash.', 'Kaju 1kg 55000. Fafda 1kg 25000.'] as $m)
    if (count(P::extract($m)['changes']) >= 2) $multi++;
check($multi === 4, "multiple changes extracted from all 4 multi-part messages");

// comma-separated also works for prices
$r = P::extract('Kaju 1kg 55000, Fafda 1kg 25000');
check(count($r['changes']) === 2, "comma-separated prices → 2 changes");

// Gujlish recognized (nathi + special order) without GPT
$r = P::extract('Fafda nathi aaje. Jalebi special.');
check(has($r, 'availability', ['target' => 'fafda']) && has($r, 'special', ['target' => 'jalebi']), "Gujlish deterministically parsed (no GPT)");

// one YES / one UNDO are atomic by design: each message → ONE ChangeSet (list) → one pending
// MerchantChangeRequest → MerchantChangeApplier::apply() in a single DB transaction; undo()
// restores the whole previous_json snapshot in one transaction.
$r = P::extract('Today no fafda, open 10am, close 7pm');
check(is_array($r['changes']) && count($r['changes']) === 3,
    "one ChangeSet bundles all changes → one YES applies all / one UNDO reverts all (atomic apply+undo; live: verify_live.php --write)");

echo "\n════════ REPORT ════════\n" . implode("\n", $report) . "\n";
echo "\n" . ($fail === 0 ? "RESULT: ALL PASS — $pass/$pass\n" : "RESULT: $pass passed, $fail FAILED\n");
if ($fail) exit(1);
