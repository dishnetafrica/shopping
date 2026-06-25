# Send a quotation to a customer from the panel (POS) — with photos

Adds a "📄 Send quotation on WhatsApp" button to the POS screen. Staff add items to the POS
cart, type the customer's name + phone, tap the button — the system builds a branded PDF
quotation (with product photos) and sends it to that customer's WhatsApp. Reuses the same
engine the bot uses. Cumulative: includes the photo-in-quotation change too.

## How staff use it
POS -> add products to the cart -> fill Customer name + Phone -> tap "Send quotation on WhatsApp".
A toast confirms "Quotation Q-… sent to <phone> ✓".

## Files (5)
- resources/panel/seller.html                              — POS button + send logic.
- app/Http/Controllers/Panel/PanelApiController.php         — new endpoint quotationSend().
- routes/web.php                                            — papi/quotation-send route.
- app/Services/Bot/OrderCalculator.php                      — quote lines carry product image.
- app/Services/Bot/QuotationService.php                     — PDF gains a photo column.

## PREREQUISITE: dompdf (still required)
The PDF needs dompdf. If it isn't installed the button reports
"PDF engine (dompdf) is not installed yet". Install once (the -W clears the css-selector lock):
    composer require dompdf/dompdf:^3.0 -W
Commit composer.json + composer.lock so the EasyPanel build installs it.

## Deploy
1. Unzip at the repo ROOT (overwrites the 5 files).
2. Install + commit dompdf (above).
3. git push -> EasyPanel rebuild -> php artisan optimize:clear
4. Open the panel -> POS -> build a cart -> enter customer phone -> Send quotation.

## Notes
- It sends to the phone you type in POS (digits only; country code as you store numbers).
- Items are matched to your catalogue by name (same matcher the bot uses); unmatched lines
  show as "to be confirmed" on the quote.
- Works for every shop (Family Shoppers, the dhaba, EuroPearl, ...). No vertical gating.
- If WhatsApp isn't connected, it still creates the PDF and logs the link (console) instead of sending.

## Rollback
Restore the 5 files from the previous version and redeploy. The new route/button then disappear.
