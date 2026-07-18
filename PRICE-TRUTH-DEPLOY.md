# PRICE-TRUTH-DEPLOY.md — the $67 vs $47.90 bug: a live revenue leak, fixed

## What your two screenshots caught
Products carry TWO price columns: `price` (what the panel shows and edits) and
`base_price` (a legacy mirror the canonical write path always kept equal). Some edit
paths update `price` alone → the fields drift → and FOUR selling paths preferred the
STALE `base_price`:

| Where | Effect until now |
|---|---|
| **Storefront** | familyshoppers.net sold at the old price ($47.90) — your screenshot |
| **WhatsApp bot** | the bot ALSO quoted & charged the old price |
| Quotation revise | revised lines re-priced at the old price |
| Invoice re-price | same |

Every price you raised in the panel was silently NOT raised for customers. That is
real money on every drifted product.

## The fix (three layers)
1. **Selling paths now read `price` first** (all four flipped, one line each).
2. **Drift is structurally impossible**: a Product model saving() hook syncs
   base_price = price on every save, whichever screen edits it (Filament, papi, v3,
   imports) — pinned by test.
3. **COGS corrected**: the order ledger computed "cost" from base_price — which
   mirrors the SELLING price, so margins there were meaningless. It now uses the real
   `cost_price` field.

## Deploy + the repair (DO BOTH)
1. Upload → Rebuild → `php artisan test --filter=PriceTruth`
2. **`php artisan products:price-sync 17 --dry`** → read the list: every drifted
   product with old → new (expect #65498: 47.90 → 67.00; there may be more — this
   list is your leak report)
3. `php artisan products:price-sync 17` → syncs + clears the storefront and bot
   catalogue caches — live immediately
4. Refresh familyshoppers.net/#p65498 → **$67.00**

## Files (8)
StorefrontController · OrderCalculator · QuotationReviseService · InvoiceService
(precedence flips) · OrderLedgerService (COGS→cost_price) · Product model (saving
sync) · ProductPriceSyncCommand (NEW) · PriceTruthTest (3 tests)
