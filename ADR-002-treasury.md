# ADR-002 — Treasury (bounded context)
**Status:** APPROVED. Treasury is its OWN bounded context, NOT part of Accounting. It produces ledger
entries (via ADR-001) but is not inside the accounting kernel — so bank rec, forecasting, floats, and
advances can be added later without swelling the kernel.

```
Sales → Accounting → Ledger            (documents)
Treasury → Custody/Transfers/Counts → Ledger   (cash operations)   ← independent context
```

## Custodian (generic, tenant-scoped)
NOT "CashHolder" — the same abstraction later holds cheque books, cards, POS terminals, van inventory.
```
custodians(id, tenant_id, branch_id, name,
  type,       -- employee|safe|branch|vehicle|bank|float|petty_cash|in_transit
  location,   -- optional (release-1 stand-in for full Branch)
  status, created_at)
```
`in_transit` is a reserved system custodian per (tenant, currency) for cross-office movement.

## Branch (first-class in model; optional in release 1)
`Company → Branch → Account → Custodian`. `branches(id, tenant_id, name, base_currency?, status)`.
Postings carry optional `branch_id`. Release 1 may collapse branch into custodian.location; branch
P&L/balance sheet drop in later without redesign.

## Custody projection (app/infra — a dimension, not a kernel concept)
```
cloudbss_cash_custody(tenant_id, entity_id, branch_id, currency, custodian_id, net_minor, updated_at,
  PK(tenant_id, entity_id, branch_id, currency, custodian_id))
```
Folded from cash-affecting JournalPosted lines (like AR subledger folds AR). Answers: how much USD the
company holds, per branch, per custodian, on a rider.

## Transfers = domain events with workflow (approval BEFORE posting)
```
TreasuryTransferRequested → TreasuryTransferApproved → JournalPosted
```
Only the approved event posts. Same-currency: base==foreign, no FX. Cross-currency reuses ADR-001/003
settlement (rate locked on the event, FX difference stored). Company total unchanged; only custody moves.

## Cash count with approval lifecycle
```
Draft → Submitted → Approved → JournalPosted
```
On approval, variance posts to **Cash Over/Short**. Immutable record (who counted/approved, when,
expected/counted/variance). Surfaces discrepancies daily, not month-end.

## Treasury dashboard (read-model; answers operational questions)
Currency × custody-type matrix (Safe/Bank/Staff/In-Transit) with drill-down by custodian. Beyond
balances: idle-cash aging by holder, outstanding variances, in-transit duration, highest idle cash by
branch, replenishment needs.

## Boundary (do not cross)
Kernel: double-entry integrity, base balancing, immutability, currency correctness.
Treasury (app layer): custody, transfers, counts, dashboards, workflow, approvals.

## Test gates
- Float transfer leaves company total unchanged; safe down, cashier up.
- Custody projection sums to GL cash control per currency.
- Cross-border USD→UGX transfer locks rate, stores FX difference.
- Count variance posts to Cash Over/Short; holder balance matches count; only on Approved.
