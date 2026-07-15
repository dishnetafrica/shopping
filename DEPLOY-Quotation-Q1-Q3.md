# Quotation Experience — Q1 + Q2 + Q2b + Q3 (cumulative, additive-only)

Reuses the existing engine (QuotationWriteService, QuotationService PDF renderer,
QuotationConvertService, Capability Platform). Terminology "Quotation" throughout.

Q1  Instant Quotation + live portal (Request Quotation → /quote → /q/{token})
Q2  Immutable revision engine + customer edits + visual "what changed" diffs
Q2b Sales-side revisions through the SAME ledger (panel "Revise quotation" → /papi/quotation-revise)
Q3  PROFESSIONAL PDF UPGRADE (enhances the SHARED QuotationService renderer — all quotations benefit):
    - Per-line product THUMBNAILS (base64-embedded), Product/Brand column, Disc%, Tax, Line Total
    - Totals: Subtotal · Discount · VAT · FREIGHT · Grand Total
    - QR CODE → live portal (dependency-optional: composer require bacon/bacon-qr-code; the portal
      link is always shown so the panel is complete without the library)
    - Company Stamp + Customer Approval areas, real page numbers (@page counter), modern typography
    - generate() gained optional ($portalUrl, $freight) — 100% backward compatible.
    - Freight: new quotations.freight column; sales set it on revise; PDF + portal show it; the
      grand total includes it, so PDF and portal stay in sync.

## Deploy
1. Copy files (preserve paths).
2. php artisan migrate      # three additive migrations (portal fields, revisions, freight)
3. (Optional, enables the PDF QR image) composer require bacon/bacon-qr-code
4. No build step. Optionally php artisan route:clear && config:clear.

## Rollback
Restore modified files, delete new files, migrate:rollback x3. Order flow untouched throughout.

## Notes
- The PDF renderer change benefits bot/panel/v3 quotations too (one professional PDF everywhere);
  all new columns/rows render only when data is present, so existing quotations are unaffected.
- Verified: PHP lints clean; the actual heredoc template renders correctly (see preview); portal +
  storefront JS parse; portal renders with freight. dompdf-safe markup (tables, no flex/grid,
  DejaVu font, @page page counter, SVG QR via php-svg-lib).
- Next: Q4 — AI sales suggestions, smart alternatives, stock-aware pricing, delivery estimation.
