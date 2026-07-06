# ADR-000 — ERP Architecture Principles
**Status:** FOUNDATIONAL. This is the constitution every future ADR must obey. It describes RULES,
not features. If a later ADR needs to break one of these, that ADR must call it out explicitly and
justify it — silent violations are not allowed.

These principles are not aspirational; they are the rules the kernel and its slices already follow.

## 1. The kernel is event-driven and immutable
- One write model: the event store. State is derived by folding events into projections.
- Posted journals and recorded events are immutable (Constitution rule 10). Corrections are new
  compensating entries, never edits. No module may mutate a posted journal — ever.

## 2. Business workflows never bypass the ledger
- Every financial fact reaches the books through a domain event → `JournalPosted`. No side-channel
  writes to balances. If it affects money, it is a posting.
- Workflows (approvals, transfers, counts) may gate WHEN a posting happens, but the posting itself
  always flows through the kernel's single write seam.

## 3. All money is the `Money` value object
- No raw decimals/floats for money anywhere. `Money` is integer-minor, currency-tagged, and refuses
  to combine mismatched currencies. Multi-currency composes two `Money`s (`JournalAmount`), never
  a float.

## 4. Currency conversion produces immutable base amounts
- Every posted line carries its base-currency value, computed once at posting time and frozen.
- The rate used is recorded on the business event (with its numeric value), never only as a reference
  to a mutable table. Editing a rate table never rewrites history. Reports never re-convert on the fly.

## 5. Balancing is in base currency
- An entry balances when Σ(debits.base) == Σ(credits.base). Foreign amounts need not balance across
  currencies. This is the invariant the kernel guarantees.

## 6. Bounded contexts interact through events, not direct dependencies
- Accounting, Treasury, Inventory, CRM, Reporting are separate bounded contexts. They communicate by
  publishing/subscribing to events, not by calling into each other's internals.
- A context may PRODUCE ledger entries or READ projections; it may not reach into the kernel's model.

## 7. The kernel stays minimal; business concepts live in the app layer
- The kernel owns exactly: double-entry integrity, base-currency balancing, immutability, currency
  correctness. Nothing else.
- Custody, branches, parties, workflows, approvals, dashboards, rate providers, tax rules — all live
  in app/infra as policies, projections, and read-models. They ride on postings as tags; they are not
  kernel concepts. (If the kernel would need to know it, ask first whether it truly must.)

## 7a. The journal records WHAT was posted, not WHY
- A ledger line (`JournalAmount`) holds exactly `{foreign, base}` — the amounts posted. Nothing more.
- The REASON a base amount was computed (exchange rate id, provider, override reason, approver) lives
  on the originating business event (e.g. `PaymentPosted`), never on the journal line. This keeps the
  accounting kernel reusable even if another context computes base amounts differently later.
- No future ADR may add rate/pricing/tax metadata to `JournalAmount`. Put it on the event.

## 8. Projections are rebuildable; the log is the source of truth
- Every projection (balances, subledger, custody, reporting) can be rebuilt from the event log at any
  time. No projection holds truth the log doesn't. Recovery is replay.

## 9. Changes to the kernel require an ADR and a freeze ceremony
- `src/` is frozen against a hash baseline. Changing it is a deliberate versioned act with an ADR,
  regenerated baseline, and the full golden + stress suites plus new suites, all green, before tag.
- Infra/app additions (policies, projections, reports) are freeze-safe and do not touch `src/`.

## 10. Idempotency and multi-tenant isolation are non-negotiable
- Every posting path is idempotent (safe to retry; keyed on a stable id). Books are scoped per
  (tenant, entity); one tenant can never see or affect another's ledger.

## 11. Every new context ships milestone-gated
- Foundational changes ship in branch-scoped milestones, each with test gates green against real
  Postgres, none starting until the prior gates pass. Production tenants stay on the proven version
  until the new one is proven. Features are opt-in per tenant.

## 12. Prove with real data before trusting with real money
- A new capability is not "done" when tests pass; it is done when it has reconciled against real
  numbers from a real tenant. Test-green is necessary, not sufficient.

---
Every ADR (001…) must conform to these. When one must deviate, it states which principle and why.
