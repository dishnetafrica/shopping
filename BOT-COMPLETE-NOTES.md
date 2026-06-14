# CloudBSS bot — complete set (commerce + intents + cart management)

Cumulative. The 9 files in app/Services/Bot/ are the full current bot and supersede all earlier
bundles. No migration (cart + context live in conversation `cart`/`state` JSON).
Deploy = push files + `php artisan optimize:clear`.

## NEW in this build (from the "Biscuits" transcript)

1. **Price questions** — "how much is uganda waragi 750ml", "price of rice", "rice price",
   "how much for bread" now ANSWER the price (one match -> "*X* is UGX N", several -> a priced
   list) and never silently dump the item in the cart. Delivery-price questions
   ("how much is delivery", "delivery fee") stay a business answer.

2. **"You don't have big size"** — recognised as a *size follow-up* on the active context, not a
   literal search. After Biscuits it re-sorts biscuits largest-first instead of returning random
   "Big Size" products (Ladies Nicker, Car, Tape…). Handles the complaint/negation wrappers
   ("you don't have…", "do you have big size", bare "big"/"big size"/"large size"); "small size"
   -> smallest-first.

3. **"Do you sell / do you have X"** stays a product search (e.g. "do you sell rice" -> rice),
   while "are you open / do you deliver" stay business answers.

4. **Clear miss reply** — an unstocked product ("do you sell plastic chairs") now gets
   "Sorry, we don't stock *plastic chairs* right now" instead of a vague "didn't catch that".

## Cart Management Engine (CartEditor.php)
Numbered basket everywhere; remove by number ("remove item 1,2,3") or name ("remove Redbull");
clear ("remove everything"); change qty ("change item 2 to 5", "make Redbull 10",
"reduce Beer to 2"); confirmations ("✅ Removed: …" / "✅ Updated: …"). Handled before the
numeric-selection step so "remove item 2" edits the cart while a bare "2" is still a selection.

## Follow-up phrasing
"more items you have" / typo "itmes" / "what else do you have" continue context; "more rice"
stays a search; "more" with nothing new -> "That's everything we have for *X*".

## Carried forward
Business intent · Bug 1 selection≠qty · Bug 2 cart correction · Bug 3 size availability ·
Bug 4 catalog intent · Bug 5 high-confidence auto-resolve · Bug 6 location · Bug 7 category.

## Files (deploy all 9)
BotBrain, IntentClassifier, ShoppingEngine, CatalogueMatcher, LocationDictionary, CartCorrection,
CategoryDictionary, FollowUp, CartEditor.

## Tests — 402 assertions, 0 failures
cart 41 · followup 49 · realcustomer 49 · intent 61 · location 34 · commerce-bugs 16 ·
phase-1 63 · defaults 18 · delivery 31 · decline 15 · final 25.

## IMPORTANT — the bot went SILENT mid-transcript (read this)
From "Am meaning big biscuits" onward (checkout, waragi price, rice, wipes, plastic chairs)
there were NO bot replies, ending with the customer asking "Why are you replying". The miss path
already returns a reply, so silence is NOT a classification bug — it points to infrastructure:
  - the queue worker (ProcessIncomingMessage) stopped/crashed, or
  - the Evolution/WhatsApp instance for this tenant disconnected, or
  - an uncaught exception in the deployed handler for one input killed the job.
None of that is visible or fixable from the code here. Check on staging:
  - queue worker alive: `php artisan queue:work` running / supervisor status; `failed_jobs` table.
  - Evolution instance connected for the Family Shoppers number.
  - `storage/logs/laravel.log` around the timestamps for stack traces.
This build reduces odd outputs but cannot revive a dead worker or a disconnected WhatsApp session.
