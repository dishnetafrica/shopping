# CloudBSS Kernel — with Postgres persistence + first sales→ledger slice

This repo is the frozen kernel (`src/`) plus an `infra/` adapter layer that makes it durable and
wires the first real business flow. The kernel itself is byte-for-byte unchanged.

## Layout
- `src/`            the frozen domain kernel (event store, ledger, decisions, money, tasks)
- `app/` `modules/` example policies + pluggable modules
- `infra/`          **NEW** — Postgres persistence + sales→ledger use-case (see infra/*.md)
- `tests/`          kernel golden (`run.php`) + freeze gate (`stress.php`)
- `infra/tests/`    persistence + slice proofs (need a Postgres DSN)

## Verify everything
```bash
composer test              # 39 golden assertions (no DB)
composer stress            # freeze gate 18/18 (generate baseline first, below)
# with a Postgres DSN:
CLOUDBSS_PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=cloudbss" composer test-persistence   # 21
CLOUDBSS_PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=cloudbss" composer test-slice          # 20
```

## Freeze baseline (one-time, keep in CI)
`tests/stress.php` compares `src/` against a baseline hash at `/tmp/kernel-frozen.sha256`:
```bash
find src -name "*.php" | sort | xargs sha256sum > /tmp/kernel-frozen.sha256
composer stress   # 18/18 — KERNEL SAFE TO FREEZE
```

## Integrate into ShopBot (Laravel)
See `infra/LARAVEL-INTEGRATION.md` — bind `SalesLedger`, call `recordSale(...)` on order-confirmed.
Design + limits in `infra/PERSISTENCE-NOTES.md`.
