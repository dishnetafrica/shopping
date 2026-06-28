# OKE Phase 1 — Drop 1 (reviewed): Complete domain-free engine + schema

The full **generic** Owner Knowledge Engine: capture → classify → route → extract → persist
(append-only) → apply, with the architecture-protecting tests and an extensibility proof.
Drop 2 adds the Core + Daily Menu capabilities and the WhatsApp/bot/storefront wiring.

## Contents
- **6 migrations**: knowledge_events, knowledge_facts (versioned), knowledge_actions (queue),
  owner_profiles, operational_state, daily_menus. **6 models** (all BelongsToTenant).
- **Engine** (`App\Services\Knowledge`): `KnowledgeEngine` (orchestrator), `BusinessMemory`
  (sole append-only fact writer), `CapabilityRegistry`, `KnowledgeQueue` (collapse),
  `OwnerProfileResolver`, `EntityConfidence`, `FactVersioning`, `Source`/`Intent`/`Reason`,
  contracts `Capability`/`Extractor`/`Projector`/`Classifier`, DTOs `Fact`/`ActionRequest`/`ExtractionResult`.
- **Tests**: `Unit/Knowledge/EngineCoreTest` (17), `Unit/Knowledge/ArchitectureTest` (9 architecture
  guards incl. domain-free + append-only static scans), `Feature/Knowledge/EnginePipelineTest`
  (DB invariants: capture-first, projector-failure-preserves-event, unknown-capability-graceful,
  append-only facts), `Support/Knowledge/TestCapability` (extensibility proof).
- **Docs**: `OKE-DROP1-REVIEW.md` (architecture review + findings + verdict).

## Status
- `php -l` clean on all files.
- Pure + static: **26 assertions pass** in-sandbox (17 core + 9 architecture, incl. the
  domain-free and only-BusinessMemory-writes-facts scans).
- DB-backed invariants are Feature tests — run `php artisan test --filter=Knowledge` in the app.

## Deploy
Additive only (no existing table/class changed). Unzip into repo root → `php artisan migrate`
→ `php artisan test --filter=Knowledge`.

## Carry into Drop 2 (from the review)
1. Partial unique index `(tenant_id,capability,fact_type,key) WHERE is_current` to make
   append-only impossible to violate at the DB.
2. Resolve `operational_state` vs existing `DailyState` ownership (one store per concern).
