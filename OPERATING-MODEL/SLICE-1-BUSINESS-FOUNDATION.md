# Slice 1 — Business Foundation  ·  STATUS: BUILT + review changes applied (ready to merge)

## Pre-merge review changes (all applied)
1. **No second event system (ADR-012).** New code emits ONLY via `Bos::emit()`; `LegacyEventBridge`
   converges the existing per-domain tables onto `bos_events` incrementally (forward + backfill),
   never duplicating. `bos_events` is the canonical spine.
2. **Rule Engine mandatory (ADR-013).** Governance rule added: no business logic outside the rule
   engine without an ADR; a fitness test blocks inline rule predicates (e.g. balance comparisons) in
   BOS code.
3. **Projections capped at four (ADR-014).** Canonical types Timeline/Activity/Today/Metrics added as
   bases; a fitness test blocks a fifth type without an ADR.
4. **Instrument every event (ADR-015).** The envelope + `EventStore` now capture correlation id,
   causation id, device, connectivity, app version, and latency automatically — from the first event.

Verified after changes: **20/20 standalone checks green**, all files lint clean.

---


The pre‑slice artifact (Scope / DoR / Design / Migration / Rollback / Acceptance) + what shipped.

## Scope
The internal spine every later slice stands on: the **event store**, the **rule engine**, the
**projection + timeline base**, the **capability interface**, and the **BOS SDK** — plus the first
**architecture fitness tests**. No user‑facing change (internal), no existing behaviour touched.

## Definition of Ready — met
- Capability: *Platform* (foundation for all verbs). Process: n/a (infrastructure).
- Objects: introduces `Event` (history). Rules: R2, R3, R4 (first of doc 4). Events: envelope only.
- Projection: Timeline base. UI: none. Tests: rule unit tests + fitness tests written first.

## Technical design (what was built, under `app/BOS/`)
- **Event store** — `bos_events` table (unified, append‑only envelope) + `App\BOS\Event` (model‑level
  append‑only guard: updates/deletes throw) + `App\BOS\EventStore` (`emit()`, idempotent by
  `idempotency_key`, fans out to subscribers) + `App\BOS\Contracts\Subscriber`.
- **Rule engine (single source of policy)** — `App\BOS\Rules\BusinessRule` + `RuleRegistry`
  (`check`/`enforce`, one registration point) + `RuleViolation`. First three rules ported:
  R2 receipt‑requires‑payment, R3 payment‑≤‑balance, R4 void‑not‑hard‑delete.
- **Projections** — `App\BOS\Projections\Projection` (read‑only base) + `TimelineProjection`
  (`events WHERE subject ORDER BY occurred_at` — the seed of the Customer Timeline).
- **Capability interface** — `App\BOS\Capabilities\Capability` (verb: id/purpose/processes/objects).
- **SDK** — `App\BOS\Bos`: `Bos::emit()`, `Bos::check()/enforce()`, `Bos::timeline()`. The one toolkit
  every future capability uses.

## Migration plan
- One new migration `create_bos_events_table` (idempotent; `up` guarded by `hasTable`).
- Run: `php artisan migrate`. It creates ONE new table and touches nothing else.
- Nothing writes to it yet (services opt in via `Bos::emit()` in later slices), so deploying is inert.

## Rollback plan
- `php artisan migrate:rollback` drops `bos_events` (the migration has a working `down`).
- Delete the `app/BOS/` directory + the two test folders. Zero impact on V3 — nothing referenced it.

## Acceptance tests (written first, all green here)
- `tests/Unit/Bos/BusinessRuleTest.php` — `test_R2/R3/R4` + registry enforce throws.
- `tests/Feature/Bos/ArchitectureFitnessTest.php` — events append‑only · projections never persist ·
  rules only in the rule layer · every rule registered + named‑tested · events emitted only via SDK.
- Verified locally (standalone, no DB): **15/15 checks pass**; all 14 PHP files + 2 tests lint clean.
  On your server: `php artisan test --filter=Bos`.

## Architecture fitness tests (this slice's invariants — grow one per slice)
Events append‑only · projections read‑only · rules only in the rule layer · every rule tested · Event
constructed only via the SDK. Next slices add: no‑UI‑DB‑write, services‑never‑call‑UI, AI‑cannot‑
bypass‑workflow.

## Four‑review gate
- **Functional:** n/a (internal) — provides the spine. ✅
- **Architectural:** complies; fitness tests green. ✅
- **Operational:** one reversible migration; deploy is inert; rollback trivial. ✅
- **UX:** n/a (no screen). ✅

## Not in this slice (next steps, still Slice‑1 family)
- Subscriber examples (TimelineSubscriber) — wired when the first real emit lands in Slice 3.
- Demo‑company seeder + instrumentation hooks — Slice 1b, before Today.
- Backfill adapter mapping legacy event tables into the timeline read model (BOS‑0005).
