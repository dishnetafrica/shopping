# OKE Phase 1 — Drop 1: Engine Core + Schema (domain-free)

Implements the v4-locked Owner Knowledge Engine **core**: the part that is generic, pure,
and unit-testable independent of any application. Drop 2 adds the Core + Daily Menu
capabilities, engine stage-wiring, the `oke:nudge` command, and bot/storefront reads.

## What's in Drop 1
**Schema (6 migrations)** — `knowledge_events` (raw memory + source + intent),
`knowledge_facts` (durable, **versioned**, never overwritten), `knowledge_actions`
(the queue + change-request traceability + per-entity confidence), `owner_profiles`,
`operational_state` (today/dated toggles, kept separate from facts), `daily_menus`
(Daily Menu app projection).

**Models (6)** — all `BelongsToTenant` (auto tenant scope + stamp).

**Engine core (`App\Services\Knowledge`)**
- `Source`, `Intent`, `Reason` — sources (WhatsApp = just the first adapter) and first-class intent/why.
- `Contracts\Capability` / `Extractor` / `Projector` — the registry contract; engine knows no domain.
- `CapabilityRegistry` — apps register capabilities; engine routes by intent.
- `Dto\Fact` / `ActionRequest` / `ExtractionResult` — one message → facts **and** actions.
- `KnowledgeQueue` — Phase-1 collapse (Tea 5000/5500/6000 → one action).
- `OwnerProfileResolver` — "same as yesterday" = "repeat" = "no changes".
- `EntityConfidence` — per-entity confidence + weakest-link rollup.
- `FactVersioning` — append-only version rules (never overwrite).

**Tests** — `tests/Unit/Knowledge/EngineCoreTest.php` (17 assertions): registry routing,
queue collapse, owner aliases, per-entity confidence, fact versioning, extraction merge.

## Status
- `php -l` clean on all 26 files.
- Pure core: **17/17** assertions pass (sandbox harness; run `php artisan test --filter=EngineCoreTest` in the app).

## Deploy
Additive only — no changes to existing tables/classes. Unzip into repo root, run
`php artisan migrate`, then `php artisan test --filter=EngineCoreTest`. Drop 2 wires this
core into the WhatsApp lane.

## Next (Drop 2)
Core + Daily Menu `Capability` (extractors + projectors), `KnowledgeEngine` stage pipeline,
`BusinessMemory` (versioned fact persistence), `Timeline` read model, `oke:nudge` command,
`MerchantAssistant` delegation, `BotBrain`/storefront reads.
