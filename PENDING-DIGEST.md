# Pending-orders digest — fixes the staleness spam (EuroPearl, 28 Jun 2026)

The seller-discipline watchdog we shipped did the wrong thing for a manufacturer: it sent **one ⚠️
message per order** ("EUR-3 Preparing 3d… EUR-4 New 1d19h…") using restaurant-grade timings, when a
B2B paper order legitimately sits for days. Two fixes.

## 1. Vertical-aware thresholds (`App\Support\OrderSla`)
How long an order may sit before it "needs attention" depends on the business:

| Vertical | "New" overdue after | Cadence |
|---|---|---|
| restaurant | 15 min | near-real-time (batched) |
| grocery | 60 min | twice daily |
| snacks | 8 h | twice daily |
| manufacturer | 2 days | twice daily |

EuroPearl's "New 1d19h" and "Preparing 3d" are **no longer flagged** — for a manufacturer they're
normal. Per-tenant overrides via the existing `order_sla` setting still win.

## 2. One digest, with a link (`orders:pending-digest`)
Replaces the per-order nudges with **one message per shop**, cadence by vertical:
- **manufacturer / grocery / snacks** → a digest at **09:00 and 14:00** (configurable via the
  `digest_times` setting): *"Good morning 🗂️ You have N open orders awaiting action (2 overdue).
  Review & update: <link>"*.
- **restaurant** → alerts whenever orders run late, **batched into one message** and de-duplicated so
  the same set isn't repeated every 30 min.

The link opens a login-free, token-credentialed read-only page (`/papi/pending`) listing every open
order with customer, status, age, total, and an overdue flag. Owners review there and update statuses
in the panel (the page is read-only — no mutations on a tokened URL).

Scheduled every 30 min (08:00–21:00); each tenant self-gates on the rules above.

## Verification
SLA smoke confirmed: manufacturer "New 2700m" / "Preparing 4320m" → not overdue; restaurant same ages
→ overdue. `OrderSlaTest` (pure) and `OrderPendingDigestTest` (one digest at 09:00 not per-order;
silent at 10:30; restaurant alerts then dedupes; page rejects a bad token) are the regression tests.

## Superseded
The old `orders:staleness-watchdog` (per-order nudges) is **no longer scheduled** — replaced by this
digest. The command file and its test remain but are inert; safe to delete.
