# Kernel v2 → ERP: Milestone Work Breakdown

Foundational change of this size ships in reviewable milestones, each on a branch, each with green
test gates against real Postgres, BEFORE the next starts. Production single-currency tenants stay
untouched throughout. All milestones obey ADR-000.

Order note: Treasury (M2) comes BEFORE Exchange Rates (M3) because same-currency treasury (custody,
transfers, counts, safes) needs no FX and is usable immediately. The CROSS-currency parts of treasury
depend on rates + settlement and are deferred to M4 — M2 must NOT assume the rate service exists.

## M1 — Kernel v2 core  (ADR-001)
`JournalAmount{foreign, base}`, `JournalLine`/`Account` changes, base-balancing rule, currency-locked
accounts, projection folds base. Migration (additive) + v1→v2 replay. Freeze ceremony: regenerate
baseline, golden 39 + stress 18 + dual-currency suites, all green.
**Gate:** existing tests pass as degenerate (base==foreign); cross-currency entry balances in base;
currency-lock rejects mismatches; mixed v1+v2 replay reconciles.
**Exit:** kernel v2 tagged; nothing shipped to production.

## M2 — Treasury (same-currency)  (ADR-002)
Custodians, branches (model), custody projection, transfer workflow (Requested→Approved→Posted),
cash-count lifecycle (Draft→Submitted→Approved→Posted), in_transit custodian — ALL same-currency only.
No FX assumptions; cross-currency transfers explicitly deferred to M4.
**Gate:** same-currency float transfer preserves company total (safe down, cashier up); custody sums
to GL cash control per currency; count variance posts to Cash Over/Short only on Approved. Immediately
usable for DishNet cash movement between staff/safes within one currency.

## M3 — Exchange Rate Service  (ADR-003)
`exchange_rates` (+provider), integer num/den, lookup by (from,to,date), hybrid auto-fill + override +
approval, rate metadata on the business event (not the ledger).
**Gate:** same rate reproduces base exactly; override requires approval; posted event rate immutable
after table edits.

## M4 — Multi-currency settlement engine  (ADR-001 + 003; unlocks cross-currency treasury from M2)
ONE generic engine: AR receipts, AP payments, expense payments, transfers (incl. cross-currency),
deposits, withdrawals — foreign in → base computed (locked rate) → currency-locked cash account → FX
difference stored.
**Gate:** USD-settles-UGX-invoice reduces AR in UGX, cash in USD, TB balances in base; partial
multi-currency payments reconcile; cross-border USD→UGX transfer locks rate + stores FX difference;
idempotent.

## M5 — Reporting & FX  (ADR-004)
Base-currency trial balance, foreign balances, treasury dashboard, realized FX sweep + revaluation
(configurable close period).
**Gate:** TB balances in base across currencies; same-rate month sweeps 0; revaluation reverses next
period.

---
## Sequencing discipline (ADR-000 §11, §12)
- Each milestone = a branch + PR-sized review, not one big drop.
- No milestone starts until the prior one's gates are green against Postgres.
- Production stays on v1 single-currency until M4 is proven; then multi-currency is opt-in per tenant.
- **Before ANY of this:** let the single-currency books (deployed today) reconcile against real
  Patronics numbers for a few days. Test-green is necessary, not sufficient (ADR-000 §12).
