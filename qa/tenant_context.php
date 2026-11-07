<?php declare(strict_types=1);
// Pure test of App\Support\TenantContext — the two independent axes (tenant id, super-admin
// bypass) and the clear() vs reset() contract. Framework-free; runs in the sandbox. WS-3.
require __DIR__ . '/../app/Support/TenantContext.php';

use App\Support\TenantContext;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . var_export($g, true) . " want " . var_export($w, true) . "\n"; }
}

$c = new TenantContext();
chk('fresh: id null', $c->id(), null);
chk('fresh: not super-admin', $c->isSuperAdmin(), false);

$c->set(7);
chk('set: id = 7', $c->id(), 7);
chk('set: has() true', $c->has(), true);
$c->asSuperAdmin(true);
chk('asSuperAdmin: on', $c->isSuperAdmin(), true);

// clear() is tenant-id-only BY CONTRACT — it must NOT touch the super-admin flag.
$c->clear();
chk('clear: id null', $c->id(), null);
chk('clear: super-admin UNCHANGED (still true)', $c->isSuperAdmin(), true);

// reset() clears BOTH axes — the boundary reset WS-3 adds.
$c->set(9); $c->asSuperAdmin(true);
$c->reset();
chk('reset: id null', $c->id(), null);
chk('reset: super-admin false', $c->isSuperAdmin(), false);
chk('reset: has() false', $c->has(), false);

// Boundary simulation: previous job left tenant 5 + super-admin; the Queue::before hook calls
// reset(); the next job that forgets to set() must see a clean, empty slate (NOT tenant 5).
$c->set(5); $c->asSuperAdmin(true);
$c->reset();
chk('boundary: next unit sees no tenant', $c->id(), null);
chk('boundary: next unit sees no super-admin bypass', $c->isSuperAdmin(), false);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
