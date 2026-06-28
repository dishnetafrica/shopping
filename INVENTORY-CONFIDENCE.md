# Inventory Confidence (snapshot primitive) + Operational Timeline (ADR)

Confidence lives on an **Inventory Snapshot**, not on `Product`; it **decays** with age; the timeline
ADR reserves `correlation_id` and `causation_id`.

## Built — Inventory Confidence over snapshots
Belief is an observation with three separate things kept apart — the **fact** (quantity), the
**confidence**, and the **freshness** (observed_at).

### Snapshot model, not a Product property
`inventory_snapshots` holds one current row per **(product, location)** — the same product can carry
different confidence in the shop, a warehouse, a rider's bag, or a branch. `Product` is untouched.

### Confidence decays automatically
```
exact ──(12h)──▶ estimated ──(72h)──▶ unknown
```
| State | Bot says |
|---|---|
| exact | "17 available" / "Out of stock" |
| estimated | "Usually available (last checked …)" |
| unknown | "I'll confirm before placing your order" |

`InventoryState` is the only writer (`recordCount`, `markEstimated`, `markUnknown`,
`decrementOnSale`, per location); `state()` derives with decay; `botTag()` annotates the catalogue;
`prime()` bulk-loads so the 200-item catalogue is one query. A prompt rule makes the bot reflect the
tag (`[in stock: 17]` / `[usually available — confirm]` / `[stock unknown — confirm before promising]`
/ `[OUT OF STOCK]`).

### Tests
`InventoryStateTest`: exact states the number; zero → out of stock; exact decays to estimated after
12h and to unknown after 72h; never-counted → unknown; sale decrements known but not unknown;
locations independent. Decay verified 6/6 in-sandbox.

## Designed — Operational Timeline (ADR-0001)
Envelope reserves both chain ids:
- **correlation_id** — which workflow (an order's whole life shares `ORD-123`).
- **causation_id** — *reserved, not emitted*: which event directly caused this one (e.g. a
  `seller.notified` caused by a `customer.confirmed_delivery`). Lets you later answer "why did this
  automation fire?", not just "what happened?".

No code, no bus, no migration — contract only.

## Documented future (not built)
- **Confidence is multi-factor.** Today it decays by **age** alone. It is expected to also weigh the
  **observation method** — manual count / warehouse scan (high) vs overnight ERP import / AI estimate
  (lower) — so two equally-fresh numbers can carry different confidence. The snapshot's `source`
  field is the seed of this; consumers depend only on derived `state()`, never on how it's computed,
  so this can land without touching any capability.
- **`expires_at` on the snapshot.** Makes the decay windows tenant configuration ("exact until 14:30,
  estimated until Tuesday") instead of constants in the service.
- **Reserved inventory progression:** `Available → Reserved → Allocated → Consumed`, so the bot stops
  over-promising under concurrent ordering. Arrives with real concurrency pressure (and a replay
  case), not before.
