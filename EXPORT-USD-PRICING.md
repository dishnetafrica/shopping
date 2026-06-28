# Export / USD quoting fix (EuroPearl, 28 Jun 2026)

A real conversation exposed a cluster of quoting bugs. Root cause of the main one is the same family
as the inventory bug: **the bot had the facts and didn't use them.** The USD rate (3,675) and the
South Sudan markup were configured in Settings, but the bot's quote path never read them and the LLM
was never told they exist — so it claimed it "needs to confirm the exchange rate," quoted plain UGX
for an export order, and ignored the Juba markup.

## What changed

### 1. One pricing formula, shared (`App\Services\Bot\Pricing\CurrencyPricer`)
The panel already priced Juba/USD correctly; that formula now lives in one service the **bot and the
panel both call**, so they can't drift:
`USD unit = base_UGX × (1 + ssMarkupPct/100) ÷ usdUgx`, rounded to the nearest $0.50. Markup is the
South Sudan markup with a per-client override (`customer_profiles.ss_markup_pct`).
`PanelApiController::quotationSend()` was refactored to call it (faithful extraction, no behaviour
change).

### 2. The bot quotes USD when it should
`captureQuote()` now detects USD/export intent — explicit "USD/dollar/export" ask, a Juba / South
Sudan mention, or a +211 number — across the **conversation** (current message + customer address +
recent inbound), and prices the quote in USD via the pricer. The configured rate + the export rule
are injected into the prompt, so the LLM stops saying it lacks the rate.

### 3. Juba caveat
For South Sudan the caption states the USD price is **ex-Kampala; clearance & transport confirmed
after the order** — not an invented transport figure.

### 4. No more invented discounts
A prompt rule forbids promising any discount, "bulk price" or "special price" that isn't actually
configured. (If you want real qty tiers, that's a defined rule you give me — not an LLM guess.)

### 5. No duplicate quotes
A 30-minute signature cache per (tenant, customer) stops a second PDF for the same cart — adding an
address or saying "confirm" no longer spawns a new quote number; the bot points back to the open one.

### 6. Smaller fixes
- **Message order:** the preamble text is now sent **before** the PDF, not after.
- **Price-list spam:** the price-list PDF won't re-send to the same customer within 10 minutes.

## Verification
Pure math + detection smoke 7/7: 200 boxes @ 70,000 UGX → **USD 3,800** at 0% markup, **USD 4,800**
at 25%; USD/export/Juba detection correct on every line of the real chat.
`tests/Feature/Bot/CurrencyPricerTest.php` is the regression contract; the production case is recorded
in `tests/Fixtures/quote_pricing_replays.json` (`prod-EUR-Q260628`). Pricing replays live here, not
in `order_replays.json`, which is order-extraction only.

## Not built (deliberately)
Real tiered bulk discounts — a capability needing your actual qty/% breaks, not a bug fix. The bot now
tells the truth ("price as listed") until you define them.
