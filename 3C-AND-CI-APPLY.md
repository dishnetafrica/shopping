# 3C (StaffCash cutover) + CI-0 (restore test factories)

Two clean, separate pieces. Commit them as two commits (CI-0 first).

---

## CI-0 — Restore the missing test factories (the big unblock)

**Finding:** `database/factories/` did not exist and the models had no `HasFactory`, yet **65 of ~130
test files call `Tenant::factory()` / `User::factory()`** (124 calls). Every one of those tests errors
at setup — which is why the suite "runs by hand" and why CI can't go green. This restores them.

**Files**
```
app/Models/User.php                    + HasFactory
app/Models/Tenant.php                  + HasFactory
database/factories/UserFactory.php     NEW
database/factories/TenantFactory.php   NEW
```
Only `Tenant` and `User` factories are needed — no test uses any other model factory.

```bash
git add app/Models/User.php app/Models/Tenant.php database/factories
git commit -m "CI-0: restore Tenant/User factories (unblocks 65 tests that call ::factory())"
```

> Honest note: this makes those 65 tests **run**. Some may still reveal *pre-existing* assertion
> failures (the due diligence noted a few tests assert stale things) — those are separate triage, not
> caused by this change. But they can't be seen or fixed until the tests can run at all.

---

## 3C — StaffCash "Receive" records through the one recorder (closes the double-record)

`StaffCashController::receive()` no longer does its own inline `OrderCost` + `CustomerPayment` write.
It now calls `PaymentService::record()` — the single, idempotent recorder — which:
- **closes the due-diligence Fatal double-record** (StaffCash gains the replay guard via `idempotency_key`),
- routes the fact through the `money.receive` capability (so staff receipts finally hit the event spine),
- produces **field-for-field identical** rows to the old write,
- preserves StaffCash's exact `pay_unify` sync gating via the new additive `'sync'` flag (**Option B**).

**Files**
```
app/Services/Money/PaymentService.php              additive 'sync' flag guarding the two sync calls
                                                   (this file also carries the 3B dual-write — cumulative)
app/Http/Controllers/Panel/StaffCashController.php inline write + pay_unify block → one record() call
tests/Feature/Kernel/StaffCashCutoverTest.php      parity + idempotency + pay_unify ON/OFF
```

```bash
git add app/Services/Money/PaymentService.php app/Http/Controllers/Panel/StaffCashController.php \
        tests/Feature/Kernel/StaffCashCutoverTest.php
git commit -m "3C: StaffCash records via the one money.receive path (closes double-record; pay_unify preserved)"
```

### Why 3C is parity-safe
- The old inline `OrderCost`/`CustomerPayment` fields match `PaymentService`'s exactly (same `describeNote`,
  same stages) — so the rows are unchanged.
- `'sync' => pay_unify` means the existing **`StaffReceiveSyncTest`** passes both ways (ON reduces the
  invoice, OFF leaves it) — and it can finally run thanks to CI-0.
- New, additive: staff receipts now emit `PaymentReceived` (they emitted nothing before) and get
  idempotency. Both are improvements, not regressions.

## Verify (CI — needs a DB; does not run in the doc environment)
```bash
php artisan test tests/Feature/Kernel/StaffCashCutoverTest.php   # 3C
php artisan test tests/Feature/StaffReceiveSyncTest.php          # existing parity guard (now runnable)
php artisan test                                                 # full suite
```

## Rollback
Revert the 3C commit → StaffCash returns to its inline write. Revert CI-0 independently if needed
(factories are additive; reverting only re-breaks the 65 factory tests).

## Deferred (separate later ticket — do NOT bundle)
**3D:** make R3 *fatal* at this front door ("book the excess as an advance"). That changes behaviour for
over-balance settlements, so it stays out of this behaviour-preserving cutover.
