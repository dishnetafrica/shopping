# 4 — Projection Catalog

**Status:** DRAFT for approval · Layer 4. A **projection** is a read‑only view built from objects
(their current state) + events (their history). Projections are **disposable** — any of them can be
deleted and rebuilt from the layers below. Nothing here is a source of truth; nothing here invents
data. Every number on every screen names the object field or event it came from.

**The rule that makes projections safe:** if a value can't be computed from a real object/event, the
projection **omits it or shows "—"** — it never guesses. (This is the same guardrail that stops the
bot inventing quote figures, applied to the whole UI.)

---

## PJ1 · Today (the home screen)
- **Is:** `activities WHERE owner = me AND due ≤ today`, ordered by priority.
- **Built from:** open Tasks + due `FollowupScheduled` + overdue Invoices (Collections) + `Delivered`‑
  pending orders + `LowStockDetected`.
- **Shows:** counts (tasks, follow‑ups, quotes waiting, to‑collect $) + an action list ("Visit ABC",
  "Collect $420", "Finish invoice", "Continue last job").
- **Grounding:** the `MorningBriefing` capability already computes most of this (to‑collect,
  to‑invoice, quotes‑to‑confirm, reorder) — Today is that projection, rendered as activities.
- **AI layer (optional, non‑blocking):** narrates the same facts in one paragraph. If AI is off,
  Today still fully works.

## PJ2 · Customer Timeline (the killer feature)
- **Is:** `events WHERE subject = {customer,id} ORDER BY occurred_at DESC`, plus the customer's
  current header (name, outstanding, last order).
- **Shows:** one WhatsApp‑style stream — visited, quoted, accepted, invoiced, paid, receipt sent,
  follow‑up — with the context `+` for the next action.
- **Grounding:** every event already carries `subject`; outstanding = `SUM(Invoice.balance)`.
- **Why first:** highest value, lowest risk — a pure read over events your code already fires.

## PJ3 · Insights (replaces "Reports")
- **Is:** aggregations over events. No charts‑first; quick‑read numbers.
- **Views:** Sales today (`Σ InvoiceIssued`/`PaymentReceived` today), Collections, Overdue (aged AR),
  Top customers (by paid), Missed targets, Profit (revenue − COGS − `ExpenseRecorded`).
- **Grounding:** the existing dashboard widgets (Cash on hand, Open invoices, Payments today, Net
  profit, Low stock) are exactly these projections.
- **Rule:** every figure is an aggregation of real events; period + currency stated.

## PJ4 · AI Morning Brief (interpretation, not source)
- **Is:** a natural‑language *reading* of PJ1/PJ3 facts.
  > "Good morning. Collect $4,320 from 5 customers. $1,200 at risk if XYZ isn't chased. ABC hasn't
  > ordered in 14 days. Enough stock to close 18 quotes."
- **Contract:** every figure is lifted verbatim from a projection; AI supplies wording + ordering
  only. **AI never computes a money number and never decides the workflow** — it points at the step
  the process already defined.
- **Degrades:** if AI is unavailable, PJ1 (Today) is shown plainly; nothing breaks.

## PJ5 · Object timelines (Order / Product / Shipment)
- Same shape as PJ2 for any object: `events WHERE subject={type,id}`. Order history, stock history,
  shipment tracking — all one mechanism, zero new writes.

## PJ6 · Role dashboards (Owner / Sales / Finance)
- **Owner:** cash, runway, to‑collect, to‑invoice, reorder, WhatsApp health (the briefing metrics).
- **Sales:** my visits, my quotes/close‑rate, my collections.
- **Finance:** AR aging, cash position by holder, expenses pending, P&L.
- Each is a filtered projection of the same events — **role‑aware** (layer 5), never a separate build.

## PJ7 · Operations & Finance hubs (already shipped)
- The workspace hubs ("Open Orders", "Items to Receive", "Waiting for Payment", "Money to Collect")
  are projections of open document states — already live in the mobile shell.

## PJ8 · Sync status (the only offline UI)
- **Is:** per‑record derived state from the offline queue: `✓ Synced · ⟳ Syncing · 📴 Offline · ⚠
  Needs attention`. The user never presses "Sync"; conflicts (rare, e.g. two reps paying one invoice
  offline) surface as ⚠ with a one‑tap resolve, per the object's merge rule.

---

## Contract for all projections
1. **Read‑only & disposable** — rebuildable from objects + events; never a source of truth.
2. **No invented data** — every value names its object field / event; unknown → "—".
3. **Currency‑honest** — show the real currency; conversions labeled, per the money invariant.
4. **Role‑aware** — a projection shows only what the viewer's role may see (layer 5).
5. **AI is a lens, not a source** — AI reshapes/orders/explains a projection; it adds no facts.

Approve this and every future "screen" request becomes: *which projection, filtered how, rendered as
what* — never a new module.
