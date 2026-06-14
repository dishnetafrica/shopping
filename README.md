# CloudBSS — Bot decline + fuzzy-match fix (pilot bug)

Fixes two live bugs seen in Family Shoppers chats:
1. "i dont want anything" matched random products (Abc Dent, Donut Cake, Cockroach Gel)
   — filler/negative words were fuzzy-matching product names.
2. "rice…" pulled in "Race Robot" — fuzzy match fired even though "rice" matched real
   products exactly.

## Changes (2 files)
- app/Services/Bot/CatalogueMatcher.php
  - STOP list extended with negatives/fillers (dont, not, anything, nothing, none, else, …).
  - search(): fuzzy matching is now a TYPO FALLBACK only — a query word that matches any
    product exactly will not also fuzzy-pull look-alikes (kills rice→race; keeps rcie→rice).
- app/Services/Bot/BotBrain.php
  - keywordRespond(): decline handler. "no", "cancel", "nothing", "i don't want anything",
    "not interested", etc. get a friendly prompt and NEVER run a product search.

## Verify
php qa/decline_and_fuzzy_fix_suite.php   # 15/15 — run inside the FULL repo (needs the other Bot/*.php)
No regression: Phase-1 63/63, final regression 25/25, defaults 18/18, perf 18/18.

Deploy: upload the two files, commit, push. No migration. php artisan optimize:clear.
