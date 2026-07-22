# V3 Business Operating System — Architecture Freeze v1.0 (candidate)

You're right: what we're defining is no longer an ERP architecture — it's a **Business Operating
System (BOS)**. This folder is the complete operating model, ready to freeze. Once approved, no new
feature is built until it maps onto this model.

## The one organizing idea
**Capabilities at the top** (what the company *can do* — the most stable layer), everything else
underneath. Objects hold state, processes move them, rules constrain them, events record them,
projections show them, AI assists over them — and it all runs with AI and the network off.

## The frozen stack
```
MISSION
   │
Business Capabilities   Sell · Buy · Move · Collect · Support · Market · Manage · Analyze
   │
Business Processes      12 processes, owned by capabilities
   │
Business Rules          executable invariants (money immutable, no negative stock, …)
   │
Business Objects        Customer, Product, Quote, Order, Invoice, Payment, Shipment, …
   │
Business Events         append-only facts about objects
   │
Business Services       existing Laravel services (kept)
   │
Projections             Today · Timeline · Insights · Dashboards (disposable views)
   │
AI Assistant            interprets — never a source of truth, never owns a workflow
   │
PWA  →  Android Wrapper (thin: device bridges only)
```

## The eight freeze documents — approve in order
| # | Document | Layer it defines |
|---|---|---|
| 1 | `1-MISSION-AND-CAPABILITY-MAP.md` | Mission + Capabilities (+ quality attributes) |
| 2 | `2-BUSINESS-OBJECT-CONSTITUTION.md` | Objects |
| 3 | `3-PROCESS-CATALOG.md` | Processes |
| 4 | `4-BUSINESS-RULE-CATALOG.md` | Rules |
| 5 | `5-EVENT-CATALOG.md` | Events |
| 6 | `6-PROJECTION-CATALOG.md` | Projections |
| 7 | `7-UX-CONSTITUTION.md` | UX (mobile/offline/one-thumb) |
| 8 | `8-ADR.md` | The "why" — decision records |

## Two honest notes baked into the model
- **Nav ≠ architecture.** Capabilities organize the system + desktop; the *phone* bottom bar stays a
  role‑aware curation (Today + a role's few capabilities) — eight tabs would fail the one‑thumb test.
- **Overlay, not rewrite.** You're ~70% here already (event‑shaped tables, observers, `AuditLogger`,
  `PaymentService` fan‑out, the capability layer). We name and unify these one process at a time; the
  business never stops shipping. (ADR‑008.)

## The governance rule (the whole point of freezing)
> **No new feature may be implemented until it states: which _capability_ it belongs to, which
> _process_ it extends, which _objects_ it affects, which _rules_ it must obey, which _events_ it
> emits, and which _projections_ it changes.**

Lightweight — a six‑line checklist on every change — not bureaucracy. It's what stops architectural
drift over a ten‑year product.

## To declare Freeze v1.0 I need from you
1. **Approve docs 1–8** (edit anything in doc 2 that doesn't match how you run the business — it's the
   permanent object contract).
2. **Confirm the three standing decisions** (now in the ADRs): object‑first source of truth (ADR‑001),
   unified event‑log (ADR‑002), keep events forever.
3. **Say "freeze."** From that moment the model is versioned; changes go through a new ADR.

Then the first build is the **Customer Timeline** (projection PJ2) — highest value, lowest risk, over
events your code already fires.
