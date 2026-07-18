# PRICE-TRUTH-2-DEPLOY.md — the last three price-drift stragglers

## Your question: "is the price list the bot sends the SAME as the system?"
**YES for both price lists** — verified: the text PriceListService and the photo
CatalogRenderer both read `price` (the panel's field). The bot's order calculator
was fixed this morning.

## But the audit caught 3 more readers still preferring stale base_price:
| Where | Customer impact until now |
|---|---|
| ProductImageResponder (×2) | bot sends a product PHOTO → the CAPTION price could be the old price |
| ComboEngine | combo deals priced from the old price |
| DailyOfferService | daily offers priced from the old price |
All flipped to `price ?? base_price`. PriceTruthTest now pins ALL 8 customer-facing
readers — none may ever prefer base_price again.

## The one command that closes this forever (if you haven't run it yet)
```
php artisan products:price-sync 17 --dry     # READ the list — every line was underselling
php artisan products:price-sync 17           # sync + clear caches
```
After the sync, base_price == price on every row (and the saving-hook keeps it so),
which makes even un-audited legacy readers harmless. Code fix + data fix = belt AND braces.

## Files (4) · Deploy
3 services + PriceTruthTest → upload → Rebuild → `php artisan deploy:check` →
`php artisan test --filter=PriceTruth` → ask the bot for a photo of product #65498:
the caption must say the PANEL price.
