# Quotation with product photos

Adds a photo thumbnail next to each item in the bot's PDF quotation. Works for ANY shop
(Family Shoppers included). A bulk customer who asks the bot for a "quotation" / "quote" /
"bhaav patrak" gets a branded PDF: photo, item, qty, unit price, amount, total, validity, terms.

## Files (2)
- app/Services/Bot/OrderCalculator.php  — quote lines now carry the product image.
- app/Services/Bot/QuotationService.php — PDF gains a Photo column; local images are embedded
  as base64 (reliable in dompdf), remote URLs are fetched.

## PREREQUISITE: dompdf must be installed (it isn't yet)
The PDF engine (dompdf) is not in composer.json, so today the bot falls back to a TEXT quote.
Install it once — the `-W` flag clears the symfony/css-selector lock you hit before:

    composer require dompdf/dompdf:^3.0 -W

This MUST end up committed so EasyPanel's build installs it:
  - Best: run it in your local repo clone, commit composer.json + composer.lock, push.
  - Quick test: run it in the EasyPanel shopping console — the PDF works immediately, but to
    survive the next rebuild you still need composer.json + composer.lock committed to the repo.

Once dompdf's class exists, QuotationService.available() flips to true automatically — no code
change needed; the bot starts sending PDFs (now with photos).

## Deploy
1. Unzip this at the repo root (overwrites the 2 files).
2. Install dompdf as above (commit composer.json + composer.lock).
3. git push -> EasyPanel rebuild -> php artisan optimize:clear
4. Test: message the bot e.g. "quotation for 5 cartons of <product>, 3 of <product>".
   You should receive a branded PDF with each product's photo.

## Notes
- Items with no photo simply show a blank thumbnail — layout stays clean.
- The product-catalogue cache used by the calculator refreshes within ~60s (or run optimize:clear).

## Rollback
Restore the 2 files from the previous version and redeploy.
