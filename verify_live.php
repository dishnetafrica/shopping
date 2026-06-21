<?php
/**
 * LIVE pre-production verification — run INSIDE the container against real data:
 *     php verify_live.php                 (read-only: real prices + parse/auth checks)
 *     php verify_live.php --write          (also runs the merchant apply→undo round-trip)
 *
 * Part A pushes the six weight orders through the REAL bot (BotBrain::respond) on a scratch
 * conversation and reads back the stored cart — proving weight_grams is stored, qty stays 1,
 * and price comes from the real catalogue. The scratch conversation is deleted afterward.
 * Part B verifies merchant ChangeSet composition, self-check, and unauthorized rejection.
 * Part C (only with --write) applies "Today no fafda…", confirms, undoes, and restores state.
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

$SLUG    = getenv('VERIFY_SLUG') ?: 'palssnack';     // Pal's Snacks
$DO_WRITE = in_array('--write', $argv, true);

$tenant = Tenant::where('slug', $SLUG)->first();
if (! $tenant) { fwrite(STDERR, "Tenant '$SLUG' not found\n"); exit(1); }
$bot = app(BotBrain::class);
echo "Tenant: {$tenant->name} (#{$tenant->id})   build: " . BotBrain::VERSION . "\n";

/* ───────────────────────── PART A — weight ordering (REAL catalogue) ───────────────────────── */
echo "\n=== PART A: customer weight ordering ===\n";
$msg = "750g Kaju, 500g Kaju, 250g Kaju, 1.5kg Fafda, 300g Sev, 200 grams Vanela Gathiya";
echo "Parser output per fragment:\n";
foreach (BulkOrderParser::parseAll($msg) as $ln) echo "  " . json_encode($ln) . "\n";

$scratch = Conversation::create([
    'tenant_id' => $tenant->id, 'customer_phone' => 'verify_' . uniqid(),
    'state' => [], 'cart' => [],
]);
$bot->respond($tenant, $scratch, $msg);
$scratch->refresh();
echo "\nStored cart lines (real prices):\n";
$qtyMisuse = false;
foreach ((array) $scratch->cart as $l) {
    $sub = (float) $l['price'] * (int) $l['qty'];
    $w   = $l['weight_grams'] ?? '—';
    echo "  " . str_pad((string) $l['name'], 18) . " weight_grams=" . str_pad((string) $w, 6)
        . " qty={$l['qty']}  price=" . number_format((float) $l['price'])
        . "  line_total=" . number_format($sub) . "\n";
    if (! empty($l['weight_grams']) && (int) $l['qty'] !== 1) $qtyMisuse = true;
}
echo $qtyMisuse ? "  ✗ qty used as multiplier on a weight line!\n" : "  ✓ every weight line has qty=1 (weight_grams carries the amount)\n";
$scratch->delete();

/* ───────────────────────── PART B — merchant mode (parse/auth, read-only) ──────────────────── */
echo "\n=== PART B: merchant mode (read-only checks) ===\n";
$r = MerchantConversationParser::extract('Today no fafda. Open at 10. Close at 7.');
echo "ChangeSet from 'Today no fafda. Open at 10. Close at 7.' → " . count($r['changes']) . " changes (→ ONE pending request):\n";
foreach ($r['changes'] as $c) echo "  " . json_encode($c) . "\n";

$sc = MerchantConversationParser::extract("What is today's menu?");
echo "Self-check 'What is today's menu?' → selfcheck=" . json_encode($sc['selfcheck']) . ", changes=" . count($sc['changes']) . "\n";

$authPhones = MerchantDirectory::authorizedPhones($tenant);
echo "Authorized merchant phones on file: " . (count($authPhones) ? implode(', ', $authPhones) : '(none — set owner_alert_phone or owner/manager users)') . "\n";
echo "  unauthorized 256700111222 → " . (MerchantDirectory::isAuthorized($tenant, '256700111222') ? 'AUTHORIZED (unexpected!)' : 'rejected → customer flow ✓') . "\n";

/* ───────────────────────── PART C — apply → undo round-trip (only with --write) ─────────────── */
if ($DO_WRITE) {
    echo "\n=== PART C: merchant apply → undo round-trip (writes, self-restoring) ===\n";
    $owner = $authPhones[0] ?? null;
    if (! $owner) { echo "  no authorized phone configured — skipping round-trip.\n"; }
    else {
        $before = DailyState::get($tenant);
        $convo = Conversation::firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_phone' => MerchantDirectory::normalize($owner)],
            ['state' => [], 'cart' => []]
        );
        echo "Propose: " . trim($bot->respond($tenant, $convo, 'Today no fafda. Open at 10. Close at 7.')) . "\n";
        $pending = MerchantChangeRequest::where('tenant_id', $tenant->id)->where('status', 'pending')->latest('id')->first();
        echo "  pending request id=" . ($pending->id ?? 'NONE') . "  changes=" . ($pending ? count($pending->payload_json) : 0) . "  (expect 1 request)\n";
        echo "Confirm: " . trim($bot->respond($tenant, $convo, 'YES')) . "\n";
        $after = DailyState::get($tenant);
        echo "  daily_state.hours now: " . json_encode($after['hours']) . "  unavailable: " . json_encode($after['unavailable']) . "\n";
        echo "Undo:    " . trim($bot->respond($tenant, $convo, 'undo last change')) . "\n";
        echo "Confirm: " . trim($bot->respond($tenant, $convo, 'YES')) . "\n";
        $restored = DailyState::get($tenant);
        echo "  daily_state restored == before? " . (json_encode($restored) === json_encode($before) ? 'YES ✓' : 'NO ✗') . "\n";
        echo "Self-check live: " . trim($bot->respond($tenant, $convo, "What is today's menu?")) . "\n";
    }
} else {
    echo "\n(Part C apply→undo round-trip skipped — re-run with --write to exercise it.)\n";
}

echo "\nDone.\n";
