# ShopBot update — Special Order + Patronics quotation + Expert AI (text/voice/photo)

Extract at the repo root; commit & push.

## What this update adds
- **AI product knowledge**: CATALOGUE RANGE overview (whole catalogue by category, ~900 tokens)
  so the bot knows its full breadth, not just the first 200 SKUs.
- **Consultative / expert selling**: handles customers who describe a PROBLEM ("power keeps
  tripping") or a LOAD ("I have 10 fans") — diagnose / size / recommend / quote.
- **Browse by category**: "menu"/"categories" -> pick a category -> items.
- **Photo handling (vision)**: reads a product's model/part/rating from a photo and matches the
  catalogue; if no label is legible, identifies the type and asks for the rating. (Shared prompt —
  every tenant gets it; no persona edit needed.)
- **Voice notes**: already transcribed (Whisper) and run through the same pipeline as text.
- **Patronics quotation** template (form code, Disc %, VAT block, terms, signatures).
- **Special Order** (live off-catalogue pricing via the marketplace provider architecture).

## Files
Modified: app/Services/Bot/AiBrain.php, QuotationService.php, BotBrain.php,
ProductImageResponder.php, app/Providers/AppServiceProvider.php, app/Filament/Pages/Settings.php
New: config/marketplace.php, app/Services/Marketplace/**, tests/Unit/Marketplace/**

## Deploy — env (EasyPanel) then restart the service
    PRICE_API_URL=https://price.bentleywalkers.com
    PRICE_API_KEY=dishnet-price-2026
    MARKETPLACE_DEFAULT_PROVIDER=jumia
    MARKETPLACE_DEFAULT_MARGIN=0.15
    # APP_DEBUG=false

## How text / voice / photo behave (AI-mode tenant)
- TEXT: full expert/consultative + catalogue search.
- VOICE: transcribed to text, then identical to typing. Great for electricians who talk.
- PHOTO: sent to the vision model. Reads visible model/part numbers -> exact catalogue match
  (ideal for "photo my failed part, get the same one"). No label -> identifies type + asks rating.
  NOT reverse-image search. Vision quality depends on OPENAI_MODEL:
    * gpt-4o-mini (current): reads clear labels fine; weaker on unlabeled/blurry parts.
    * gpt-4o: noticeably better product ID from imperfect photos (costs more per message).
  To upgrade vision for Patronics only, set OPENAI_MODEL=gpt-4o (or leave mini for cost).

## Patronics (tenant 7) settings — already applied if you ran the earlier block
    $t = \App\Models\Tenant::find(7);
    $t->setting('ai_persona');     // should print the electrical-advisor paragraph
    $t->setting('vertical');       // manufacturer
    $t->setting('quote_vat_pct');  // 18
    // photo handling needs NO persona change — it's in the shared prompt now.

## Test on WhatsApp (tenant 7)
1. Text: "power keeps tripping in my house" -> diagnosis + question + RCCB/MCB recommendation
2. Text: "I have 10 fans, which breaker?" -> ~700W ~ 3A -> 1P 6-10A C-curve MCB + 30mA RCCB + quote
3. Voice: say scenario 2 as a voice note -> same answer
4. Photo WITH label: snap a Schneider breaker showing its part number -> reads it, finds it, quotes
5. Photo WITHOUT label: plain breaker photo -> identifies type, asks the rating
6. "menu" -> category list with counts
7. Off-catalogue ("Hikvision 4ch DVR") -> special order, never a made-up price
