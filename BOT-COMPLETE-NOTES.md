# CloudBSS bot — complete set (commerce + intents + cart + greetings + sessions + shop-start)

Cumulative. The 10 files in app/Services/Bot/ are the full current bot and supersede all earlier
bundles. No migration. Deploy = push files + `php artisan optimize:clear`.

## NEW — Shopping-Start intent
"Have an order to make" was searched -> "we don't stock have an order to make". Now an
intent-to-shop message is recognised BEFORE product search and gets a friendly prompt:

  🛒 Great! What would you like to order today?
  Examples: *Rice 5kg*, *Sugar 2kg*, *Milk 1 litre*, *Bread 2 pcs*.
  You can send several items in one message.

Covers: have/make/place an order, can i order, want to shop / start shopping, want/need to buy,
need groceries/supplies/stock/products/items, etc. Whole-message match only, so "I want to order
rice" / "can i order milk" still search the product, and bare "order" / "place order" stay
checkout. Required tests pass: "Have an order to make", "Need groceries", "Want to shop",
"Can I place an order".

## Carried forward
Session expiry & cart recovery (10-min idle; continue-vs-fresh prompt; 24h TTL) ·
fuzzy cross-match guard (Shell never matches Hello — current code already blocks it) ·
multilingual greetings (EN/Swahili/Luganda/Juba-Arabic incl. script/India; greet beats a pending
clarification) · price questions · "big size" follow-up · "do you sell X" + clear miss reply ·
Cart Management Engine (numbered basket; remove/clear/change by number or name) · follow-up
phrasing & typos · business intent · Bugs 1–7.

## Files (deploy all 10)
BotBrain, IntentClassifier, ShoppingEngine, CatalogueMatcher, LocationDictionary, CartCorrection,
CategoryDictionary, FollowUp, CartEditor, GreetingDictionary.

## Tests — 489 assertions, 0 failures
session 22 · greeting 51 · cart 41 · followup 49 · realcustomer 49 · intent 75 · location 34 ·
commerce-bugs 16 · phase-1 63 · defaults 18 · delivery 31 · decline 15 · final 25.

## Honest scope
Classifier logic unit-tested directly; the reply path runs through Laravel — confirm live with
"Have an order to make", "Need groceries", "Want to shop", "Can I place an order" (friendly prompt,
no search), and that "I want to order rice" still adds rice. This transcript ran on old code; not
live until deployed.

## STILL OUTSTANDING (not code): silent-bot incident
Unchanged — infra (queue worker / Evolution WhatsApp session / uncaught exception). Check the
worker, the WhatsApp connection, and storage/logs/laravel.log.
