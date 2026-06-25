# Quotations — photos + POS send + log (cumulative)

One ZIP for the whole quotation feature. Supersedes panel-quotation-send.zip.

## What you get
1. PDF quotation with a PHOTO next to each item (bot + panel).
2. POS button "📄 Send quotation on WhatsApp" — staff add items + customer phone, it sends a
   branded PDF quote to that customer's WhatsApp.
3. NEW: a "Quotations" page in the panel side menu — lists every quotation sent (bot or POS),
   newest first, with Date / Quote No / Customer / Details / Source, and a Download button to
   re-open the PDF.

## Files (5)
- resources/panel/seller.html                       — POS button + Quotations page + logic.
- app/Http/Controllers/Panel/PanelApiController.php  — quotationSend() + quotations() endpoints.
- routes/web.php                                     — papi/quotation-send + papi/quotations.
- app/Services/Bot/OrderCalculator.php               — quote lines carry the product image.
- app/Services/Bot/QuotationService.php              — PDF photo column.

## PREREQUISITE: dompdf
Still required for any PDF. Install (note: NO -W, and pin css-selector to v7 so it stays
PHP-8.3 compatible):
    composer require dompdf/dompdf:^3.0 "symfony/css-selector:^7.2"
Then commit composer.json + composer.lock so the EasyPanel build keeps it.

## Deploy
1. Unzip at the repo ROOT (overwrites the 5 files).
2. Install + commit dompdf as above.
3. git push -> EasyPanel rebuild -> php artisan optimize:clear
4. Panel -> side menu -> Quotations (to view the log); POS -> Send quotation (to send one).

## How the log works
It reads sent-quotation messages from the message log and re-links the stored PDF
(saved at storage/app/public/quotations/<tenant>/Quotation-<no>.pdf). If a PDF file was
cleared, the row still shows but says "no file" instead of a download link.

## Rollback
Restore the 5 files from the previous version and redeploy.
