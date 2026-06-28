# Keeping order data honest — when the seller doesn't update status

The delivery-confirmation loop depends on staff advancing status (… → Out for delivery → Delivered).
If they don't, `delivered_at` never gets set, the customer can't confirm, and orders rot in
"Preparing" with no data. Two mechanisms keep the data honest — one pushes the seller, one lets the
customer close the loop and records the gap.

## 1. Self-checking watchdog — pushes the seller (`orders:staleness-watchdog`)
Every 30 min (daytime), any order sitting in an active status past its SLA triggers a WhatsApp nudge
to the shop's alert number. If it's still stuck after another SLA window, the nudge escalates once
more, then caps (never spam).

Default SLAs (minutes), per-tenant override via the `order_sla` setting:

| Status | New | Accepted/Confirmed | Preparing | Ready | Packed/Dispatched/Out for delivery |
|---|---|---|---|---|---|
| SLA | 15 | 45 | 90 | 45 | 120 |

- The clock uses `status_changed_at` (stamped by `OrderObserver` on every status change), so it
  measures real time-in-status, not time-since-order.
- Terminal statuses (Delivered / Customer confirmed / Cancelled / Rejected) are never nudged.
- Transport (Juba) orders skip the dispatch-state SLA — long transit is expected and the shipment
  chain owns that timing; their pre-dispatch states are still watched.
- Escalation is capped at 2 nudges; `stale_level` + `stale_nudged_at` track it.

## 2. Customer self-update — closes the loop, records the gap
The tracking page no longer waits for the seller to mark Delivered:
- **Delivered** → prominent "✓ I Received My Order".
- **Still in progress** → a quieter "Already received this order? ✓ Confirm I received it".
- **Either** → "Not received yet? Tell the shop" which alerts the shop (`NotifyOwner`) and logs it.

When a customer confirms an order the seller never marked delivered, the system sets
`delivered_at = now()`, `status = Delivered`, **`delivered_by = customer`**, and
`customer_confirmed_at`. The loop closes from the customer side, and `delivered_by = customer` is a
permanent record that the seller didn't keep the data current.

## 3. The data-discipline scorecard
`OrderObservatoryReport::sellerDiscipline()` (in the daily BUSINESS block):
- **stale-nudges** — how often staff had to be pushed,
- **self-closed** — orders the customer had to close (`delivered_by = customer`),
- **stuck now** — active orders currently flagged stale.

Rising self-closed or stale-nudges = a shop that isn't maintaining its data; both are now visible
instead of invisible.

## Schema
Migration `…000019` adds `status_changed_at`, `stale_nudged_at`, `stale_level`, `delivered_by` to
`orders`. `OrderObserver` stamps `status_changed_at` on create and on every status change (and
resets the stale flags so a fresh status restarts the clock).

## Tests
- `OrderStalenessWatchdogTest` — stuck-past-SLA nudges; fresh order doesn't; terminal doesn't;
  escalation caps.
- `SelfUpdateConfirmationTest` — customer self-confirms an un-delivered order (sets
  `delivered_by = customer`); self-confirm offered on an in-progress order; "not received"
  escalates; a seller-delivered order is *not* tagged customer.
*(DB/queue feature tests — run `php artisan test`.)*

## Edge cases handled
- Status flipped back out of Delivered → `delivered_at` cleared (existing observer logic) and the
  clock restarts.
- Manual late "Delivered" → `delivered_at`/`status_changed_at` measured from the flip, not order time.
- Transport in transit → not nagged; only pre-dispatch staleness is watched.
