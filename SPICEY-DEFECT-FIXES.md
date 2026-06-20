# Spicey Herbs — Defect Fixes (Launch Blockers Cleared)

Both HIGH defects fixed and verified by re-running the real ordering engine. Grocery tenants (Family Shoppers / Pal's) are provably unaffected.

---

## Defect 1 — USD rounded to whole dollars → FIXED

**Root cause:** the money layer assumed a zero-decimal currency (UGX). `Pricing::net()` did `round($base)` (integer) and `Pricing::money()` / the bot's checkout + reply lines used `number_format($x)` (0 decimals). UGX has no cents so it was invisible; USD lost cents *and* rounded up — and because `net()` rounds the stored line price, the customer was actually charged the rounded figure.

**Fix:** one source of truth for precision — `Pricing::decimalsForCurrency()` (0 for UGX and the zero-decimal set, 2 for USD/everything else). `net()` and `money()` now round/format to that precision; every money `number_format()` in `BotBrain` (price replies, clarify lists, delivery quotes, checkout total) takes the currency's decimals; the engine's own `money()`, the seller dashboard revenue, and the POS view too. GPS coordinate formatting was left alone.

**Verified (real engine, USD):**
```
"2 Butter Chicken"  → USD 19.00   (was "USD 19", line price 9.50×2)
"Chicken Biryani"   → USD 8.50    (was "USD 9")
"Paneer Tikka"      → USD 7.00    (was "USD 7" — fine, but now correct by rule)
```
Regression: UGX still formats `UGX 9` (0 decimals) — Family Shoppers unchanged.

## Defect 2 — items silently dropped in multi-message flows → FIXED

**Root cause:** the engine is built clarify-and-wait, which is correct for a supermarket. Two paths dropped restaurant lines:
1. A **bare** dish name ("Chicken Biryani") has no add-verb or quantity → `add_intent=false` → the engine *shows options* instead of adding.
2. An **ambiguous** name ("Paneer Tikka" → *(Dry)* vs *Masala*) returns a clarify list even *with* a quantity.
In both cases the item sat as a pending pick, and the customer's next message ("Add 2 Garlic Naan") processed a new line and the pending pick was abandoned — silently.

**Fix:** a **gated restaurant mode** (`tenant setting restaurant_mode`, default **off**). When on, the engine:
- auto-adds a confident single match even without an add-verb/quantity, and
- resolves an ambiguous match to the **best-ranked candidate** (shown in the reply, so it's correctable) instead of dropping it.

Grocery tenants keep `restaurant_mode` off → byte-identical behaviour (verified: bare "Chicken Biryani" still browses, cart unchanged).

> Note on ambiguity: "Paneer Tikka" auto-resolves to *Paneer Tikka (Dry)* (top-ranked). The customer sees it in the basket summary and can correct. If the owner wants a specific default, add a keyword to the preferred product.

---

## Changed files
- `app/Services/Pricing.php` — currency-decimal source of truth; `net()`/`money()` precision.
- `app/Services/Bot/ShoppingEngine.php` — `restaurant` flag; auto-add confident single; auto-resolve ambiguity to best; currency-aware `money()`.
- `app/Services/Bot/BotBrain.php` — pass `restaurant_mode` into the engine; currency-aware money on every price/clarify/delivery/checkout line; precise grand totals.
- `app/Support/PanelCurrency.php` — `decimals()`.
- `app/Filament/Widgets/OrdersStatsOverview.php` — revenue with cents.
- `resources/views/filament/pages/pos.blade.php` — POS money with cents.
- `qa/spicey_flow_sim.php` — restaurant-mode harness, 6 required flows, accuracy scoring.

## Test results — `php qa/spicey_flow_sim.php`

| Test | Flow | Result |
|---|---|---|
| 1 | Chicken Biryani → Add 2 Garlic Naan → Checkout | **PASS** (both land) |
| 2 | Butter Chicken → Add Garlic Naan → Checkout | **PASS** (both land) |
| 3 | 2 Butter Chicken → 1 Paneer Tikka → 1 Veg Burger → Checkout | **PASS** (all 3 land) |
| 4 | Butter Chicken → Add Coke → Remove Coke → Checkout | **PASS** (Butter Chicken lands, Coke absent) |
| 5 | 5-item mixed order | **PASS** (5/5) |
| 6 | 10-item mixed order | **PASS** (10/10) |

Checkout fired exactly once in every flow. **No item silently dropped.**

```
ORDER ACCURACY: 23/23 requested items landed (100.0%)
ALL FLOWS PASS
```

Regression: `order_instructions` 49/49, `replay_csv` 40/40 still green; grocery mode unchanged.

## Order accuracy — final
**100%** on the six required flows, measured by running the **actual** production engine (not a match estimate) over the menu. Caveat unchanged: this is the order-construction layer. The full production end-to-end number (WhatsApp transport, queue, DB) comes from `php artisan bot:replay qa/spicey-replay-tests.csv --tenant=<id>` in your console — but the flows that were dropping items now resolve deterministically.

## One new deploy step
Set the tenant flag alongside USD (runbook step 3):
```
$t->putSetting('currency','USD'); $t->putSetting('restaurant_mode', true); $t->save();
```

## Status
Both launch blockers cleared. This clears the path to your gate: live smoke test → add beverages → 20–30 real orders → 2–3 days internal → customer launch.
