# CATALOGUE-DEPLOY.md — the photo catalogue IS the price list now

## What customers get
Ask the bot "price list" (English/Arabic/Swahili/Gujarati — all triggers already
existed) → they receive the **branded photo catalogue PDF**: cover page with your
logo + category index, one category per page, product cards with PHOTO, name, pack,
MOQ, price, SKU. The same brochure your panel could already download — now the bot
hands it out.

## Three delivery paths, one renderer
1. **Bot** — price-list asks send the photo version (text renderer = fallback)
2. **SalesCadence Day-3** — the enquiry cadence now mails the photo catalogue
3. **Chat composer** — new "Send photo catalogue (PDF)" action: your team fires it
   into any chat with an optional caption (takes the chat over, logs 📸 to the
   transcript)

## Performance honesty
First generation fetches every product photo (~265 images) — expect 30–60s ONCE,
then a 6-hour cache serves everyone instantly (bytes on disk, meta in cache).
Prices changed via products:price-sync? The cache refreshes within 6h; to force:
`Cache::forget('catalog_pdf:17:p')` in tinker.

## Settings
`price_list_style` = **photos** (default) | text — flip per tenant any time.
`price_list_caption` still honoured for the bot's message.

## Files (6) · Deploy
CatalogRenderer (+forTenant, cached) · AiBrain · SalesCadenceCommand ·
ChatCapability · PanelApiController · routes — upload → Rebuild →
`php artisan deploy:check` (the new gate — USE IT) → WhatsApp the bot
"price list" from any number → the photo catalogue arrives.
