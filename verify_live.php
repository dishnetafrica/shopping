<?php
/**
 * PAL'S SNACKS PILOT — live verification (build 2026.06.21-mw2). Run INSIDE the container:
 *     php verify_live.php           read-only: customer cart prices + merchant ChangeSets
 *     php verify_live.php --write    also runs the apply → undo round-trip (self-restoring)
 *
 * Matches the approved verification matrix exactly:
 *   Customer: 750g kaju · 500g kaju · 250g kaju · 1.5kg fafda
 *   Merchant: 4 ChangeSet messages + apply/undo round-trip on message 1.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Models\Conversation;
use App\Models\MerchantChangeRequest;
use App\Services\Bot\BotBrain;
use App\Services\Bot\BulkOrderParser;
use App\Services\Bot\Merchant\MerchantConversationParser;
use App\Services\Bot\Merchant\MerchantDirectory;
use App\Services\Bot\Merchant\DailyState;

$SLUG     = getenv('VERIFY_SLUG') ?: 'palssnack';
$DO_WRITE = in_array('--write', $argv, true);
$pass = 0; $fail = 0;
function chk(&$pass, &$fail, $c, $l) { $c ? $pass++ : $fail++; echo '   [' . ($c ? 'PASS' : 'FAIL') . "] $l\n"; }

$tenant = Tenant::where('slug', $SLUG)->first();
if (! $tenant) { fwrite(STDERR, "Tenant '$SLUG' not found\n"); exit(1); }
$bot = app(BotBrain::class);
echo "Tenant: {$tenant->name} (#{$tenant->id})   build: " . BotBrain::VERSION . "\n";
chk($pass, $fail, BotBrain::VERSION === '2026.06.21-mw2', "version == 2026.06.21-mw2");

/* ───────────── CUSTOMER: weight ordering (real catalogue) ───────────── */
echo "\n=== CUSTOMER WEIGHT TESTS ===\n";
$msg = '750g kaju, 500g kaju, 250g kaju, 1.5kg fafda';
echo "Message: \"$msg\"\nParser:\n";
foreach (BulkOrderParser::parseAll($msg) as $ln) echo "   " . json_encode($ln) . "\n";

$scratch = Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => 'verify_' . uniqid(), 'state' => [], 'cart' => []]);
$bot->respond($tenant, $scratch, $msg);
$scratch->refresh();
echo "Stored cart (real prices):\n";
$weightLines = 0; $qtyMisuse = false;
foreach ((array) $scratch->cart as $l) {
    $sub = (float) $l['price'] * (int) $l['qty'];
    echo "   " . str_pad((string) $l['name'], 16) . " weight_grams=" . str_pad((string) ($l['weight_grams'] ?? '—'), 6)
        . " qty={$l['qty']}  price=" . number_format((float) $l['price']) . "  total=" . number_format($sub) . "\n";
    if (! empty($l['weight_grams'])) { $weightLines++; if ((int) $l['qty'] !== 1) $qtyMisuse = true; }
}
$scratch->delete();
chk($pass, $fail, $weightLines >= 1, "weight lines created from real catalogue");
chk($pass, $fail, ! $qtyMisuse, "every weight line qty=1 (weight_grams carries amount)");

/* ───────────── MERCHANT: ChangeSet creation (read-only) ───────────── */
echo "\n=== MERCHANT CHANGESET TESTS ===\n";
$mtests = [
    'Today no fafda, open 10am, close 7pm',
    'Fafda nathi aaje, Jalebi special',
    'Delivery after 5pm today, Only cash',
    'Kaju 1kg 55000, Fafda 1kg 25000',
];
foreach ($mtests as $i => $t) {
    $r = MerchantConversationParser::extract($t);
    echo ($i + 1) . ". \"$t\" → " . count($r['changes']) . " changes, " . count($r['unparsed']) . " unparsed\n";
    foreach ($r['changes'] as $c) echo "      " . json_encode($c) . "\n";
    chk($pass, $fail, count($r['changes']) >= 2 && ! $r['unparsed'], "  msg " . ($i + 1) . ": multiple changes, zero unparsed (one ChangeSet)");
}

/* ───────────── APPLY → UNDO round-trip (only with --write) ───────────── */
if ($DO_WRITE) {
    echo "\n=== APPLY → UNDO ROUND-TRIP ===\n";
    $authPhones = MerchantDirectory::authorizedPhones($tenant);
    $owner = $authPhones[0] ?? null;
    if (! $owner) { echo "   no authorized merchant phone configured — set owner/manager or owner_alert_phone, then re-run.\n"; chk($pass, $fail, false, "authorized merchant phone present"); }
    else {
        $before = DailyState::get($tenant);
        $convo = Conversation::firstOrCreate(['tenant_id' => $tenant->id, 'customer_phone' => MerchantDirectory::normalize($owner)], ['state' => [], 'cart' => []]);
        echo "Propose: " . trim($bot->respond($tenant, $convo, 'Today no fafda, open 10am, close 7pm')) . "\n";
        $pending = MerchantChangeRequest::where('tenant_id', $tenant->id)->where('status', 'pending')->latest('id')->first();
        chk($pass, $fail, $pending && count($pending->payload_json) === 3, "one pending request bundling 3 changes");
        echo "Confirm: " . trim($bot->respond($tenant, $convo, 'YES')) . "\n";
        $after = DailyState::get($tenant);
        chk($pass, $fail, ($after['hours']['open'] ?? null) === '10:00' && ($after['hours']['close'] ?? null) === '19:00', "apply: hours set 10:00–19:00");
        echo "Undo:    " . trim($bot->respond($tenant, $convo, 'undo last change')) . "\n";
        echo "Confirm: " . trim($bot->respond($tenant, $convo, 'YES')) . "\n";
        $restored = DailyState::get($tenant);
        chk($pass, $fail, json_encode($restored) === json_encode($before), "undo: daily_state restored == before");
    }
} else {
    echo "\n(apply→undo round-trip skipped — re-run with --write)\n";
}

echo "\n──────── RESULT ────────\n" . ($fail === 0 ? "✅ ALL PASS — $pass/$pass\n" : "❌ $pass passed, $fail FAILED\n");
exit($fail ? 1 : 0);
