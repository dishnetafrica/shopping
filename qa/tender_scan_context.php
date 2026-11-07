<?php
/**
 * Pure harness — TendersScanCommand per-tenant context lifecycle (runs in the sandbox).
 *
 * Models the control flow of TendersScanCommand::handle(): set($tenant->id) at the top of each
 * iteration, clear() in a finally. Proves the three exit paths of a loop body all leave a clean
 * slate for the next tenant: normal completion, `continue` (tender disabled), and a thrown
 * exception inside scan(). It also models the BelongsToTenant scope arithmetic to show WHY the
 * fix is needed: a read with no context collapses to `tenant_id IS NULL` → zero rows.
 *
 * This does NOT boot Laravel. The Eloquent/DB proof (sync() returns the real count only with
 * context, dedup is tenant-scoped, rows are isolated per tenant) is tests/Feature/TenderScanContextTest.php.
 */

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

/** Minimal stand-in for App\Support\TenantContext (tenant-id axis — the one this path uses). */
final class Ctx {
    private ?int $tenantId = null;
    public function set(?int $id): void { $this->tenantId = $id; }
    public function clear(): void { $this->tenantId = null; }
    public function id(): ?int { return $this->tenantId; }
}

/**
 * The scope arithmetic: a tenant-scoped read matches only rows whose tenant_id equals the active
 * context id; with no context the predicate is `tenant_id IS NULL`, which no real row satisfies.
 */
function scopedRowsVisible(Ctx $ctx, int $rowTenantId): bool {
    $active = $ctx->id();
    return $active !== null && $active === $rowTenantId;
}

/** The exact loop body of TendersScanCommand::handle(), parameterised by what scan() does. */
function runIteration(Ctx $ctx, int $tenantId, string $mode, array &$observed): void {
    $ctx->set($tenantId);
    try {
        if ($mode === 'disabled') {
            return;                       // the `continue` path (tender_enabled false)
        }
        // scan() runs here with context live — record what a scoped read would see.
        $observed['contextDuringScan'] = $ctx->id();
        $observed['ownRowsVisible'] = scopedRowsVisible($ctx, $tenantId);
        if ($mode === 'throw') {
            throw new RuntimeException('scan blew up');
        }
    } catch (\Throwable $e) {
        $observed['caught'] = $e->getMessage();
    } finally {
        $ctx->clear();                    // never leak this tenant's id into the next iteration
    }
}

$ctx = new Ctx();

// 1 — WHY the fix is needed: a context-less scoped read sees nothing.
$ctx->clear();
check('no context: a scoped read of tenant 5\'s rows is INVISIBLE (the bug)', scopedRowsVisible($ctx, 5) === false);

// 2 — with context, the tenant's own rows are visible; another tenant's are not.
$ctx->set(5);
check('context=5: tenant 5 rows visible', scopedRowsVisible($ctx, 5) === true);
check('context=5: tenant 9 rows NOT visible (isolation)', scopedRowsVisible($ctx, 9) === false);
$ctx->clear();

// 3 — normal iteration: context is live during scan, cleared afterwards.
$obs = [];
runIteration($ctx, 7, 'ok', $obs);
check('normal: context was 7 during scan', ($obs['contextDuringScan'] ?? null) === 7);
check('normal: tenant 7 rows were visible during scan', ($obs['ownRowsVisible'] ?? false) === true);
check('normal: context cleared after iteration', $ctx->id() === null);

// 4 — disabled tenant (continue path) still clears.
$ctx->set(111);           // simulate a stale value from a prior unit of work
$obs = [];
runIteration($ctx, 8, 'disabled', $obs);
check('continue path: context cleared after iteration', $ctx->id() === null);

// 5 — scan throws: the finally still clears (exception cannot leave stale context).
$obs = [];
runIteration($ctx, 12, 'throw', $obs);
check('exception: scan ran under context 12', ($obs['contextDuringScan'] ?? null) === 12);
check('exception: was caught (command logs + continues)', ($obs['caught'] ?? '') === 'scan blew up');
check('exception: context cleared after iteration', $ctx->id() === null);

// 6 — a full multi-tenant sweep never leaks one tenant's id into the next.
$leaked = false;
foreach ([[3,'ok'],[4,'disabled'],[5,'throw'],[6,'ok']] as [$tid, $mode]) {
    check("sweep: clean slate before tenant {$tid}", $ctx->id() === null) ?: null;
    if ($ctx->id() !== null) { $leaked = true; }
    $obs = [];
    runIteration($ctx, $tid, $mode, $obs);
}
check('sweep: no id leaked across the whole run', $leaked === false && $ctx->id() === null);

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
