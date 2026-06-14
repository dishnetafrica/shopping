# Real-customer commerce accuracy fixes

Four reported bugs + one latent size bug found while fixing them. No migration.

## BUG 1 (highest) — a clarification selection must never become a quantity
"Sikandar peanuts ... → 2" was adding 200 items. Cause: a size in the query (e.g.
"200g") was carried into the clarification group as its quantity, so picking an option
multiplied by 200. Fix: a clarification's quantity now comes from an EXPLICIT count only
("2 sikandar peanuts" → 2); a size token is the thing being clarified, never a quantity,
so a plain pick adds 1. Replying 1/2/3 always selects an option.

## BUG 2 — quantity correction, no product search
"make it 1", "only 1 pkt", "one packet only", "change to 2" now update the LAST cart
item's quantity instead of searching. Detection is in a pure CartCorrection class (cue
word + a number). A correction with an empty basket gets a gentle "basket is empty" — it
never product-searches. Messages like "2 milk" or "I want 2 milk" are NOT corrections.

## BUG 3 — size availability
"salted bread 250gm" when only 500g / 1kg exist now answers:
"📏 250g isn't available — we have 500g, 1kg" and still lists the options to pick from,
instead of silently re-showing the same list.

## BUG 4 — catalog intent
"menu", "whole menu", "catalog", "catalogue", "price list", "what do you sell" → a catalog
response (categories for a big catalogue, otherwise an item+price list). Never searched.

## BONUS — latent size bug fixed (CatalogueMatcher::normSize)
normSize stripped trailing zeros from whole numbers, so "500g"→"5g", "250g"→"25g",
"100ml"→"1ml", and "50g"/"500g" collided. Now zeros are trimmed only after a decimal
("2.0kg"→"2kg") while "500g" stays "500g". This sharpens all size matching, not just the
new size note.

## Files (cumulative — supersedes cloudbss-commerce-bugfix.zip and
## cloudbss-location-intelligence.zip; deploy THIS set)
- app/Services/Bot/BotBrain.php          (catalog case, quantity-correction, menu→catalog)
- app/Services/Bot/IntentClassifier.php  (CATALOG intent)
- app/Services/Bot/ShoppingEngine.php    (selection qty = count only, size_unavailable)
- app/Services/Bot/CatalogueMatcher.php  (normSize fix)
- app/Services/Bot/LocationDictionary.php (location intelligence, unchanged this round)
- app/Services/Bot/CartCorrection.php    (NEW — pure correction detector)
Deploy: push the 6 files, `php artisan optimize:clear`. No migration.

## Verified here — required tests pass (qa/realcustomer_suite.php, 34/34):
Sikandar peanuts → option 2 = ONE item (never 200); "I want only 1 pkt" → qty update,
no search; "salted bread 250gm" → size-aware reply; "send me whole menu" → catalog intent.
No regression: brain 63/63, intent 47/47, location 34/34, commerce-bugs 16/16,
defaults 18/18, delivery 31/31, decline 15/15, final 25/25.

Pure services are unit-tested directly. The BotBrain catalog reply + quantity-correction
cart mutation run through the framework — confirm live on WhatsApp.
