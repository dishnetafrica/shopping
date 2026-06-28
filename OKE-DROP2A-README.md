# OKE Phase 1 — Drop 2a: Engine→App seam + Core & Daily Menu capabilities

Builds on Drop 1 (locked engine core). Adds the projection seam, the canonical operational-state
store, the deterministic classifier, the DB append-only invariant, and the first two capabilities.
All additive and unit-testable; the **live-path wiring** (MerchantAssistant/BotBrain/storefront/
`oke:nudge`) is **Drop 2b**.

## Contents
- **Finding 1 fixed (DB invariant):** migration `..._add_current_fact_unique_index` — Postgres
  partial unique index `(tenant_id,capability,fact_type,key) WHERE is_current`. Append-only is now
  impossible to violate at the database, not only in code.
- **ProjectionCoordinator** (+ `ProjectionReport`): single seam between engine and projectors —
  ordered, failure-isolated, idempotent (skips already-applied), future home of retries/rebuilds.
  `KnowledgeEngine` now applies through it.
- **OperationalStateStore:** the canonical generic store for today/dated toggles, separate from
  durable facts. (Finding 2 resolution — see below.)
- **DeterministicClassifier:** text → single intent (Gujlish synonyms); Phase-3 AI implements the
  same `Classifier` contract with zero engine change.
- **Core capability** (`App\Apps\Core`): price (Fact + gated Action), durable policy/facility Facts,
  today/dated operational changes (cash-only-today, closed-tomorrow). Projector writes Product price
  + operational state.
- **Daily Menu capability** (`App\Apps\DailyMenu`): meal blocks, sold-out, specials, "no <meal>";
  projector writes `daily_menus` + operational availability; `TodayMenu` read model (menu minus
  today's sold-outs) for the bot/storefront.
- **KnowledgeView:** customer-facing reads of durable Policy/Facility/Schedule facts.
- **OkeServiceProvider:** the one place capabilities are registered (Core + Daily Menu). Engine
  never changes when adding a capability.

## Finding 2 resolution (operational_state vs DailyState)
`OperationalStateStore` is the long-term concept; **capabilities write only through it**, enforced
by a new architecture test (`Apps must not reference DailyState`). The legacy `DailyState` becomes a
thin facade over this store in **Drop 2b**, where its only callers (the merchant lane) are rewired —
so the two never evolve in parallel.

## Tests
- Pure (run anywhere): `DeterministicClassifierTest`, `CoreExtractorTest`, `MenuExtractorTest`,
  `ProjectionCoordinatorTest` (partial-failure isolation + idempotency), plus the Drop-1
  `ArchitectureTest` gains the Apps-no-DailyState guard.
- **19 pure/static assertions pass in-sandbox; all files `php -l` clean.**
- DB-backed `EnginePipelineTest` updated for the coordinator dependency; runs under `php artisan test`.

## Deploy
Additive only. Unzip → `php artisan migrate` (adds the partial index + tables from Drop 1) →
`php artisan test --filter=Knowledge`. Register `OkeServiceProvider` in Drop 2b.

## Drop 2b (next)
DailyState→facade cutover; `MerchantAssistant` delegates to `KnowledgeEngine` (confirm/undo);
`BotBrain` reads `TodayMenu`+`KnowledgeView`; storefront endpoint; `oke:nudge` command;
end-to-end integration tests.
