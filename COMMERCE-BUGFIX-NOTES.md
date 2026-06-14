# Critical commerce bug fixes (matching + selection state)

Fixes the four pilot bugs. Three files changed; no migration.

## Root causes & fixes
**Bugs 3 & 4 — selection state lost ("Beer" → "1" → "I didn't quite catch that").**
The intent classifier I added earlier ran before the shopping engine, so a numeric
reply like "1" or "1 2 3" was classified UNKNOWN and never reached the selection logic.
- BotBrain now resolves an ACTIVE clarification first: if options are pending, the
  engine runs before any greeting/affirmation/intent check, so the number resolves.
- If the reply neither resolves the selection nor starts a new product, the options are
  KEPT and the bot re-prompts — nothing is added on an ambiguous reply, and selection
  state survives between messages.
- The engine no longer wipes pending options on a no-match (it only clears them after a
  real add or when showing new options).

**Bug 1 — "Milk 2ltr" added "Jallen Yoghurt 2ltr".**
Scoring treated a name match and a keyword match equally, so a yoghurt that merely lists
"milk" as a keyword could tie/beat real milk; auto-pick then chose it on price.
- A match in the product NAME now scores far higher (120) than a keyword/category match
  (40), so on-noun products rank first.
- Auto-pick (explicit_then_auto) now chooses among the TOP-relevance candidates only,
  then cheapest — a cheap off-noun product can't win on price.

**Bug 2 — "Club 3" auto-added (treated 3 as quantity).**
- Auto-pick no longer silently resolves a genuinely ambiguous term; when several equally
  relevant matches exist (Club beer / empty / bottle), the bot clarifies and adds nothing.
  (Quantity parsing itself was left unchanged — "2kg sugar", "5 rice" still work.)

## Verified here — 16/16 (qa/commerce_bugs_suite.php), incl. the required tests:
Beer→1, Jesa milk→3, Rice→2, Club→1, Milk→2 all add the correct product; "club 3" asks
and adds nothing; "milk" never adds yoghurt; selection state survives a stray reply.
No regression: brain 63/63, defaults 18/18, intent 47/47, decline 15/15, delivery 31/31.

## Files
- app/Services/Bot/CatalogueMatcher.php — name-weighted scoring.
- app/Services/Bot/ShoppingEngine.php — relevance-aware auto-pick + preserve options.
- app/Services/Bot/BotBrain.php — resolve pending clarification before other handling.

Deploy: push the three files, `php artisan optimize:clear`. Then on WhatsApp test the
required cases. (The selection-state fix is best confirmed live end-to-end.)
