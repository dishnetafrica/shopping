# CloudBSS bot — complete set (commerce + intents + cart + multilingual greetings)

Cumulative. The 10 files in app/Services/Bot/ are the full current bot and supersede all earlier
bundles. No migration. Deploy = push files + `php artisan optimize:clear`.

## NEW in this build (greeting follow-ups)
Two real failures fixed:

1. **Greeting during a clarification** — "Umeze ute?" / "مساء الخير" arrived while a numbered
   option list was pending, so the bot replied "reply with the number…". Now a greeting
   mid-clarification greets back AND keeps the options live ("Your options are still above 👆
   reply with the number when you're ready"). A bare number still resolves the selection.

2. **Arabic-script greetings** — "مساء الخير" (good evening), "صباح الخير", "السلام عليكم",
   "مرحبا", "سلام", "كيفك", "أهلا" now recognised. Normalisation is Unicode-aware (keeps Arabic
   letters, strips harakat/tatweel, unifies alef/ya variants), so spelling variants match.
   Also added Swahili "Umeze ute" / "umeze" (NOTE: I'm inferring this is a greeting from context
   — please confirm the language/meaning; it's harmless since it's not a product).

## Multilingual greeting layer (GreetingDictionary.php)
Runs BEFORE product search and matches the WHOLE message, so "Habari" greets while "Habari Salt"
shops. Languages: English, Swahili, Luganda, Juba Arabic (romanised + script), India
(Namaste / Jai Shree Krishna). Small-talk ("how are you", "are you there") greets;
thanks (asante, webale, shukran) -> THANKS. Localised replies (Habari/Karibu, Bulungi, Salaam).
Buckets are data-only -> easy to make tenant/country-configurable later.

## Carried forward
Price questions ("how much is X") answer a price (delivery-price -> business); "you don't have
big size" -> size follow-up; "do you sell X" stays a search; clear "we don't stock X" on a miss;
Cart Management Engine (numbered basket; remove/clear/change by number or name); follow-up
phrasing & typos; business intent; Bugs 1–7.

## Files (deploy all 10)
BotBrain, IntentClassifier, ShoppingEngine, CatalogueMatcher, LocationDictionary, CartCorrection,
CategoryDictionary, FollowUp, CartEditor, GreetingDictionary.

## Tests — 453 assertions, 0 failures
greeting 51 · cart 41 · followup 49 · realcustomer 49 · intent 61 · location 34 ·
commerce-bugs 16 · phase-1 63 · defaults 18 · delivery 31 · decline 15 · final 25.

## Honest scope
Dictionary + classifier are unit-tested directly. The reply + state-keeping path runs through
Laravel/Conversation — confirm live with: مساء الخير and Umeze ute? while a clarification list is
showing (should greet + keep options), and the bare greetings Habari/Mambo/Salaam.

## STILL OUTSTANDING (not code): silent-bot incident
Earlier "Why are you replying" transcript = the bot stopped responding mid-session. That is infra
(queue worker / Evolution WhatsApp session / uncaught exception), not a classifier bug. Check the
worker, the WhatsApp instance connection, and storage/logs/laravel.log.
