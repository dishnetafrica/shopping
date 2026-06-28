# OKE Drop 1 — Architecture Review (checkpoint before Drop 2)

Honest review of the domain-free engine against the agreed checklist. Verdict at the end.
Pure + static checks were run in-sandbox; DB-backed invariants are written as Feature tests
that run under `php artisan test` with a test database.

## A. Verification checklist
| Item | Verdict | Evidence |
|---|---|---|
| DB schema correct | ✅ | 6 migrations; facts carry `version`/`is_current`/`supersedes_id`; `operational_state` separate from facts; tenant-scoped. |
| Capability Registry has no domain knowledge | ✅ | `CapabilityRegistry` is a pure string→capability map; static scan asserts the whole engine namespace references no app classes. |
| Versioning append-only | ✅ (code-enforced) | `BusinessMemory` supersedes, never overwrites/deletes; `FactVersioning` rules; **see Finding 1**. |
| Queue collapse behaviour | ✅ | `KnowledgeQueue::collapse` last-write-wins per `(action_type,target)`; Tea 5000/5500/6000 → one. |
| Projections outside the engine | ✅ | Engine depends only on the `Projector` interface; concrete projections live in apps (Drop 2). |
| `MerchantAssistant` not prematurely coupled | ✅ | Engine has zero reference to `MerchantAssistant`/`BotBrain` (static scan). Wiring is Drop 2. |

## B. Architecture tests added (protect the architecture, not behaviour)
In `tests/Unit/Knowledge/ArchitectureTest.php` (pure/static — run anywhere) and
`tests/Feature/Knowledge/EnginePipelineTest.php` (DB-backed):
1. New capability registers with **zero engine changes**.
2. A capability may use an intent string **not in the core `Intent` enum** ("greeting") and still route.
3. **Two capabilities** can claim the same intent without engine edits (first wins).
4. Engine namespace contains **no application/domain class references** (static scan).
5. Facts are **append-only** (versioning rules + DB proof: v1 retained, unchanged value writes nothing, exactly one `is_current`).
6. **Only `BusinessMemory` writes facts** (static scan of `app/`).
7. A **projector failure** marks the action rejected but **never loses** the event or action (DB proof).
8. An **unknown capability** is handled gracefully — event preserved, no exception (DB proof).
9. **One message → multiple facts AND multiple actions** (`ExtractionResult`).

## C. Extensibility proof
`tests/Support/Knowledge/TestCapability.php` — a throwaway capability handling a brand-new intent
("greeting") + `Note`, with its own `TestProjector` ("test_projection"). It is registered and
exercised in the tests **without editing a single engine file**, demonstrating real (not
theoretical) genericness.

## D. Checkpoint questions
- **Is the engine completely domain-agnostic?** Yes — static scan (test 4) + the registry being a pure map; engine prose scrubbed of example domains.
- **Can a new capability be added without modifying the engine?** Yes — tests 1–3 and the TestCapability prove it.
- **Are facts truly immutable and versioned?** Append-only by design; `BusinessMemory` is the sole writer (tests 5–6). *See Finding 1 for the DB-level hardening I recommend.*
- **Are projections owned exclusively by capabilities?** Yes — engine knows only the `Projector` interface.
- **Can the same event generate multiple facts and multiple actions?** Yes — test 9.
- **Does every event remain recoverable even if projection/app fails?** Yes — event is persisted at capture, before anything fallible; tests 7–8.

## E. Findings (candid — issues I'd raise on my own code)
1. **Append-only is code-enforced, not DB-enforced.** `BusinessMemory` is the only writer and a
   static test guards that, but nothing physically blocks a stray future `UPDATE`. **Recommended
   for Drop 2:** a Postgres *partial unique index* `(tenant_id,capability,fact_type,key) WHERE
   is_current` so two current versions are impossible at the DB, and keep the static guard.
2. **`operational_state` vs the existing `DailyState`.** Both model "today" state → split-brain risk.
   **Decision needed in Drop 2:** pick one source-of-truth per concern (likely migrate DailyState's
   sold-out/hours into `operational_state` so there is one store), or clearly partition them.
3. **Single-intent routing.** The engine routes one classified intent to one capability. A message
   spanning *two* capabilities (e.g. a price change + a menu change) is handled by Drop 2's
   per-clause extraction within a capability; truly cross-capability multi-intent is Phase-3
   TaskDetect. Not a defect — a known boundary to keep in mind.
4. **Classifier is an interface only.** The deterministic implementation lands in Drop 2; the engine
   stays generic by construction. (Status, not a defect.)
5. **`is_current` race.** Mitigated now via `lockForUpdate` + transaction in `BusinessMemory`;
   Finding 1's partial index would make it impossible rather than merely unlikely.

## F. Verdict
**Approved to proceed to Drop 2.** All six checkpoint questions pass with evidence; the engine is
provably domain-agnostic and extensible. Carry Findings 1 and 2 into Drop 2 (partial unique index +
resolve operational-state/DailyState ownership) — both are small and best done while wiring the
first real capabilities.
