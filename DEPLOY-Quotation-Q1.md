# Quotation Experience — Phase Q1 (Instant Quotation + PDF + Live Portal)

Additive only. Reuses the existing quotation engine (QuotationWriteService, QuotationService,
QuotationConvertService). No existing logic changed. Terminology is "Quotation" throughout.

## Files
- database/migrations/2026_07_16_000001_add_portal_fields_to_quotations.php  (NEW — adds public_token, delivery_address, remarks, sales_exec_id, viewed_at, accepted_at)
- app/Models/Quotation.php                                   (MOD — fillable + auto public_token on create + portalUrl/isOpen helpers)
- app/Http/Controllers/Storefront/StorefrontController.php   (MOD — requestQuote() + quoteUrl in shop config)
- app/Http/Controllers/Storefront/QuotationPortalController.php (NEW — show/data/pdf/share/approve)
- routes/web.php                                             (MOD — /quote + /q/{token}(/data|/pdf|/share|/approve); reserved q/quote slugs)
- resources/storefront/shop.html                             (MOD — checkout becomes "Request Quotation" + quotation-ready screen)
- resources/storefront/quotation.html                        (NEW — the customer portal page)

## Deploy
1. Copy files over (preserving paths).
2. php artisan migrate            # applies the additive columns
3. No build step (storefront is static HTML served with no-store).
4. (Optional) php artisan route:clear && php artisan config:clear

## What it does
- Storefront "Request Quotation" → POST /{shop}/quote → QuotationWriteService::createFor()
  prices via OrderCalculator, renders the branded PDF, persists Quotation+items, sends WhatsApp.
- Customer gets an instant quotation-ready screen (number, total, validity) with a portal link + PDF.
- Public portal at /{shop}/q/{token}: view items/images/totals/timeline, Download PDF, Share
  (WhatsApp/Email), Approve. Approve → QuotationConvertService::convert() (one-click → Sales Order,
  idempotent). Everything renders from the one Quotation record (portal, PDF, WhatsApp stay in sync).

## Rollback
Restore the previous versions of the 5 modified files, delete the 2 new files, and
`php artisan migrate:rollback` (drops only the added columns). Zero data risk — the order flow
(placeOrder) is untouched and remains available.

## Scope note (next phases)
Q2 = immutable revision engine + customer/sales edits + visual diffs. Q3 = professional PDF
upgrade (per-line thumbnails/brand/variant/discount/tax, QR to portal). Q4 = AI sales suggestions,
smart alternatives, stock-aware pricing, delivery estimation.
