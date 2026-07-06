# ADR-003 — Exchange Rate Service
**Status:** APPROVED. Rate metadata lives on the business EVENT and this service — NOT on the kernel's
JournalAmount (ADR-001 keeps only foreign+base). The kernel never learns "why" an amount was computed.

## Rate table
```
exchange_rates(id, from_currency, to_currency,
  buy_rate, sell_rate, average_rate,
  provider,       -- manual|bank|central_bank|market   (ready for auto-import)
  effective_date, created_by, created_at)
```
Rates are integer numerator/denominator (no floats, arbitrary precision).

## On the business event (not the ledger)
```
PaymentPosted { ... exchange_rate_id, provider, rate_num, rate_den, override_reason?, approved_by? }
```
The event stores the ACTUAL numeric rate used (immutability) AND references the rate record for audit.
Editing the rate table later never changes a posted event.

## Policy
- Lookup by (from, to, date) → effective rate.
- Rate CHOICE is policy-driven per transaction type: customer receipt / supplier payment / internal
  transfer. Average is the configurable DEFAULT, not hard-coded.
- Hybrid entry: today's rate auto-fills; seller MAY override; override flags for manager approval.

## Test gates
- Same rate in → out reproduces base exactly; FX difference 0.
- Override path requires approval flag; approver recorded.
- Historical reproducibility: change table after posting → posted event's rate unchanged.
