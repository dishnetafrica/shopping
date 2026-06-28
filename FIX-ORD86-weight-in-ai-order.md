# Fix: weighted items collapse to 1 Kg in the AI order path (production ORD-86)

**First production-derived fix under the Engineering Policy**, hardened per review before deploy.
Chain: production conversation (ORD-86) → shadow difference (WEIGHT) → replay test → fix → metric.

## What happened
Pal's Snacks, 28 Jun 2026. Customer ordered **Fafda 250g, Sev Khamni 250g, Samosa 4pcs,
Khaman 250g**. The bot's *summary* was correct, but the placed order (ORD-86) was **Fafda 1 Kg,
Ratlami Sev 1 Kg, Samosa ×4, Khaman 1 Kg** — total computed correctly against the wrong variant.

## Root cause
The AI agent (`AiBrain`) emits a hidden `<<ORDER {…}>>` line whose schema carried only name+qty —
**no weight** — so `OrderCalculator` priced each product's default (1 Kg) variant. The deterministic
path already priced weight via `WeightPricer`; the AI path never carried the grams.

## The fix (surgical, reuses existing logic, now versioned + extensible)
1. **Protocol v2 + versioning** — `AiBrain::ORDER_PROTOCOL_VERSION = 2`. Each item now carries a
   general `quantity {value, unit}` rather than a one-off `grams` field, so future units (kg, ml,
   dozen, pcs, pack) need no further protocol change. The `<<ORDER>>`/`<<QUOTE>>` blocks include
   `"version":2`.
2. **`QuantityNormalizer`** (pure, beside `WeightPricer`/`WeightParser`) maps `{value,unit}` →
   `{qty, weight_grams}`: g/kg → grams (qty 1); pcs/pack/dozen/unknown → a count, **never** a
   weight. New units are added here only.
3. **Back-compatible parser** — `AiBrain::extractOrderItems()` is JSON-first (the block is normally
   valid JSON) with a tolerant regex fallback for truncated/malformed blocks. It accepts v2
   `quantity{value,unit}`, the interim `grams`, and the legacy `qty` shape — so older prompts and
   replay fixtures keep working.
4. **Pricing** — `OrderCalculator` prices `weight_grams` through the **same `WeightPricer`** as the
   deterministic path (exact merchant variant → pro-rata → never the default).

## Regression contract
- `QuantityNormalizerTest` — weight units → grams; **pieces/dozen/pack never get a weight** (the
  mixed-order protection you asked for).
- `OrderCalculatorWeightTest` — 250g priced at the 250g price not 1 Kg; no-grams uses base; and a
  **mixed order** (Fafda 250g + Samosa ×4) prices weight and pieces independently.
- `tests/Fixtures/order_replays.json` — ORD-86 added as a labeled replay case.
*(DB feature tests run under `php artisan test`; pure tests verified in-sandbox.)*

## Metric to verify after deploy (your success criterion)
Watch the `WEIGHT` disagreement category in the Observatory. A correct fix shows: **WEIGHT
disagreements drop**, **no rise in VARIANT disagreements**, **no rise in clarification rate** for
weighted products. When that holds, advance the ORD-86 shadow diff to `verified`.

## Data note
If a sold-by-weight product has neither a matching weight variant nor a `reference_price`, pricing
still falls back to base — set `reference_price` (+ `reference_weight_grams`) or add variants. Data,
not code.

---

## Hardening added before deploy (review round 2)

### Negation replay
`tests/Fixtures/order_replays.json` now includes a negation case:
`250g Fafda / 250g Vanela / No Jalebi` → Fafda + Vanela present, **Jalebi absent**.
`EntityExtractor` gained a `stripNegations()` pre-filter ("no X", "without X", "skip X") that is
scoped to a single line, so it can't swallow the next item in a multi-line order (verified:
`No Sev\nKhaman 250g` keeps Khaman).

### Protocol version on telemetry
`protocol_version` is now stamped on every `order_flow_metrics` and `shadow_diffs` row (from
`AiBrain::ORDER_PROTOCOL_VERSION`). If disagreement rates shift after a deploy, they can be
correlated to the protocol version directly instead of guessing between router / resolver /
pricing / prompt. Migration `2026_06_28_000017`.

## Deployment checklist
- [ ] Replay tests pass (ORD-86, mixed-unit, negation).
- [ ] Shadow mode enabled.
- [ ] Protocol version logged (column populated).
- [ ] `WEIGHT` disagreement rate monitored daily.
- [ ] `VARIANT` disagreement rate monitored (no regression).
- [ ] Clarification rate for weighted products monitored.
- [ ] First few weighted orders reviewed manually before widening rollout.
- [ ] When stable: advance ORD-86 on the Confidence Ladder to `verified`, then pick the next
      highest-impact disagreement category.
