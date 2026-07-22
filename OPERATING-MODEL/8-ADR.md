# 8 — Architecture Decision Records (ADR)

**Status:** the "why" behind the freeze. Each ADR is immutable once Accepted; a change of mind is a
*new* ADR that supersedes an old one (never an edit). Format: Context · Decision · Consequences.

---

### ADR‑001 — Business Objects are the source of truth (not event‑sourced)
**Status:** Accepted.
**Context:** we need history, timelines, and offline sync without the complexity of replaying events to
know current state.
**Decision:** each object stores its own current state (as today's Laravel models do); events are the
append‑only *history* of what happened to it.
**Consequences:** simpler, offline‑friendly, no replay engine; events are still authoritative for
history/analytics/AI. (Supersedes the earlier "events at the center" sketch.)

### ADR‑002 — Event‑logged with a unified, append‑only Event Store
**Status:** Accepted.
**Context:** several per‑domain event tables already exist (`bot_events`, `activity_feed`,
`product_events`, `shipment_events`…).
**Decision:** converge on one unified `events` table with a shared envelope (subject, actor, payload,
idempotency_key); migrate per‑domain tables into it over time.
**Consequences:** one query powers every timeline/projection; retention kept forever.

### ADR‑003 — Capability‑first architecture
**Status:** Accepted.
**Context:** modules and even objects are less stable than what the company *does*.
**Decision:** organize the system as Mission → Capabilities → Processes → Rules → Objects → Events →
Services → Projections → AI. Capabilities are the top‑level architecture + desktop navigation + the
governance anchor.
**Consequences:** stable spine; features must map to a capability. *Phone nav remains a role‑aware
curation, not eight capability tabs* (one‑thumb constraint).

### ADR‑004 — Currency policy: USD base, local for local
**Status:** Accepted.
**Context:** repeated currency defects (UGX shown as USD, $0.27 orders, inflated invoices).
**Decision:** base currency USD; Uganda purchases in UGX; South Sudan expenses in SSP; every document
carries its *true* transaction currency + a USD‑normalized value; conversion happens only at
display/settlement boundaries, never silently in storage; USD‑base shops are always native.
**Consequences:** the whole class of currency bugs becomes structurally impossible. (Root of the fixes
already shipped.)

### ADR‑005 — Never hard‑delete financial or customer‑facing objects
**Status:** Accepted.
**Decision:** correct via void/reverse/credit‑note/archive; keep the record with a reason and audit
entry.
**Consequences:** full auditability; already how cashbook voids and invoice normalization behave.

### ADR‑006 — AI assists; it never owns the workflow or invents facts
**Status:** Accepted.
**Decision:** AI may explain, prioritize, and draft the step a process already determined, and may
phrase real computed figures — it may not advance state or produce a money number.
**Consequences:** the business runs fully with AI (and network) unavailable; trust is preserved.
(Codifies the bot anti‑hallucination guardrails.)

### ADR‑007 — Single PWA codebase; thin Android wrapper
**Status:** Accepted.
**Decision:** one Progressive Web App is the product on Android/iPhone/desktop; the native wrapper holds
no business logic — only device bridges (push, camera, GPS, biometric, files, print, share, bg‑sync).
**Consequences:** features ship without store updates; the wrapper changes only for new device
capabilities.

### ADR‑008 — Overlay / strangler migration; no big‑bang rewrite
**Status:** Accepted.
**Context:** a live business shipping weekly.
**Decision:** adopt the operating model by naming + unifying what already exists (observers, jobs,
event tables, capabilities), one process at a time.
**Consequences:** continuous delivery; no freeze of the business itself, only of the architecture.

### ADR‑009 — Capabilities evolve the existing Laravel capability layer
**Status:** Accepted.
**Decision:** verb‑capabilities are the existing `ProcessCapability` orchestrating `ObjectCapability`;
the workspace API and projection layer are reused, not replaced.
**Consequences:** the strongest existing asset is kept and elevated.

### ADR‑010 — Offline‑first with invisible sync
**Status:** Accepted.
**Decision:** field writes queue locally with client id + idempotency key and sync automatically; the
only offline UI is a per‑record status badge; conflicts resolve per each object's merge rule.
**Consequences:** no lost work, no "Sync" button; correctness handled by idempotency + merge rules.

### ADR‑011 — Governance: no feature without a model mapping
**Status:** Accepted.
**Decision:** every new feature must declare its capability, process, objects, rules, events, and
projections before implementation (the checklist in doc 0).
**Consequences:** prevents architectural drift; lightweight (a PR checklist), not bureaucratic.

---

### ADR-012 — Legacy events converge on the unified spine (no second event system)
**Status:** Accepted.
**Context:** V3 already has per-domain event tables (bot_events, activity_feed, product_events,
shipment_events, owner_activity_log, order_draft_events…). Adding `bos_events` risks two event worlds.
**Decision:** `bos_events` is canonical. NEW features emit ONLY via `Bos::emit()`. Legacy tables
migrate incrementally, per capability, through a single `LegacyEventBridge` (forward on write, backfill
on read); events are never created twice. A domain's legacy table is frozen once fully forwarded.
**Consequences:** one spine via strangler; the bridge is the only bridging point, deletable table by table.

### ADR-013 — The Rule Engine is the mandatory path for business logic
**Status:** Accepted.
**Decision:** Business rules are defined + evaluated ONLY in `App\BOS\Rules`, enforced at call sites via
`Bos::enforce(R#, …)`. **No developer may write business logic outside the Rule Engine without an
approved ADR.** A fitness test blocks inline rule predicates in new BOS code; legacy inline logic
migrates as each capability is touched.
**Consequences:** logic stops leaking into controllers/services; every rule has one home + a named test.

### ADR-014 — Exactly four canonical projection types
**Status:** Accepted.
**Decision:** The only projection TYPES are **Timeline, Activity, Today, Metrics.** Concrete projections
extend one of these bases; a fifth type requires an ADR. A fitness test enforces the cap.
**Consequences:** almost every screen is one of four shapes; no projection sprawl.

### ADR-015 — Every event is instrumented from day one
**Status:** Accepted.
**Decision:** Each event auto-records correlation id (groups one action), causation id (which event
caused it), device, connectivity (online/offline), app version, latency, and extensible context —
alongside actor/tenant/timestamps.
**Consequences:** field issues are debuggable after the fact; analytics + sync diagnostics come free.
