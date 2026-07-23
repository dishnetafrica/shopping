# P0 Deploy Sequence — this session's bundles

Deploy in this order. "Behaviour change?" tells you whether to run the suite + smoke after; the rest are
additive/dormant and safe to ship anytime. All bundles are independent unless a dependency is noted.

| # | Bundle | Behaviour change? | Deploy notes / verify | Depends on |
|---|--------|-------------------|-----------------------|------------|
| 1 | **tenant-isolation-P0** (`DEPLOY-TENANT-ISOLATION-TEST.txt`) | No (tests only) | Restores `tests/TestCase.php` so the suite runs. `php artisan test --filter=TenantIsolation` → green. | — |
| 2 | **tenant-scope-batch1** | Yes — 13 models auto-scoped | After deploy: `php artisan test`; smoke tenant 17 Suppliers / Demand Plan / invoice items. | #1 (for the test net) |
| 3 | **tenant-scope-batch2** | Yes — 3 models + command ctx | `php artisan test`; then `sourcing:remind --dry`, `relationship:plan --tenant=17`, `bot:misses`. | #2 |
| 4 | **stock-ledger-P0** | No (tooling only) | `php artisan list \| grep stock:`. Then the STAGED enable on tenant 17 per its runbook (reconcile → backfill → flip both flags). | — |
| 5 | **db-backup-P0** | No (additive + Dockerfile) | Rebuild installs `pg_dump`. `php artisan db:backup --verify`. Schedule daily. | — |
| 6 | **offline-queue-fix** | Low (client-side) | `php artisan view:clear` (Blade). Smoke: offline collect → reject → "needs attention" → Retry/Discard. | — |
| 7 | **rbac-phase0** | No (ships dormant) | Ships inert. Activation (wire middleware → `RBAC_MODE=report` → `enforce`) is a deliberate later step per its runbook. | — |

**Suggested path:** 1 → 2 → 3 (the isolation chain, with a `php artisan test` after 2 and 3), then 4, 5, 6, 7 in any order.

## After the queue is green — the held work (needs you first)

- **Money-five scoping** (`CustomerPayment`, `OrderCost`, `Ledger*`) — same pattern as batch 2, but held until the suite is confirmed green, because they feed cash + the GL. Isolation debt is 24 → 8; these are 5 of the remaining 8 (the other 3 are platform-global by design).
- **Accounting §4a** — your sign-off on "keep `LedgerService` as the one GL, add VAT, retire the kernel" unblocks the migration spec, then the migration (with a test run).
- **RBAC rollout** — Phase 0 wiring (dormant) → `report` (observe 1–2 wks) → `enforce`.
- **Error tracking (Sentry)** — the last untouched P0 line; needs a composer package + DSN (an env/infra step).

## P0 scorecard

| Item | State |
|---|---|
| Tenant isolation test | ✅ shipped + guarding |
| Scope leaking models | 🟡 16/24 done (debt 24→8); money five held; 3 platform-global |
| Backups + tested restore | ✅ shipped |
| Offline: never lose a failed sync | ✅ shipped |
| Stock ledger = one truth | 🟡 tooling ready; enable is the staged run |
| RBAC / deny-by-default | 🟡 designed + dormant code; activation is phased |
| Accounting → one engine | ⬜ awaiting §4a sign-off |
| Error tracking | ⬜ not started |
