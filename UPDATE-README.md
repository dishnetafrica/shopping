# ShopBot update — Special Order + Patronics quotation (VAT-inclusive fix) + Expert AI (text/voice/photo)

Extract at the repo root; commit & push.

## Highlights
- AiBrain: full-catalogue knowledge + consultative selling (problems/loads) + browse-by-category
  + photo/vision handling (reads a product's model/part number and matches the catalogue).
- QuotationService: Patronics layout + **VAT-inclusive mode** (no double VAT — see below).
- Special Order (marketplace) + provider architecture.

## VAT-inclusive fix (IMPORTANT)
Patronics' catalogue prices already INCLUDE VAT (price list = "Inclusive VAT"). The quotation was
adding 18% on top = double VAT. New setting `prices_include_vat` fixes it:
- ON  -> the catalogue price is treated as VAT-inclusive; the quote shows net unit prices, breaks
         out VAT, and the TOTAL equals the VAT-inclusive price (matches the website & Patronics'
         own quote form). Verified: reproduces quote 211213 exactly (grand 2,778,616.80).
- OFF (default) -> catalogue prices are net; VAT is added on top (unchanged old behaviour).
Chat/website prices were always correct (they show the inclusive price directly). Only the
formal quotation PDF was affected.

    $t = \App\Models\Tenant::find(7);
    $t->putSetting('prices_include_vat', true);   // Patronics: prices include VAT
    $t->putSetting('quote_vat_pct', 18);          // already set

## Deploy — env (EasyPanel) then restart
    PRICE_API_URL=https://price.bentleywalkers.com
    PRICE_API_KEY=dishnet-price-2026
    MARKETPLACE_DEFAULT_PROVIDER=jumia
    MARKETPLACE_DEFAULT_MARGIN=0.15
    # APP_DEBUG=false

## text / voice / photo (AI-mode)
- TEXT: expert/consultative + catalogue search.
- VOICE: transcribed (Whisper) then identical to text.
- PHOTO: vision reads visible model/part numbers -> catalogue match; no label -> asks the rating.
  Quality scales with OPENAI_MODEL (gpt-4o-mini ok for labels; gpt-4o better for unlabeled photos).

## Test (tenant 7)
1. "power keeps tripping in my house" -> diagnosis + recommendation
2. "I have 10 fans, which breaker?" -> load estimate + MCB/RCCB + quote
3. Voice note of #2 -> same
4. Photo of a Schneider breaker with visible part number -> reads it, finds it, quotes
5. Ask for a formal quotation on a few items -> VAT total must MATCH the website price (no double VAT)
6. "menu" -> category list with counts
