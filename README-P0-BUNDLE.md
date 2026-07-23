# CloudBSS — P0 Foundation bundle (combined)

Everything from this session, at real repo paths. Unzip into the **repo root**; files land where they belong.
Then follow **`OPERATING-MODEL/P0-DEPLOY-SEQUENCE.md`** for the deploy order and what to verify between each.

## What's inside (8 increments)

1. **Tenant isolation test** — `tests/TestCase.php` (restored so the suite runs), `tests/Unit/TenantIsolationTest.php`, `tests/Feature/TenantIsolationDbTest.php` · guide: `DEPLOY-TENANT-ISOLATION-TEST.txt`
2. **Tenant scope batch 1** — 13 models scoped (`app/Models/*`) · guide: `DEPLOY-TENANT-SCOPE-BATCH-1.txt`
3. **Tenant scope batch 2** — 3 models + 6 command patches + `app/Support/BotMiss.php` · guide: `DEPLOY-TENANT-SCOPE-BATCH-2.txt`
4. **Stock ledger tooling** — `app/Console/Commands/StockReconcile.php`, `StockBackfillCommand.php` · guide: `DEPLOY-STOCK-LEDGER-ENABLE.txt`
5. **DB backup + restore** — `app/Console/Commands/DbBackup.php`, `Dockerfile` (+pg_dump) · guide: `DEPLOY-DB-BACKUP.txt`
6. **Offline queue fix** — `resources/views/shell.blade.php` · guide: `DEPLOY-OFFLINE-QUEUE-FIX.txt`
7. **RBAC deny-by-default (dormant)** — `app/Support/Roles.php`, `app/Http/Middleware/PermissionGate.php`, `config/permissions.php` · design: `OPERATING-MODEL/RBAC-DESIGN-v1.md` · guide: `DEPLOY-RBAC-PHASE0.txt`
8. **Error alerting** — `config/errors.php`, `app/Support/ErrorNotifier.php`, `bootstrap/app.php` · guide: `DEPLOY-ERROR-ALERTS.txt`

Governing docs: `OPERATING-MODEL/BUILD-CONSTITUTION-v1.0.md` · `OPERATING-MODEL/P0-DEPLOY-SEQUENCE.md`

## Safety
- Behaviour-changing (run `php artisan test` after): batches 1 & 2, offline fix (client-side).
- Additive / dormant / tooling-only (safe anytime): isolation test, stock ledger, DB backup, RBAC (ships inert), error alerting (log-only until a webhook is set).
- No migrations. Money-path models (CustomerPayment, OrderCost, Ledger*) and the accounting engine were deliberately NOT changed — held for a test-gated batch + your §4a sign-off.

## After deploying + a green suite
Money-five scoping and the accounting §4a migration are the next steps — both waiting on that green `php artisan test` and your decision.
