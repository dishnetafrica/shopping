# ShopBot update — Special Order + Patronics quotation + Expert AI selling

Extract at the repo root; commit & push.

## Modified / new (highlights)
- app/Services/Bot/AiBrain.php  — AI product knowledge + EXPERT/CONSULTATIVE selling:
    * CATALOGUE RANGE overview (whole catalogue by category, ~900 tokens) so the bot knows its full breadth
    * handles customers who describe a PROBLEM or a LOAD, not a SKU (diagnose / size / recommend / quote)
    * BROWSE BY CATEGORY flow ("menu"/"categories" -> pick a category -> items)
    * configurable in-prompt detail via `ai_catalogue_limit` (default 200, max 600)
- app/Services/Bot/QuotationService.php  — Patronics-style quotation (form code, Disc %, VAT block, terms, signatures)
- config/marketplace.php + app/Services/Marketplace/**  — Special Order (live off-catalogue pricing)
- app/Services/Bot/BotBrain.php, ProductImageResponder.php, AppServiceProvider.php, Filament/Pages/Settings.php

The CODE enables expert behaviour; the PERSONA text below is what gives it electrical-domain knowledge.
Set both.

## Deploy — env (EasyPanel) then restart the service
    PRICE_API_URL=https://price.bentleywalkers.com
    PRICE_API_KEY=dishnet-price-2026
    MARKETPLACE_DEFAULT_PROVIDER=jumia
    MARKETPLACE_DEFAULT_MARGIN=0.15
    # APP_DEBUG=false

## Patronics tenant settings (tinker) — makes the bot an electrical specialist
    $t = \App\Models\Tenant::find(PATRONICS_ID);
    $t->putSetting('vertical', 'manufacturer');
    $t->putSetting('ai_catalogue_limit', 400);

    $t->putSetting('ai_persona',
      "You are the sales assistant for Patronics Services (U) Ltd, a wholesale & retail supplier of ".
      "genuine Schneider Electric and industrial electrical products in Kampala, Uganda. We stock MCBs, ".
      "MCCBs, RCCBs/RCBOs, contactors, overload relays, motor protection, isolators, timers, enclosures ".
      "& distribution boards, cable management, switchgear and wiring devices. Customers range from ".
      "homeowners to electricians and contractors, and often use part numbers (e.g. LC1D32P7, A9F74101).\n\n".
      "You are also a PRACTICAL ELECTRICAL ADVISOR:\n".
      "- If a customer describes a PROBLEM (lights/power tripping, a breaker that won't hold, an ".
      "overheating motor, a socket with no power, frequent blackouts), diagnose the likely cause in ".
      "plain language (overload / short circuit / earth leakage / undersized breaker / loose connection) ".
      "and recommend the right protective device or part.\n".
      "- If a customer gives a LOAD (e.g. \"10 ceiling fans\", \"a 3 HP motor\", \"wiring a 3-bedroom ".
      "house\"), estimate the current using standard assumptions (ceiling fan ~70W; 1 HP ~0.75kW; ".
      "single-phase 230V; three-phase 400V) and recommend the correct MCB rating and curve, an RCCB ".
      "(30mA for shock protection), a contactor if it's motor/switching, and a sensible cable size.\n".
      "- Prefer C-curve MCBs for general and motor loads; suggest a TeSys contactor + thermal overload ".
      "for motors; always ground the final recommendation in a REAL Schneider item from the catalogue ".
      "and offer a quotation. Never invent a spec, rating, model or price. If unsure of a detail, ask ".
      "ONE short question. Keep replies WhatsApp-short and practical. Prices are UGX, VAT applies, and ".
      "formal quotations are available; for items not stocked, offer a special order.");

    $t->putSetting('brand_knowledge',
      'Patronics Services (U) Ltd . Plot 99, 6th Street Industrial Area, Kampala . TIN 1000038646 . '.
      'Class X Electrical Engineer & Contractors . genuine Schneider distributor . wholesale & retail.');

    // quotation branding
    $t->putSetting('quote_form_code','PSUL-FR-F36');
    $t->putSetting('tin','1000038646');
    $t->putSetting('quote_vat_pct',18);
    $t->putSetting('quote_discount_pct',20);
    $t->putSetting('sales_employee','Amos Wakapisi');
    $t->putSetting('quote_prepared_by','Davis');
    $t->putSetting('quote_subtitle',"Class 'X' ELECTRICAL ENGINEER & CONTRACTORS\nWHOLESALER/ RETAILER: INDUSTRIAL & DOMESTIC ELECTRICAL PRODUCTS\nPLOT NO. 99, 6TH STREET INDUSTRIAL AREA- KAMPALA\nPhone: +256 750 558160");
    $t->putSetting('website','www.patronicsservices.com');
    $t->putSetting('public_email','sales.ug@patronicsservices.com');

    // special order
    $t->putSetting('special_order_enabled', true);
    $t->putSetting('special_order_margin', 0.15);

## Test scenarios to run on WhatsApp (what "good" looks like)

1) NORMAL USER, problem:
   You: "hi, power keeps tripping in my house, what could be the issue?"
   Expect: a short diagnosis (overload / short / earth leakage), one clarifying question
   ("does it trip when a certain appliance is on?"), and a recommendation to fit a 30mA
   RCCB + correctly-rated MCB, then "want me to suggest the exact parts and a price?"

2) LOAD SIZING ("10 fans"):
   You: "I have 10 fans to wire, which breaker do I need?"
   Expect: it estimates load (~10 x 70W ~ 700W ~ 3A at 230V), recommends a 1P 6-10A
   C-curve MCB on 1.5mm2 cable + a 30mA RCCB for the board, names Schneider Easy9/Acti9
   items from the catalogue, and offers a quote.

3) ELECTRICIAN, by part number:
   You: "price for LC1D32P7 and 25 pcs EZ9F56363"
   Expect: it finds both in the catalogue and offers a formal quotation (Patronics PDF).

4) BROWSE:
   You: "menu"  ->  it lists the categories with counts and asks you to pick one
   You: "MCCB"  ->  it lists a few MCCBs and asks the rating/poles you need.

5) OFF-CATALOGUE (special order):
   You: "do you have a Hikvision 4-channel DVR?"
   Expect: not in catalogue -> special-order flow (or "the shop will confirm"), never a made-up price.

Notes: the CATALOGUE RANGE overview is cached ~5 min per tenant (refreshes after imports).
The bot's expertise = persona text + catalogue breadth. Refine the persona to sharpen it.
