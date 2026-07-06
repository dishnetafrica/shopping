# CloudBSS Kernel — refactored spine (one write model, event-first)

Framework-agnostic domain layer (PSR-4 `CloudBSS\Kernel\`), drops into Laravel unchanged. Run the
golden tests with no dependencies:

```bash
php tests/run.php      # 39 assertions, all green — includes replay/reconstruction proofs
```

## What the refactor changed (and what it preserved)

**Preserved:** `Money`, `Decision`, `Participant` (Party | Digital), `Task`, the `*Policy`
interfaces, the package layout, and public method names. Only the architectural spine changed.

**One write model.** There is exactly one source of truth — the `EventStore`. The ledger no longer
keeps its own entries; it is a **projection** of `JournalPosted` events:

```
command → Decision (the why) → Event → EventStore → EventBus → Projections
business event → [resolved AccountingPolicy] → JournalPosted → LedgerProjection → balances
```

| Step (your roadmap) | Where |
|---|---|
| 1. One write model (events) | `EventStore` is the only write model; `Kernel::recordEvent()` the only entry |
| 2. Ledger is a projection | `LedgerProjection` folds `JournalPosted`; `rebuildFrom()` replays the log |
| 3. Event metadata | `DomainEvent` carries `schemaVersion`, `correlationId`, `causationId`; store assigns `aggregateSequence` + `globalPosition` with optimistic concurrency |
| 4. Injected clock + ids | `Clock` / `IdGenerator` (System/Frozen, Random/Sequence) → deterministic replay |
| 5. Kernel independent of Laravel + tenants | tenant/entity are event **metadata**; `AccountingPolicyResolver` (app-supplied) picks policy per event; no framework imports |
| 6. Migrate one capability at a time | modules subscribe on the `EventBus`; the kernel never changes to admit a new consumer |

## Event-sourced vs normal aggregates (your calibration)

- **Event-sourced** (replay/audit critical): the Ledger (via `JournalPosted`), Decisions (via
  `DecisionMade`), and `Task` (raise → apply → `fromHistory`).
- **Normal aggregates**: `Party`, `DigitalParticipant`, and future Product/Supplier/Customer/
  Settings — ordinary objects, not event-sourced.

## Reconstructability — every audited object rebuilds from events alone

Proven by tests:
- **Ledger** → `LedgerProjection::rebuildFrom(log)` reproduces every balance.
- **Task** → `Task::fromHistory(events)` reproduces status + owner.
- **Decision** → the full why (policy **version** + parameters + structured inputs + evidence refs)
  is in the `DecisionMade` event, explainable even after a policy changes.
- **Money** → overflow now **throws** instead of silently becoming a float; the no-float guarantee
  is真.
- **Determinism** → `FrozenClock` + `SequenceIdGenerator` make replay bit-for-bit identical.

## What this deliberately does NOT include yet

Persistence (the event store + projections against Postgres), FX with realized gain/loss, the
Conversation object, AI Workforce routing, and the first business module. Each new capability
should pass the simulator's 14 scenarios as end-to-end tests before shipping.

## C3 note (Money at extreme scale)

Money uses overflow-**checked** 64-bit integers: safe to ~9.2×10¹⁸ minor units and throws beyond
that rather than corrupting. If a currency/volume ever needs more, swap the internal primitive for
bcmath/gmp — the public API does not change.
