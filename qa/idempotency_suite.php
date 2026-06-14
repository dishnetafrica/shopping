<?php
// Proves the production-safety LOGIC deterministically. The DB unique index is
// simulated by a FakeUniqueStore whose insertOrIgnore() mirrors Postgres:
// inserting a duplicate unique tuple affects 0 rows. The real key helpers are used.

require dirname(__DIR__) . '/app/Support/Idempotency.php';

use App\Support\Idempotency;

/** Mimics a table with a unique constraint + insertOrIgnore (returns rows inserted: 1 or 0). */
class FakeUniqueStore
{
    private array $rows = [];
    public function __construct(private array $uniqueCols) {}
    private function key(array $row): string { return implode('|', array_map(fn ($c) => (string) ($row[$c] ?? ''), $this->uniqueCols)); }
    public function insertOrIgnore(array $row): int
    {
        $k = $this->key($row);
        if (isset($this->rows[$k])) return 0;       // unique violation -> ignored
        $this->rows[$k] = $row;
        return 1;
    }
    public function count(): int { return count($this->rows); }
    public function all(): array { return array_values($this->rows); }
}

$pass = 0; $fail = 0; $fails = [];
function ck(string $l, bool $ok) { global $pass,$fail,$fails; if ($ok){$pass++; echo "  PASS  $l\n";} else {$fail++; $fails[]=$l; echo "  FAIL  $l\n";} }

echo "\n========= PRODUCTION SAFETY — IDEMPOTENCY LOGIC =========\n";

// ---- Criterion 1: duplicate WhatsApp messages cannot create duplicate work ----
echo "\n[2A] Message dedup\n";
$receipts = new FakeUniqueStore(['tenant_id', 'whatsapp_message_id']);
$claim = fn ($t, $conv, $mid) => $receipts->insertOrIgnore(['tenant_id'=>$t,'conversation_id'=>$conv,'whatsapp_message_id'=>$mid]) > 0;

$first  = $claim(1, 10, 'wamid.AAA');   // first delivery
$dup    = $claim(1, 10, 'wamid.AAA');   // WhatsApp/worker retry — same id
ck('first delivery is processed', $first === true);
ck('duplicate delivery is skipped (not processed again)', $dup === false);
ck('only one receipt stored', $receipts->count() === 1);
// different message id from same conversation still processes
ck('a genuinely new message still processes', $claim(1, 10, 'wamid.BBB') === true);
// same id under a DIFFERENT tenant is independent (tenant isolation)
ck('same id under another tenant is independent', $claim(2, 99, 'wamid.AAA') === true);

// simulate 1000 duplicate deliveries of one id -> exactly one processed
$store2 = new FakeUniqueStore(['tenant_id', 'whatsapp_message_id']);
$processed = 0;
for ($i = 0; $i < 1000; $i++) {
    if ($store2->insertOrIgnore(['tenant_id'=>1,'whatsapp_message_id'=>'wamid.FLOOD']) > 0) $processed++;
}
ck('1000 duplicate deliveries -> processed exactly once', $processed === 1 && $store2->count() === 1);

// ---- Criterion 2: duplicate checkout cannot create duplicate orders ----
echo "\n[2C] Order idempotency\n";
$orders = new FakeUniqueStore(['idempotency_key']);
$placeOrder = function (int $t, int $conv, string $token) use ($orders): bool {
    $key = Idempotency::orderKey($t, $conv, $token);
    return $orders->insertOrIgnore(['idempotency_key'=>$key]) > 0;   // true = order created
};
$token = 'checkout-uuid-123';
$o1 = $placeOrder(1, 10, $token);   // customer sends location
$o2 = $placeOrder(1, 10, $token);   // double-press / WhatsApp retry / worker retry (same checkout)
ck('first checkout creates an order', $o1 === true);
ck('repeat of the SAME checkout creates NO new order', $o2 === false);
ck('exactly one order exists', $orders->count() === 1);
// a NEW checkout (new token) is a legitimately new order
$o3 = $placeOrder(1, 10, 'checkout-uuid-456');
ck('a new checkout (new token) creates a new order', $o3 === true && $orders->count() === 2);
// key is stable + unique
ck('order key stable across retries', Idempotency::orderKey(1,10,$token) === Idempotency::orderKey(1,10,$token));
ck('order key differs per tenant/conversation/token',
    Idempotency::orderKey(1,10,$token) !== Idempotency::orderKey(2,10,$token) &&
    Idempotency::orderKey(1,10,$token) !== Idempotency::orderKey(1,11,$token));

// ---- Criterion 4: campaign retries cannot send duplicates ----
echo "\n[2D] Campaign idempotency\n";
$camp = new FakeUniqueStore(['campaign_id', 'recipient']);
$claimRcpt = fn ($cid, $phone) => $camp->insertOrIgnore(['campaign_id'=>$cid,'recipient'=>Idempotency::recipient($phone)]) > 0;
$audience = ['256700111222', '256700333444', '256700111222']; // note duplicate in audience
$sent = 0;
foreach ($audience as $p) if ($claimRcpt(7, $p)) $sent++;
ck('duplicate recipient in audience is sent once', $sent === 2 && $camp->count() === 2);
// job restart: re-run whole audience -> nobody re-sent
$resent = 0;
foreach ($audience as $p) if ($claimRcpt(7, $p)) $resent++;
ck('job restart re-sends to nobody', $resent === 0 && $camp->count() === 2);
// phone normalisation: "+256 700-111-222" == "256700111222"
ck('recipient normalisation dedups formatting', Idempotency::recipient('+256 700-111-222') === Idempotency::recipient('256700111222'));
ck('already-claimed normalised recipient is skipped', $claimRcpt(7, '+256 700-111-222') === false);

// ---- Criterion 5: per-conversation lock keys ----
echo "\n[2B] Conversation lock keys\n";
$kA = Idempotency::conversationLock(1, 'inst1', '256700111222');
$kA2 = Idempotency::conversationLock(1, 'inst1', '256700111222');
$kB = Idempotency::conversationLock(1, 'inst1', '256700999888');
$kC = Idempotency::conversationLock(2, 'inst1', '256700111222');
ck('same conversation -> same lock key (serialised)', $kA === $kA2);
ck('different customer -> different lock key (parallel ok)', $kA !== $kB);
ck('different tenant, same number -> different lock key (isolation)', $kA !== $kC);
ck('checkout lock distinct from conversation lock', Idempotency::checkoutLock(1,10) !== $kA);

echo "\n========= RESULT =========\n";
printf("PASS %d   FAIL %d\n", $pass, $fail);
if ($fails) { echo "Fails:\n"; foreach ($fails as $f) echo "  - $f\n"; exit(1); }
echo "ALL GREEN \u{2705}\n";
