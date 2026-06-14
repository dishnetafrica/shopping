# CloudBSS commerce accuracy — Bugs 1–7 (complete, cumulative)

> IMPORTANT: the live site still shows Bugs 1–4 and 6 because the earlier fixes were
> never deployed. This single bundle contains ALL fixes (Bugs 1–7) and supersedes
> cloudbss-commerce-bugfix.zip, cloudbss-location-intelligence.zip and
> cloudbss-commerce-accuracy.zip. Deploy this and the live bugs go away.

## Implementation plan / what each fix does

**BUG 1 — option selection treated as quantity (the UGX 2.6M cart).**
A size in the query ("200gm") was carried into the clarification as its quantity, so
picking an option multiplied by 200. Now a clarification's quantity comes from an explicit
COUNT only; a size is the thing being clarified, never a quantity. A pending clarification
is also resolved before any other handling, so "1/2/3" is always a selection.

**BUG 2 — cart correction.** New pure CartCorrection detector ("make it 1", "only 1 pkt",
"one packet only", "change to 2", "reduce…/remove…" via cue + number). Updates the LAST
cart item's quantity; never product-searches. Empty basket → a gentle message, not a search.

**BUG 3 — size availability.** When a requested size matches no SKU, the bot answers
"📏 250g isn't available — we have 500g, 1kg" and still lists the options. Also fixed a
latent normSize bug (it stripped trailing zeros: "500g"→"5g", "50g"/"500g" collided).

**BUG 4 — catalog intent.** "menu / whole menu / catalog / price list / what do you sell"
→ a catalog response (categories for a large catalogue, else an item list). Never searched.

**BUG 5 — high-confidence auto-resolve.** When a multi-word query fully describes ONE
product that clearly leads the field (more matched words than any rival), it auto-adds even
under the clarify strategy. "Uganda Waragi Premium Pet 6pcs" → 6 × the Premium Pet SKU.
A genuinely ambiguous query ("uganda waragi", three share the lead) still clarifies.

**BUG 6 — location intelligence.** Kampala + Juba area dictionary (canonical + misspellings).
Area names ("Upper Mawanda", "Kisaasi", "Munuki", "Jebel") are recognised as delivery
locations (never searched), stored on the conversation, canonicalised for zone matching so
a typo like "Kisasi" still hits the "kisaasi" zone keyword for the right fee/ETA.

**BUG 7 — category intelligence.** New CategoryDictionary. A bare category term ("spirits",
"snacks", "soft drinks", "cleaning") lists that category's members as selectable options,
with an EXCLUDE list so "spirits" returns Waragi/Vodka/Whisky and NOT Surgical/Roll-on/
Cleaning Spirit. The match is strict (whole message = the term), so "surgical spirit" itself
is left to normal product search, and a specific product like "vodka" stays a search.

## Files (deploy all 7 + optimize:clear; NO migration)
app/Services/Bot/: BotBrain.php, IntentClassifier.php, ShoppingEngine.php, CatalogueMatcher.php,
LocationDictionary.php, CartCorrection.php, CategoryDictionary.php

## Test results (run on PHP 8.3; all green)
- realcustomer_suite      49/49   (Bugs 1–7 incl. all required tests)
- commerce_bugs_suite     16/16
- intent                  47/47
- location_suite          34/34
- phase-1 (shipped)       63/63
- defaults                18/18
- delivery                31/31
- decline                 15/15
- final regression        25/25
TOTAL 298 assertions, 0 failures.

### Required tests (all pass)
Sikandar peanuts → 2 = ONE item (never 200) · Beer → 1 adds item 1 · "I want only 1 pkt"
modifies cart · "send me whole menu" → catalog · "salted bread 250gm" → size availability ·
"Uganda Waragi Premium Pet 6pcs" → auto-resolve (6×) · "Upper Mawanda" → location ·
"spirits" → alcohol products (surgical/cleaning excluded).

## Honest scope notes
- Pure services (matcher, parser, engine, classifier, Location/Category/Cart dictionaries)
  are unit-tested directly. The catalog/category replies and the cart-correction write run
  through Laravel/Conversation — confirm those live on WhatsApp.
- Bug 2 correction applies to the LAST cart item; naming an item ("make the rice 1") is a
  follow-up if the pilot needs it.
- Bug 7 ships Spirits/Snacks/Soft Drinks/Cleaning. Add more categories by editing
  CategoryDictionary (terms/include/exclude). Best results when the owner also sets each
  product's category field.
