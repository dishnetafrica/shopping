# Location Intelligence — Kampala & Juba area awareness

Recognises neighbourhood names so they are treated as a DELIVERY LOCATION (not a
product), and uses the canonical name to drive zone matching / fee / ETA.

## What changed
- **NEW app/Services/Bot/LocationDictionary.php** — canonical Kampala + Juba areas,
  common misspellings/alternative spellings, and detection:
  - `detect()` → {area, city, match} for a known area (canonical or misspelling).
  - `looksLikeLocation()` → true for a known area, or a location cue + landmark.
  - `canonicalize()` → returns the canonical area ("Kisasi" → "Kisaasi"), used for zones.
- **IntentClassifier.php** — new `LOCATION` intent. A STRONG product signal (catalogue
  word / qty+unit / shop verb) still wins, but a location is now recognised BEFORE the
  weak "short bare term" rule that previously made area names (e.g. "Munyonyo") search
  the catalogue. ("deliver" was removed from shop-verbs — it's a delivery cue; a real
  "deliver milk" still matches via the product word.)
- **BotBrain.php** — a `LOCATION` case stores the area on the conversation and confirms
  (no product search), showing a fee/ETA preview if the cart has items. `placeOrder`
  now canonicalises the checkout location (so misspellings still match zone keywords) and
  falls back to a previously volunteered area if the checkout reply names none.
  Delivery location is kept in the conversation state (no migration).

## How it behaves
- "Am in Kisaasi" / "Upper Mawanda" / "Near Total Ntinda" / "Munyonyo" / "Deliver to
  Jebel" → recognised as location, not searched; stored for the order.
- Works with only an area name (no full address).
- A misspelling like "Kisasi" is normalised to "Kisaasi" before zone matching, so the
  right zone fee/ETA is used instead of the fallback.

## NOTE — supersedes cloudbss-commerce-bugfix.zip
BotBrain.php here already includes the commerce-bug fixes, and CatalogueMatcher.php +
ShoppingEngine.php are bundled too. Deploy THIS set (5 files) and you have both the
commerce fixes and location intelligence. No migration. `php artisan optimize:clear`.

## Adding areas / spellings later
Edit KAMPALA / JUBA (canonical) and ALIASES (misspelling → canonical) in
LocationDictionary.php. For the fee to differ by area, the owner still defines delivery
zones (Dispatch ▸ Delivery Zones) with those area names as match keywords — the
dictionary just makes the customer's text match reliably.

## Verified here
location_suite 34/34; no regression: intent 47/47, brain 63/63, commerce-bugs 16/16,
defaults 18/18, delivery 31/31, decline 15/15, final 25/25. PHP lints clean.
(LocationDictionary / IntentClassifier are pure and unit-tested; the BotBrain LOCATION
case + checkout normalisation run through the framework — confirm live on WhatsApp.)
