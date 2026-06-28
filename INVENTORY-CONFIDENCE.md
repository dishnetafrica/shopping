# Inventory Confidence (snapshot primitive) + Operational Timeline (ADR)

Refined per review: confidence lives on an **Inventory Snapshot**, not on `Product`; it **decays**
with age; the timeline ADR gains `correlation_id`.

## Built — Inventory Confidence over snapshots
The bot used to assume availability (`'stock' => $p->stock ?? 1`). Now belief is an observation with
three separate things kept apart — the **fact** (quantity), the **confidence**, and the
**freshness** (observed_at).

### Snapshot model, not a Product property
`inventory_snapshots` holds one current row per **(product, location)** — so the same product can
carry different confidence in the shop, a warehouse, a rider's bag, or a branch. `Product` is
untouched. (`InventorySnapshot` model + migration `…000020`.)

### Confidence decays automatically
No one has to "expire" anything — the bot grows cautious on its own as an observation ages:

```
exact  --(12h)-->  estimated  --(72h)-->  unknown
```

| State | Bot says |
|---|---|
| exact | "17 available" / "Out of stock" |
| estimated | "Usually available (last checked …)" |
| unknown | "I'll confirm before placing your order" |

Windows (`EXACT_HOURS`, `ESTIMATED_HOURS`) are parameters on `state()`.

### Service & wiring
`App\Services\Inventory\InventoryState` is the only writer: `recordCount`, `markEstimated`,
`markUnknown`, `decrementOnSale` (reduces a *known* count, leaves unknown alone), all per location.
`state()` derives with decay; `botTag()` annotates the catalogue; `prime()` bulk-loads snapshots so
the 200-item catalogue costs one query. The AI catalogue shows `[in stock: 17]` /
`[usually available — confirm]` / `[stock unknown — confirm before promising]` / `[OUT OF STOCK]`,
and a prompt rule makes the bot reflect it.

### Tests
`InventoryStateTest`: exact states the number; zero → out of stock; **exact decays to estimated
after 12h and to unknown after 72h**; never-counted → unknown; sale decrements known but not
unknown; **locations are independent**. Decay logic verified 6/6 in-sandbox.

## Designed — Operational Timeline (ADR-0001), now with `correlation_id`
The envelope gains `correlation_id`, which chains every event of one workflow (an order's whole
life: created → kitchen → picker → packed → rider → delivered → confirmed) under one id
(`ORD-123`). "Show me everything that happened to this order" becomes a single filter, and
cross-subsystem debugging gets far easier — still no code, no bus, no migration.

## Documented next states (not built)
- **Reserved** inventory state: "10 paneer · 7 reserved · 3 available" — prevents over-promising
  when several customers order at once. Natural once order volume grows; arrives with its evidence.
- **History**: snapshots are current-belief; the per-observation history is a timeline concern
  (emit `inventory.counted` events) once the timeline is implemented.
