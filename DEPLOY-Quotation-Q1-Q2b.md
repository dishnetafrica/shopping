# Quotation Experience — Q1 + Q2 + Q2b (cumulative, additive-only)

Reuses the existing engine (QuotationWriteService, QuotationService, QuotationConvertService,
Capability Platform). Terminology "Quotation" throughout. No existing logic removed.

## Q1  Instant Quotation + PDF + Live portal (storefront "Request Quotation" → /quote → portal /q/{token})
## Q2  Immutable revision engine + CUSTOMER edits + visual "what changed" diffs
## Q2b SALES-side revisions through the SAME immutable ledger:
   - New app/Http/Controllers/Panel/QuotationReviseController.php + route POST /papi/quotation-revise
     (authed). Accepts the SAME payload the existing panel edit form posts (items JSON + remarks).
   - QuotationReviseService::applySalesLines() — full edited line set → diff → immutable revision,
     repriced via the frozen QuotationService (PDF/portal/WhatsApp stay in sync). Notifies customer.
   - QuotationCapability: the "Edit quotation" action is repointed to /papi/quotation-revise and
     relabelled "Revise quotation" (same builder form; now creates revisions instead of overwriting).
     Subtitle shows the current revision. The old /papi/quotation-update is left intact.
   - Editability aligned via Quotation::isEditable() (open AND not accepted/converted).

## Files
NEW: 2 migrations, QuotationRevision.php, QuotationReviseService.php,
     Storefront/QuotationPortalController.php, Panel/QuotationReviseController.php,
     resources/storefront/quotation.html
MOD: Quotation.php, Storefront/StorefrontController.php, Capability/Object/QuotationCapability.php,
     routes/web.php, resources/storefront/shop.html

## Deploy
1. Copy files (preserve paths).
2. php artisan migrate     # two additive migrations
3. No build step. Optionally php artisan route:clear && config:clear.

## Rollback
Restore the 5 modified files, delete the 7 new files, migrate:rollback twice. Order flow untouched.

## Guarantees
- Revisions append-only (ORM guard); full audit by one ordered query.
- One source of truth: portal, PDF, WhatsApp all render from the same Quotation; every customer OR
  sales change is a new revision that regenerates all three via the frozen QuotationService.
- Agreed prices sticky (existing lines keep quoted price; only added lines pull catalogue price).
- Sales may adjust price/discount/qty/add/remove/remarks. Freight + the professional PDF upgrade
  (per-line thumbnails/brand/variant/tax columns + QR-to-portal) are Q3.
