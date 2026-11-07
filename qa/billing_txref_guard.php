<?php declare(strict_types=1);
// Pure test of the P0 WS-1c tx_ref -> tenant guard logic (same regex as
// BillingController::txRefMatchesTenant). Framework-free; runs in the sandbox.
function txRefMatchesTenant(string $txRef, int $tenantId): bool {
    if (! preg_match('/^CB-(\d+)-/', $txRef, $m)) return true; // unknown format (e.g. Stripe id)
    return (int) $m[1] === $tenantId;
}
$pass=0;$fail=0;
function chk($l,$g,$w){global $pass,$fail; if($g===$w){$pass++;echo "  [PASS] $l\n";}else{$fail++;echo "  [FAIL] $l — got ".var_export($g,true)." want ".var_export($w,true)."\n";}}

chk('matching tenant (CB-12-ABCD, 12)',            txRefMatchesTenant('CB-12-ABCD1234', 12), true);
chk('MISMATCH: row tenant 99 but ref says 12',     txRefMatchesTenant('CB-12-ABCD1234', 99), false);
chk('MISMATCH: forged ref for another tenant',     txRefMatchesTenant('CB-7-ZZZZ', 42), false);
chk('stripe-style ref (no CB-) -> not blocked',    txRefMatchesTenant('cs_test_a1b2c3', 42), true);
chk('empty ref -> not blocked here',               txRefMatchesTenant('', 42), true);
chk('matching large id',                           txRefMatchesTenant('CB-100500-QWER', 100500), true);

echo "\n".($fail===0?"ALL PASS: $pass passed, 0 failed\n":"FAILURES: $fail failed, $pass passed\n");
exit($fail?1:0);
