<?php declare(strict_types=1);
// Pure test of App\Support\TenantContext and the SAVE/RESTORE boundary logic WS-3 uses in
// WhatsAppServiceProvider (snapshot -> reset -> restore). Framework-free; runs in the sandbox.
require __DIR__ . '/../app/Support/TenantContext.php';

use App\Support\TenantContext;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . var_export($g, true) . " want " . var_export($w, true) . "\n"; }
}

$c = new TenantContext();

// --- primitives ---
$c->set(7); $c->asSuperAdmin(true);
chk('set/super', [$c->id(), $c->isSuperAdmin()], [7, true]);
$c->clear();
chk('clear() is tenant-id-only (super still true)', [$c->id(), $c->isSuperAdmin()], [null, true]);
$c->reset();
chk('reset() clears both', [$c->id(), $c->isSuperAdmin()], [null, false]);

// --- the boundary: snapshot -> reset -> (work) -> restore, as the provider does per job/command ---
$stack = [];
$push = function () use ($c, &$stack) { $stack[] = $c->snapshot(); $c->reset(); };
$pop  = function () use ($c, &$stack) { if ($stack !== []) { $c->restore(array_pop($stack)); } };

// 1) Independent/async job: previous left tenant 5; the job must START clean.
$c->set(5);
$push();
chk('async: job starts on a clean slate', [$c->id(), $c->isSuperAdmin()], [null, false]);
$c->set(5);            // the job sets its own tenant, as real jobs do
$pop();
chk('async: boundary balanced afterwards', $c->id(), 5);

// 2) SYNC/inline job: caller is tenant 5 and continues after the inline job; must stay tenant 5.
$c->reset(); $c->set(5);          // request/observer context
$push();                          // Queue::before (inline dispatch)
$c->set(9);                       // inline job does its own work as tenant 9
$pop();                           // Queue::after
chk('sync: caller context PRESERVED after inline job', $c->id(), 5);   // <-- the OrderObserver fix

// 3) Nested inline dispatch (A -> B), stack stays balanced.
$c->reset(); $c->set(5);
$push(); $c->set(11);             // job A
$push(); $c->set(22);             // job B nested in A
$pop();  chk('nested: back to A context', $c->id(), 11);
$pop();  chk('nested: back to caller context', $c->id(), 5);

// 4) Exception path: restore still runs (provider restores on JobExceptionOccurred).
$c->reset(); $c->set(5);
$push(); $c->set(33);
try { throw new \RuntimeException('boom'); } catch (\Throwable $e) { $pop(); }   // failure restore
chk('exception: caller context restored', $c->id(), 5);

// 5) Super-admin never leaks across a boundary.
$c->reset(); $c->asSuperAdmin(true);
$push();
chk('super-admin reset for the unit of work', $c->isSuperAdmin(), false);
$pop();
chk('super-admin restored to caller', $c->isSuperAdmin(), true);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
