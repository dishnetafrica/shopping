# Intent Classification Layer (runs BEFORE catalogue search)

Fixes the pilot issue where conversational messages triggered a product search.
The bot now behaves like a shop assistant, not a search engine.

## Files
- NEW app/Services/Bot/IntentClassifier.php — pure, deterministic classifier.
- CHANGED app/Services/Bot/BotBrain.php — classifies intent before the ShoppingEngine;
  only SHOPPING reaches the catalogue.

## Intents
shopping · greeting · feedback · thanks · question · cancel · decline · human_agent ·
checkout · cart · unknown.

## Product-search guard (the core rule)
SHOPPING (and therefore a catalogue search) is returned ONLY when there is a real
shopping signal:
1. a word matches a catalogue word EXACTLY (singular/plural), or
2. a quantity + unit ("2kg", "500 ml") — not "10 sec", or
3. an explicit shopping verb (buy/order/add/"do you have"/"i need"…) with content, or
4. a SHORT bare term (<=3 content words) that isn't conversational — likely a product
   or a typo, so the engine's fuzzy fallback can still fix "sugr" -> "sugar".
Conversational text (feedback/greeting/thanks/question) and long non-product
sentences never search. Mixed messages ("hello, i need rice") -> shopping wins.

## Behaviour
- Feedback / Greeting / Thanks / Question -> friendly acknowledgement, no search.
- Human agent ("talk to a person", "customer care") -> hands the chat to a human
  (sets agent_active, alerts the owner), friendly hold message.
- Decline / Cancel -> exit shopping, friendly prompt (BotBrain already handled these;
  the classifier agrees as defense).
- Unknown / gibberish -> friendly clarification, never random product suggestions.

## Verified here — 47/47 (qa/intent_classifier_suite.php), incl. the exact reported bug
and all your example sets. No regression: Phase-1 63/63, final 25/25, decline 15/15,
defaults 18/18.

Deploy: drop in the two files, push, php artisan optimize:clear. No migration.
