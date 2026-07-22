# BOS Master Backlog — Guide

`BOS-BACKLOG.csv` turns the frozen architecture into a dependency‑ordered, executable roadmap. It is a
plain CSV so you can import it straight into Jira / Linear / a sheet.

## Columns
`id · phase · capability · title · objective · dependencies · acceptance · complexity · rollback · tests`
- **dependencies** — the `BOS-####` ids that must ship first (the ordering).
- **complexity** — S / M / L / XL (relative, not hours).
- **rollback** — how to back the task out safely (every task is reversible).
- **tests** — the named tests that prove it (rule tests are `test_R#_…`).

## Granularity policy (why 108 tasks, not 500)
A good backlog is **fully specified near‑term and deliberately coarse long‑term** — writing 500
detailed rows for work 9 months out is false precision you'd rewrite before reaching it. So:
- **Phases 0–3 (82 tasks): fully specified.** This is your next real work — Foundations → Business
  Foundation → Today → the whole Sell workflow end‑to‑end.
- **Phases 4–8: epics + representative tasks (5 each).** Each ends with an `EPIC decompose` row.
  **Decompose these into full tasks just‑in‑time**, at the start of that phase, using the same six
  fields. Reserved id ranges are left for them (e.g. `0101–0150` for Sell overflow, `0156–0175` for
  Collect, `0181–0195` for Buy, `0201–0215` Inventory, `0221–0235` Finance, `0241–0299` Analytics).
- Expanded fully, this becomes the 200–500 task backlog — but honestly, not padded.

## Definition of Ready (a task can start when)
Its dependencies are done · it names its capability/process/objects/rules/events/projection · its
acceptance + tests are written first. (This is the pre‑code mapping from doc 9.)

## Definition of Done (a task ships when)
Named tests pass · events emitted and subscribed · projection updates from events (no ad‑hoc writes) ·
works offline where field‑facing · one‑thumb path verified · rollback confirmed · PR checklist complete.

## The critical path (what unblocks the most)
```
BOS-0001 events table → 0003 emit() → 0044 projection base → 0045 timeline → 0055/0056 customer timeline
BOS-0006 client-uuid → 0008 outbox → 0009 idempotent sync → 0096 offline payment
BOS-0025 rule engine → the R# rule tasks (0026–0037)
```
Foundations (Phase 0) and the rule engine gate almost everything — do them first.

## Suggested first sprint (2–3 weeks, all overlay, zero business disruption)
`BOS-0001, 0002, 0003, 0004` (event spine) · `0011` (offline app‑shell) · `0014, 0016` (test harness +
PR gate) · `0044, 0045` (projection + timeline) · `0055, 0056` (Customer Timeline — the first visible
win). Every one of these sits *on top of* existing code; nothing is ripped out.

---

## Worked examples (the depth behind a CSV row)

**BOS-0055 — Customer timeline projection**
- *Capability:* Sell · *Process:* Relationship · *Objects:* Customer, (reads all events)
- *Rules:* R22 (every mutation emits an event), R23 (projections are read‑only)
- *Events consumed:* all with `subject={customer,id}` · *Services:* TimelineEngine (BOS-0045)
- *Projection:* `events WHERE subject_type='customer' AND subject_id=? ORDER BY occurred_at DESC`
- *Acceptance:* returns one merged stream (legacy + new events); no writes to source; rebuildable
- *Tests:* `test_customer_timeline`, `test_timeline_rebuild_matches` · *Rollback:* remove projection
  (read‑only, safe)

**BOS-0096 — Payment (offline, idempotent)**
- *Capability:* Collect · *Process:* Collections · *Objects:* Payment, Invoice
- *Rules:* R3 (payment ≤ balance → advance), R21 (client uuid + idempotency)
- *Events:* emits `PaymentReceived` (fan‑out to cashbook + invoice + ledger already exists)
- *Services:* PaymentService.record + outbox (BOS-0008) + sync (BOS-0009)
- *Acceptance:* recording offline then reconnecting posts exactly once; balance correct; receipt follows
- *Tests:* `e2e_payment_offline`, `test_R3_payment_le_balance`, `test_sync_idempotent`
- *Rollback:* feature‑flag the offline path; online path unchanged

---

## Governance reminder (doc 9)
No task merges without answering: **which capability, process, objects, rules, events, services,
projection, tests.** Miss one → don't merge. That single discipline is what keeps this backlog — and
the architecture — from drifting over the next ten years.
