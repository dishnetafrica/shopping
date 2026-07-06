# ADR-001 — Kernel v2: Dual-Currency Ledger Core
**Status:** APPROVED (v2 contract). Scope: the FROZEN kernel core only. Treasury, rates, and
reporting are separate ADRs (002/003/004) so this stays one reviewable decision.

## Decision
The ledger becomes multi-currency by composing two `Money` values per line. `Money` is unchanged.

### JournalAmount (minimal — kernel answers only "what was posted")
```
JournalAmount {
    Money foreign   // amount in the line's own (document/tender) currency
    Money base      // same value in the tenant base currency
}
```
That is ALL the kernel holds. It does NOT hold the rate id, provider, override reason, or approver —
those explain WHY the base amount was computed and live on the business EVENT, not the ledger line
(see ADR-003). The kernel answers "what amount was posted?", never "why was it calculated?".

### JournalLine
```
JournalLine { Account account; JournalAmount amount; string side }   // was: Money amount
```

### Balancing rule (the core change)
An entry balances in BASE currency: Σ(debits.base) == Σ(credits.base). Foreign amounts need not
balance across currencies (different units). This makes cross-currency settlement representable and
still provably balanced.

### Account
Gains optional `currency` (locked) + `type`. A posting whose foreign currency ≠ a currency-locked
account's currency is REJECTED at construction (fail fast). Income/expense/equity post in base.

### Immutability
Once posted, `foreign` and `base` are frozen (Constitution rule 10 extends to the amounts). The rate
used is recorded on the business event, immutably (ADR-003) — editing a rate table never rewrites a
posted line.

## Base-currency balancing — worked
Invoice 118,000 UGX, customer pays $30 @ 3705 → base 111,150 UGX:
```
Dr Cash·USD   foreign 30 USD    base 111,150 UGX
Cr AR                            base 111,150 UGX
```
Balances in base (UGX). AR reduces in UGX; USD cash rises in USD.

## Backward compatibility & replay
Single-currency is the degenerate case: `base == foreign`, no rate. A v1 `JournalPosted` (single
Money) folds as `JournalAmount{foreign=base=that Money}` — no event rewriting. `cloudbss_ledger_
balances` gains `base_currency` + `base_net_minor` (additive; existing rows backfill base = native).

## Freeze ceremony (this is a v2 major bump)
Touches `JournalLine`, `Account`, posting reactor, `LedgerProjection` in `src/`. Therefore: regenerate
the freeze baseline ONCE with this ADR as justification; re-establish golden (39) + stress (18) for v2
PLUS dual-currency suites; `Money` untouched (its tests stay valid). All green before v2 is tagged.

## Test gates
1. Same-currency regression: every existing test passes with foreign==base (degenerate case).
2. Cross-currency entry balances in base; AR reduces in document currency; cash rises in tender ccy.
3. Same-rate settlement → FX difference == 0 (no phantom gains).
4. Currency-lock: USD into a UGX-locked account is REJECTED.
5. Replay: mixed v1+v2 log rebuilds; balances reconcile.

## Rollback
Additive columns; v1 events replay as degenerate case; a tenant reverts by simply not using >1
currency. No data rewrite.
