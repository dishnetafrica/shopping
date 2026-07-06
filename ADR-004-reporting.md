# ADR-004 — Reporting & FX
**Status:** APPROVED. All reporting reads the durable projections (base-currency values already stored
per ADR-001) — pure queries, no kernel involvement.

## Reports
- **Trial balance (base currency):** Σ debits.base == Σ credits.base. Also a per-currency view.
- **Foreign balances:** open AR/AP/cash by document currency (unconverted facts).
- **Treasury dashboard:** currency × custody drill-down (defined in ADR-002; rendered here).
- **FX reports:** realized (from stored settlement differences) + unrealized (revaluation).

## FX gain/loss cadence (configurable close period; monthly default)
1. At settlement: base amount + rate LOCKED immediately (ADR-003), FX difference STORED (not yet a GL
   posting).
2. Period-end: sweep stored differences → ONE journal Dr/Cr FX Gain/Loss ↔ FX Clearing.
3. Revaluation: open foreign balances revalued at period-end rate; unrealized delta posts to FX
   Gain/Loss (unrealized), reverses next period.
Locking the rate is immediate (audit); POSTING the gain/loss is deferred (clean books). These are not
the same thing.

## Test gates
- Trial balance balances in base across mixed-currency postings.
- Same-rate month → FX sweep posts 0.
- Revaluation reverses cleanly next period.
