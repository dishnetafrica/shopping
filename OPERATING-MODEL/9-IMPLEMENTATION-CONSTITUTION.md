# 9 — Implementation Constitution (ShopBot BOS v1.0)

**Status:** FROZEN with the architecture. This governs *how code gets written* from now on.

> The architecture is frozen. **Do not redesign it. Do not introduce parallel architectures. Do not
> build shortcuts that violate the operating model.** Every implementation follows this constitution.

## Mission (why we build)
ShopBot is a **Business Operating System for trading companies**, not an ERP. The system exists to
help people *perform business work*, not manage database tables. The UI must feel like an operating
system for work, not an accounting package.

---

## The Golden Rule — never build screens first
Every feature is implemented in this exact order. **If a step is skipped, stop.**
```
1. Capability   2. Process   3. Business Rules   4. Business Objects
5. Events       6. Services   7. Projection       8. UI
```

## Definition of Ready — the pre‑code mapping (no code before this is approved)
Before writing code for any feature, produce and get approval on this one‑page map:
```
Feature:            <name>
Capability:         which of Sell/Buy/Move/Collect/Support/Market/Manage/Analyze
Process:            which process it extends (or a new one, justified)
Business Objects:   which objects it creates/changes (owner capability)
Business Rules:     which rules (R#) it must obey; any new rule (+ its test)
Events:             which events it emits (existing names, or new + payload)
Services:           which Laravel services it uses/adds (reuse first)
Projection:         which projection it feeds/changes
UI:                 which screen (always a projection; mobile-first)
Tests:              unit (rules), integration (events), e2e (one-thumb path)
Migration impact:   schema/data changes; backfill; reversible?
```
No mapping → not ready → no code.

---

## Standing rules (enforced, not optional)

**Source of truth.** Business Objects hold current state (Customer, Quote, Order, Invoice, Payment,
Shipment, Receipt, Expense, Visit, Task). **Never derive current state by replaying events.** (ADR‑001)

**Events.** Append‑only. Never update, never delete. Events are historical facts; they never own
business state. Every mutation emits its event (doc 5). (ADR‑002)

**Business Rules.** Mandatory, and **defined in one place only** — never duplicated across screens or
services. Every rule (doc 4, R1–R26) has an automated test named for it (`test_R3_…`). A rule change
is a doc‑4 amendment + a new ADR. **Governance (ADR-013): no developer may write business logic
outside the Rule Engine without an approved ADR** — a fitness test blocks inline rule predicates.

**Projections.** Only four canonical TYPES — **Timeline · Activity · Today · Metrics** (ADR-014).
A fifth type needs an ADR; almost every screen is one of these four shapes.

**One event spine (ADR-012).** New features emit ONLY via `Bos::emit()`; legacy event tables converge
onto `bos_events` incrementally via `LegacyEventBridge` — never a second event system. Every event is
instrumented from day one (ADR-015): correlation/causation ids, device, connectivity, app version, latency.

**AI.** AI never owns a workflow, never changes data directly, never invents numbers. AI may only
*explain, prioritize, draft, summarize, recommend* — and every AI statement originates from real
business data. (ADR‑006)

**Offline.** Every workflow works offline. Objects created offline get a UUID + idempotency key; sync
is idempotent (no duplicate documents); the user never presses "Sync". (ADR‑010)

**Mobile.** Every workflow is one‑handed, ≤ 3 taps for common actions, large touch targets, and works
on poor connectivity. Desktop is simply a wider layout of the same PWA. (doc 7)

**Overlay, not rewrite.** Build on the existing services, observers, capability layer, and event
tables. No parallel architecture. (ADR‑008/009)

---

## Development order (implement in this sequence)
- **Phase 0 — Foundations & guardrails:** unified event store + migration, offline sync engine, PWA
  app‑shell caching, rule‑test harness, feature flags. *(prerequisite for everything)*
- **Phase 1 — Business Foundation:** Capability Registry, Business Rule Engine, Event Bus, Projection
  Framework, Timeline Engine, Activity Engine. *No UI redesign beyond what's required.*
- **Phase 2 — Today Workspace:** Today, Activities, Smart Priorities, Customer Timeline, context‑aware
  FAB. *No reports, no dashboards.*
- **Phase 3 — Sell:** Lead → Visit → Quote → Negotiate → Order → Deliver → Invoice → Payment → Receipt
  → Relationship. *End‑to‑end before moving on.*
- **Phase 4 — Collect** · **Phase 5 — Buy** · **Phase 6 — Inventory (offline/barcode)** ·
  **Phase 7 — Finance** · **Phase 8 — Analytics + AI.**

Complete each phase (to its Definition of Done) before starting the next.

---

## Coding standard — every pull request answers
```
Which Capability?   Which Process?     Which Business Objects?   Which Business Rules?
Which Events?       Which Services?    Which Projection?         Which Tests?
```
**If any answer is missing, do not merge.** (This is the governance rule from doc 0, made mechanical.)

## Definition of Done (per task)
- Rules touched have passing named tests · events emitted and subscribed · projection updates from
  events (not ad‑hoc writes) · works offline where the flow is field‑facing · one‑thumb path verified
  · reversible (rollback noted) · PR checklist complete.

## Success criteria (the whole program is "done" when)
- A salesperson can complete an entire day's work from a phone.
- The business keeps operating **without AI**.
- The business keeps operating **without internet**.
- Every document belongs to a business process.
- Every business rule has automated tests.
- Every user screen is a projection.
- AI only narrates and assists using real business facts.

---

## Execution strategy (v1.1 — governance additions)

**Work in vertical slices, not isolated tasks.** AI agents (and humans) lose context across dozens of
tiny tasks. Each *slice* delivers a deployable, reversible unit with measurable user value where
possible. Before each slice, produce: **Scope · Definition of Ready · Technical design · Migration
plan · Rollback plan · Acceptance tests.** Slice order (Timeline before Today — everything builds on
the timeline: ADR-014 family):
1. **Business Foundation** (internal — DONE) · 1.5 **Demo Company + instrumentation** ·
2. **Customer Timeline** (the root projection) · 3. **Today** (grouped timeline/activities) ·
4. **Quote→Order→Invoice→Payment→Receipt** · 5. **Offline sync** for that workflow · 6. **Android
wrapper**. Then Collections/Buy/Inventory/Finance/Analytics. **Make the sales journey excellent before
expanding.**

**Architecture fitness tests run in CI** (not code‑review suggestions). They fail the build on drift:
no UI writes the DB directly · rules only in the rule layer · projections never mutate objects · AI
cannot bypass workflows · services never call UI · events are append‑only · financial records are
voided not deleted. Scope grows one invariant per slice.

**Demo company.** Maintain one realistic seed dataset (≈100 customers, 500 products, 300 invoices,
100 payments, stock/deliveries/returns). Every feature is validated against it — UI, performance,
training, demos, regression.

**Instrument from day one.** Measure time‑to‑quote, time‑to‑payment, sync failures, offline usage,
screen load, drop‑offs. Optimize with evidence.

**Internal SDK.** Every capability uses one toolkit (`App\BOS\Bos`: emit, rule check/enforce,
timeline) for objects/events/rules/projections/activities/timelines — consistency over duplication.

**First 90 days.** M1 Foundation + engines · M2 Today + Customer Timeline + full Sales workflow ·
M3 Collections + offline + Android wrapper + performance. Do NOT start Procurement/Inventory/Finance
analytics/advanced AI until the sales workflow is genuinely excellent.

## The four‑review gate (no slice is "done" until all four pass)
1. **Functional** — does it solve the business problem?
2. **Architectural** — does it comply with the BOS constitution + pass fitness tests?
3. **Operational** — is it deployable, reversible, observable?
4. **UX** — can a field salesperson complete the workflow quickly on a phone?
