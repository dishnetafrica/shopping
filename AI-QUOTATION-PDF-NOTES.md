# AI bot: auto-generate & send a PDF quotation

When a customer asks for a **quotation / quote / proforma**, the AI bot now builds a branded PDF and
sends it on WhatsApp — with the same deterministic, code-computed totals (the model never does math).

## Flow
1. Customer: "can you send me a quotation for 5 cartons 300-sheet and 2 napkins?"
2. The bot replies conversationally, then:
   - **LLM extracts** the items + quantities (from their recent messages),
   - **PHP prices them** from the catalogue and totals them (`OrderCalculator`),
   - **`QuotationService` renders a branded A4 PDF** (logo, company + contact, quote no, date,
     valid-until, itemised lines, total, terms),
   - it's **sent as a WhatsApp document** with a caption: "📄 Quotation KW-Q260623-AB12 — Total
     UGX 285,000. Valid 14 days…", and logged.
3. If dompdf isn't installed (or the gateway can't send docs), it gracefully falls back to the text
   total — nothing breaks.

## Branding & terms (admin → tenant → Smart bot)
- Logo, company name, address, phone, email, website, brand colour → already used.
- **Quotation validity (days)** — default 14.
- **Quotation terms** — printed at the bottom of the PDF.
Quote numbers: `{ORDER_PREFIX}-Q{YYMMDD}-{4 random}` (e.g. `KW-Q260623-AB12`). PDFs are also saved to
`storage/app/public/quotations/{tenant}/` for your records.

## Files
- `app/Services/Bot/QuotationService.php` — PDF builder (NEW).
- `app/Services/Bot/OrderCalculator.php` — deterministic pricing (already shipped).
- `app/Services/Bot/AiBrain.php` — quotation + total intent, extraction, send.
- `app/Services/WhatsApp/EvolutionGateway.php` — `sendDocument()` (mediatype=document).
- `app/Filament/Admin/Resources/TenantResource.php` — validity + terms fields.
- `qa/quotation.php` (9/9) + `qa/order_calc.php` (11/11).

## Deploy (one extra step this time)
1. **`composer require dompdf/dompdf`**  ← the only new dependency (PDF rendering).
2. `php artisan storage:link` (if not already linked) — so saved PDFs have a public URL. The bot sends
   the PDF as base64 regardless, so this is only for the saved record copy.
3. Pull → restart → `php artisan optimize:clear`. No migration.
4. Test: "send me a quotation for 5 cartons of 300-sheet and 2 napkins" → you get a PDF on WhatsApp.

## Honest caveats
- The **math is exact**; item *matching* uses the catalogue matcher, so an ambiguous name could map
  to the wrong product — the PDF and caption both say to confirm. Tune the matcher for your SKUs after
  seeing real quotes.
- `sendDocument` is implemented for **Evolution** (your live gateway). Cloud API tenants would need the
  same method added — say the word if you ever move a tenant to Cloud.
- Can't render a real PDF or hit Evolution from here — first live quotation is the real test.
