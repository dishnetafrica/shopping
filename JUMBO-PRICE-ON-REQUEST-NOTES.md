# Jumbo Paper + "Price on request"

You now have a **price-on-request** capability (for jumbo parent reels and any B2B item you'd rather quote
per enquiry), plus the Jumbo Paper line baked into the bot.

## How "price on request" works
Convention: **a product with a blank / 0 price is treated as "Price on request."** No migration, no flag —
just leave the price empty.
- **Storefront** — instead of a price + Add-to-cart, the card shows **"Price on request"** and a green
  **"Request a quote"** button that opens WhatsApp pre-filled with that product's name.
- **WhatsApp bot** — the catalogue lists those items as "price on request"; the bot will **not invent a
  number**. It captures the requirement (type, grade, GSM, quantity, origin) and treats it as a sales lead.
- **Totals / quotations** — a price-on-request item is never multiplied into a total; it appears as
  "to be confirmed" so your team prices it.

## The Jumbo Paper line (baked into the bot, no pasting)
The default knowledge now says EuroPearl also supplies jumbo parent reels to converters/manufacturers:
- **Types:** Toilet jumbo, Napkin jumbo, Kitchen-towel jumbo.
- **Grades:** Virgin (India / China / Vietnam), plus Blended and Recycled.
- **Pricing on request** — the bot captures the spec and routes to sales. "jumbo" now also triggers the
  buying-signal alert.

## Add the products
Import **`europearl-jumbo-paper.csv`** (7 rows, category **Jumbo Paper**, Price left blank = on request).
It's non-destructive. Upload a jumbo-reel photo per product in the panel if you have one. You can split
the virgin SKUs per origin (India / China / Vietnam) later if you want separate quote lines.

## Files
- `app/Support/BrandDefaults.php` — Jumbo Paper knowledge.
- `app/Services/Bot/AiBrain.php` — "price on request" catalogue rendering + rule; "jumbo" lead keyword.
- `app/Services/Bot/OrderCalculator.php` — price-on-request items not totalled.
- `resources/storefront/shop.html` — "Price on request" card + "Request a quote" button.
- `qa/price_on_request.php` — 10/10.

## Deploy
Pull → restart → `optimize:clear`. **No migration.** Then import the CSV and hard-refresh `/ep/shop`.

## Honest caveats
- **Any active product with a blank price will now show as "Price on request"** (before, it was hidden).
  So make sure your normal paper products all have prices — deactivate or price any half-entered ones,
  or they'll appear as on-request.
- Price-on-request items **can't be added to the cart** (no price) — that's intended; the customer
  requests a quote on WhatsApp and you/the bot follow up.
- Couldn't hit the live storefront/bot from here — after deploy, test "do you have toilet jumbo?" on
  WhatsApp (should explain + capture spec, no price) and check the Jumbo Paper tile on the storefront.
