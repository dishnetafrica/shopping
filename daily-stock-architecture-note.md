# Architecture note — future `daily_stock` support (DESIGN ONLY, not implemented)

Purpose: let a merchant declare how much of each item was made today and have the bot
count it down as orders are confirmed, auto-hiding an item when it runs out. This is a
**design placeholder** — nothing here is built yet.

## Where it lives
Extend the existing `daily_state` (tenant settings, already auto-resets by date) with a
`stock` slice. No new table needed for V1; stock resets daily for free via `DailyState::fresh()`.

```
daily_state.stock = {
  "<product_id>": { "made": <int>, "sold": <int>, "unit": "plate"|"piece"|"gram" }
}
```
- Count products (`unit: plate/piece`): `made`/`sold` in whole units.
- Weight products (`sold_by_weight`): `made`/`sold` in **grams** (so 5kg fafda = made 5000).
- `remaining = made - sold`. `remaining <= 0` ⇒ treated as out for the day.

## Merchant input (new parser detector)
Add a `stock` detector to `MerchantConversationParser` — keep it deterministic, same
propose→YES flow as every other change:
- `"Made 200 fafda today"`, `"200 plates fafda ready"`, `"stock 5kg kaju"` → `{type:'stock', target, made, unit?}`
- Resolved in `MerchantAssistant` to `product_id`; applied by `MerchantChangeApplier` into
  `daily_state.stock[id] = {made, sold:0, unit}` (snapshot prior for undo, like today).

## Decrement on order (the one new hook)
On **order confirmation** (where the cart becomes an order), for each line decrement stock:
- count line → `sold += qty`; weight line → `sold += weight_grams`.
- Must be **atomic** — wrap the read-modify-write of `daily_state.stock` in a DB transaction
  / row lock (or move stock to its own table; see below) to prevent overselling under
  concurrent orders. This is the main correctness risk and the reason it's deferred.
- When `remaining` would go below 0, either reject the line ("only 3 left") or allow and flag
  — a policy toggle (`stock_oversell_allowed`).
- When `remaining` hits 0, auto-add the id to `daily_state.unavailable` so browse/search hide it.

## Customer read
Catalogue/search already consult `daily_state.unavailable`; stock-out simply feeds that set.
Optionally surface "only N left" / "N kg left" in product replies (a render flag, not core).

## When to graduate to a table
If you later want history/reporting (made vs sold per day, waste, peak items), add a
`daily_stock_ledger(tenant_id, product_id, date, made, sold, unit, updated_at)` with a unique
`(tenant_id, product_id, date)` and do atomic `UPDATE … SET sold = sold + ?`. The JSON slice
is fine for live operation; the table is for analytics + safe concurrent decrement.

## Touch-points summary (for when it's built)
1. `MerchantConversationParser` — new `stock` detector.
2. `MerchantAssistant` — resolve + propose stock changes.
3. `MerchantChangeApplier` — apply/undo `daily_state.stock`.
4. `DailyState::EMPTY` — add `'stock' => []`.
5. Order-confirm path — atomic decrement + auto-unavailable at zero.
6. Catalogue read — already gated by `unavailable`; optional "N left" render.

Explicitly out of scope now: no code, no migration, no parser change. Implement only after
Weight V1 + Merchant Mode have a clean production week.
