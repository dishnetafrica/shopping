# Quotation Experience — Q1–Q4 (cumulative, additive-only)

Reuses existing engines (QuotationWriteService, QuotationService PDF renderer, QuotationConvertService,
StockLedger, ProductRecommendations, BuyAgain, BuyerContext, Capability Platform). No duplication.

Q1  Instant Quotation + professional PDF + live portal (Request Quotation → /quote → /q/{token})
Q2  Immutable revision engine + customer edits + visual "what changed" diffs
Q2b Sales-side revisions through the same ledger (panel "Revise quotation")
Q3  Professional PDF upgrade (thumbnails, brand/variant, tax, FREIGHT, QR→portal, stamp/approval, page numbers)
Q4  AI-ASSISTED SALES (deterministic, stock/history-grounded — no LLM):
    - app/Services/Sales/QuotationIntelligence.php — dispatch split (ship today vs awaiting Kampala
      stock), low-stock lines, in-stock ALTERNATIVES for unavailable items, the customer's USUAL
      items (BuyAgain), VOLUME-DISCOUNT qualification (configurable tiers by segment), segment + credit.
      Every lookup is fail-soft.
    - Sales endpoint GET /papi/quotation-intel?id=  (returns the full suggestions bundle).
    - Customer SMART ALTERNATIVES in the portal: unavailable lines are badged and a "Suggested
      alternatives — in stock now" card offers a swap the customer accepts in one tap (creates a
      revision: remove unavailable + add alternative, qty preserved).

## Deploy
1. Copy files (preserve paths).
2. php artisan migrate      # three additive migrations (portal fields, revisions, freight)
3. (Optional) composer require bacon/bacon-qr-code   # enables the PDF QR image (link always shown otherwise)
4. No build step. Optionally php artisan route:clear && config:clear.
   Optional tenant setting: quote_volume_tiers = [[amount,pct],…] (defaults 2M→2%, 5M→3%, 10M→5%).

## Rollback
Restore modified files, delete new files, migrate:rollback x3. Order flow untouched throughout.

## Verified
PHP lints clean; the real PDF heredoc renders correctly; portal + storefront JS parse; customer
edit→revision→diff, sales revise, and Smart Alternatives accept→swap all tested headlessly.

## Follow-ups (small)
- Sales panel UI to render the /papi/quotation-intel JSON as a card in the QuotationCapability
  (the endpoint is ready). Q4 customer Smart Alternatives is fully wired.
