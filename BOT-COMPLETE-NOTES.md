# CloudBSS bot — complete set (commerce + intents + cart + multilingual greetings)

Cumulative. The 10 files in app/Services/Bot/ are the full current bot and supersede all earlier
bundles. No migration. Deploy = push files + `php artisan optimize:clear`.

## NEW — Multilingual greeting layer (GreetingDictionary.php)
Problem: "Habari" was matched to *Habari Salt* and returned products. Now a greeting layer runs
BEFORE product search and matches the WHOLE message, so a bare greeting greets while
"Habari Salt 1kg" still shops.

Languages: English, Swahili (Kenya/Tanzania), Luganda (Uganda), Juba Arabic (South Sudan),
plus India (Namaste / Jai Shree Krishna) for the shop's Indian customers. Small-talk
("how are you", "are you there") greets too; thanks words (asante, webale, shukran) -> THANKS.

Localised replies:
  Habari    -> "Habari 😊 Karibu <shop>. What would you like today?"
  Oli otya  -> "Bulungi 😊 What can I get for you today?"
  Salaam    -> "Salaam 👋 Welcome to <shop>. How can I help you today?"
  (English keeps the tenant's custom bot_greeting if set.)

Required tests pass — Habari · Mambo · Poa · Oli otya · Gyebale · Salaam · Marhaba all greet,
none search. The buckets are data-only so they can be made tenant/country-configurable later
(Uganda, Kenya, Tanzania, South Sudan, Rwanda).

## Carried forward (this build)
- Price questions ("how much is X") answer a price, never silent-add; delivery-price -> business.
- "You don't have big size" -> size follow-up on the active context, not a literal search.
- "Do you sell X" stays a product search; clear "we don't stock X" on a miss.
- Cart Management Engine (numbered basket; remove/clear/change by number or name).
- Follow-up phrasing ("more items you have", typos); business intent; Bugs 1–7.

## Files (deploy all 10)
BotBrain, IntentClassifier, ShoppingEngine, CatalogueMatcher, LocationDictionary, CartCorrection,
CategoryDictionary, FollowUp, CartEditor, GreetingDictionary.

## Tests — 443 assertions, 0 failures
greeting 41 · cart 41 · followup 49 · realcustomer 49 · intent 61 · location 34 ·
commerce-bugs 16 · phase-1 63 · defaults 18 · delivery 31 · decline 15 · final 25.

## Honest scope
GreetingDictionary + classifier logic are unit-tested directly. The reply path
(greetingReply -> tenant name, custom greeting) runs through Laravel — confirm live on WhatsApp
with the exact words: Habari, Mambo, Poa, Oli otya, Gyebale, Salaam, Marhaba. Note this transcript
ran on old code; these (and earlier) fixes are not live until you deploy.

## STILL OUTSTANDING (not code): the bot went silent earlier
The "Why are you replying" transcript showed the bot stop responding mid-session — that is an
infra symptom (queue worker / Evolution WhatsApp session / uncaught exception), not a
classifier bug. Check the queue worker, the WhatsApp instance connection, and laravel.log.
