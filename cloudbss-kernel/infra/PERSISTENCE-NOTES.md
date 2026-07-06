# CloudBSS Kernel — Postgres persistence adapter

Turns the kernel from an in-memory engine into one that survives restarts, **without editing a
single byte of `kernel/src`**. Proven end-to-end against real Postgres (`infra/tests/persistence_test.php`,
21 assertions green) and the freeze gate still passes (`tests/stress.php` 18/18, `tests/run.php` 39/39).

## What's here
```
infra/
  Postgres/
    schema.sql            durable append-only `events` table (+ indexes, per-aggregate uniqueness)
    PostgresEventLog.php   PDO adapter: append() / load() / since() / forAggregate() / versionOf() / count()
  Persistence.php          wiring: persistOnPublish() (durable write) + rehydrate() (replay on boot)
  tests/persistence_test.php   real-Postgres proof: write → persist → restart → identical balances
```

## The design, and why it's shaped this way

The Constitution (rule 4) says persistence sits behind interfaces the kernel defines — but in the
current code `EventStore` is a `final` concrete class and `Kernel` type-hints it directly. So we
**cannot** substitute a Postgres store into the kernel today without editing `src/` (which the
freeze forbids). Rather than break the freeze, this adapter uses the two public seams the kernel
already exposes:

- **WRITE** — every event the kernel publishes on the `EventBus` is appended to the durable log
  (`Persistence::persistOnPublish`). The kernel doesn't know or care.
- **BOOT** — a fresh process replays the durable log into a new in-memory `EventStore` via its
  public `append()` (`Persistence::rehydrate`). `append()` rebuilds `global_position` and the
  per-aggregate sequence **without publishing**, so the kernel's auto-posting reactor does **not**
  re-fire — no double-posting. Projections then rebuild from the now-complete store.

### Boot order (important)
```php
$store  = new EventStore();  $bus = new EventBus();
$ledger = new LedgerProjection();  $bus->subscribe(Posting::EVENT, [$ledger,'on']);
$kernel = new Kernel($store, $bus, $clock, new RandomIdGenerator(), $resolver);

$log = new PostgresEventLog($pdo);  $log->migrate();
Persistence::rehydrate($store, $log);          // 1) replay history into the store (no publish)
$ledger->rebuildFrom($store->all());           // 2) rebuild balances from full history
Persistence::persistOnPublish($bus, $log);     // 3) ONLY now attach durable-write (don't re-write history)
// 4) go live — new commands persist + project + continue the position sequence
```

### Durability model
Wrap each command in a PDO transaction:
```php
$pdo->beginTransaction();
$kernel->recordEvent($businessEvent);   // in-memory append + publish → persist subscriber writes rows
$pdo->commit();
```
In request-scoped PHP this is clean: if the request dies, the transaction rolls back and the
discarded in-memory store never diverges from the committed log — the next request rehydrates from
what actually committed.

**Use `RandomIdGenerator` in production, not `SequenceIdGenerator`.** The sequence generator resets
each boot, so its ids collide across restarts; random ids are globally unique. (Replay stays
deterministic regardless, because rehydrate replays the *stored* events with their original ids —
it never regenerates them.)

## The one limitation — and the proper fix (an ADR)

This adapter is correct for a **single writer per aggregate** (which request-scoped PHP is). For
**multiple concurrent writer processes**, the in-memory store assigns `global_position`, so two
processes could pick the same position. The storage layer *detects* the clash (the
`UNIQUE(aggregate_id, aggregate_sequence)` constraint throws), but the truly correct fix is to make
Postgres the ordering authority.

That means the kernel should own an **`EventStore` interface** (rule 4's original intent), with the
current class becoming `InMemoryEventStore implements EventStore` and a new `PostgresEventStore
implements EventStore` that the `Kernel` accepts. That is a deliberate kernel evolution — exactly
what the Constitution's ADR gate (rules 12–14) exists for: extract the interface, keep the public
methods identical (backward compatible → a MINOR bump), ship both implementations. Recommended as
the next kernel ADR once you want multi-process writers. Until then, this adapter is the
freeze-respecting way to run in production.

## Run the test
```bash
# against your Postgres:
CLOUDBSS_PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=cloudbss" \
CLOUDBSS_PG_USER=postgres CLOUDBSS_PG_PASS=secret \
php infra/tests/persistence_test.php
```

## Freeze baseline
The stress gate compares `kernel/src` against a baseline hash. Generate it once and keep it in CI:
```bash
find src -name "*.php" | sort | xargs sha256sum > /tmp/kernel-frozen.sha256
php tests/stress.php   # 18/18 — KERNEL SAFE TO FREEZE
```

## Next step (the recommendation)
With durability in place, build the **first real vertical slice**: a confirmed ShopBot/Patronics
sale → `SaleRecorded`/`DeliveryCompleted` event → `UgandaVatSalesPolicy` (already in the kernel) →
`JournalPosted` → ledger. That gives Patronics a real, rebuildable, audit-perfect book you can
reconcile — the smallest proof that the kernel earns its place next to DishNet Finance OMEGA.
