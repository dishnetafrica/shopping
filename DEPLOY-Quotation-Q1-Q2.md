# Quotation Experience — Phases Q1 + Q2 (cumulative, additive-only)

Reuses the existing engine (QuotationWriteService, QuotationService, QuotationConvertService).
Terminology is "Quotation" throughout. No existing logic changed.

## Q1 — Instant Quotation + PDF + Live Portal
- Storefront "Request Quotation" → POST /{shop}/quote → QuotationWriteService::createFor()
  (prices, PDF, persists, WhatsApp). Instant quotation-ready screen with portal + PDF links.
- Public portal /{shop}/q/{token}: view, Download PDF, Share (WhatsApp/Email), Approve→convert.

## Q2 — Immutable Revision Engine + Customer Edits + Visual Diffs
- quotation_revisions: append-only ledger (QuotationRevision is immutable — updates throw).
- QuotationReviseService: applies customer/sales ops → reprices via the FROZEN QuotationService
  (PDF, portal, WhatsApp stay in sync) → writes a new revision → refreshes the current projection.
- Portal editing: change quantities, remove, add products (from your catalogue), request changes.
  Every submit = ONE new revision. The portal shows "Revision N · what changed" visually and a
  full Revision history. Customer edits notify sales on WhatsApp.
- Sales edits are supported by the service (applySalesChange: price/discount/replace/remarks) —
  the sales-panel button to call it is the immediate follow-up (Q2b); the engine is ready.

## Files
NEW:
- database/migrations/2026_07_16_000001_add_portal_fields_to_quotations.php
- database/migrations/2026_07_16_000002_create_quotation_revisions_table.php
- app/Models/QuotationRevision.php
- app/Services/Sales/QuotationReviseService.php
- app/Http/Controllers/Storefront/QuotationPortalController.php
- resources/storefront/quotation.html
MODIFIED:
- app/Models/Quotation.php                                (portal fields, auto token, revisions relation)
- app/Http/Controllers/Storefront/StorefrontController.php (requestQuote + quoteUrl)
- routes/web.php                                           (/quote, /q/{token}/*, both domain + slug groups)
- resources/storefront/shop.html                          (checkout → Request Quotation + success screen)

## Deploy
1. Copy files (preserve paths).
2. php artisan migrate         # applies BOTH additive migrations (columns + quotation_revisions)
3. No build step. Optionally php artisan route:clear && config:clear.

## Rollback
Restore the 4 modified files, delete the 6 new files, and `php artisan migrate:rollback` twice
(drops quotation_revisions + current_rev, then the portal columns). The order flow (placeOrder)
is untouched throughout — zero data risk.

## Immutability & sync guarantees
- Revisions are append-only (ORM guard). History is never overwritten; each carries actor + diff + time.
- One source of truth: portal, PDF and WhatsApp all render from the same Quotation record; every
  change is a new revision that regenerates all three via the frozen QuotationService.
- Agreed prices are sticky: existing lines keep their quoted unit price; only added lines pull a
  catalogue price. (Freight and the pro-PDF thumbnail/brand/variant/tax columns arrive in Q3.)
