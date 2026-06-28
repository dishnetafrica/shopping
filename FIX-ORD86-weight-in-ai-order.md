# Fix: weighted items collapse to 1 Kg in the AI order path (production ORD-86)

**First production-derived fix under the Engineering Policy.** It traces the chain:
production conversation (ORD-86) → shadow difference (WEIGHT) → replay test → fix → metric.

## What happened
Pal's Snacks, 28 Jun 2026. Customer ordered **Fafda 250g, Sev Khamni 250g, Samosa 4pcs,
Khaman 250g**. The bot's *summary text* was correct, but the placed order (ORD-86) was
**Fafda 1 Kg, Ratlami Sev 1 Kg, Samosa ×4, Khaman 1 Kg** — total computed correctly against the
**wrong (1 Kg) variant**.

## Root cause
This conversation runs through the **AI agent** (`AiBrain`), which emits a hidden order line:

```
<<ORDER {"items":[{"name":"...","qty":N}], "delivery":"...", "note":"..."}>>
```

That schema had **no weight field**, and the parser read only name+qty. So a weighted item could
only become `{"name":"Fafda","qty":1}`, and `OrderCalculator::quote()` priced the product's
**default variant (1 Kg)**. The deterministic (non-AI) path already handled weight correctly via
`WeightPricer`; the AI path simply never carried the grams.

## The fix (surgical, reuses existing machinery)
1. **Protocol** — the `<<ORDER>>` (and `<<QUOTE>>`) schema now carries an optional per-item
   `grams`, and the model is told: order-by-weight items include `grams` (250 gms → 250, 1 kg →
   1000); omit it for by-piece items (samosa, dozen).
2. **Parser** — `AiBrain` now reads each item object and captures optional `grams` (robust to
   field order / truncation; verified against the real ORD-86 block — Samosa correctly stays
   by-piece, delivery isn't mis-read as an item).
3. **Pricing** — `OrderCalculator` loads weight variants + sold-by-weight data and, when an item
   has `grams`, prices the requested weight through the **same `WeightPricer`** the deterministic
   path uses (exact merchant variant → pro-rata → never the default). Verified in-sandbox:
   250g → 12,500 (exact variant) or 8,800 (pro-rata from 35,000/kg), not 35,000.

## Regression contract
- `OrderCalculatorWeightTest` — weighted item priced at requested grams, not the 1 Kg base;
  no-grams still uses base (documents the boundary). *(DB feature test — run `php artisan test`.)*
- `tests/Fixtures/order_replays.json` — ORD-86 added as a labeled replay case (the
  "every production bug becomes a replay case" rule).

## Metric to verify in production
After deploy, watch the next weighted AI orders: the placed line and total must match the
requested grams. In the Observatory, this should show up as **WEIGHT disagreements trending to
zero** and the ORD-86 shadow diff advancing to `verified`.

## Note on Pal's data
Pricing falls back to the base variant if a product has neither a matching weight variant nor a
`reference_price`. If any sold-by-weight product still prices as 1 Kg after deploy, set its
`reference_price` (+ `reference_weight_grams`) or add explicit weight variants — that's data, not
code.
