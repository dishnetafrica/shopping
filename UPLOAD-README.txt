BOT LEARNING UPGRADE — miss-capture loop + Gujlish greeting patch

WHAT THIS DOES
  1. MISS-CAPTURE LOOP (the durable upgrade):
     Every time the bot fails to match a product ("we don't stock X" / "couldn't find X"),
     it now logs the term to a new bot_misses table (per tenant, with a count + sample).
     That builds a living, evidence-ranked list of exactly what real customers said that the
     bot didn't understand — so improving it stops being guesswork.
     Review it any time:
        php artisan bot:misses                 # top unmatched terms, all shops
        php artisan bot:misses --tenant=2      # just Pal's
        php artisan bot:misses --limit=80
     (The log write is wrapped so it can NEVER break a customer reply.)

  2. GUJLISH GREETING PATCH:
     GreetingDictionary now recognises kem cho / majama / kaise ho / jai swaminarayan and
     bare address openers (bhabhi / bhai / ben / kaka), incl. trailing ones ("hi bhabhi",
     "jsk bhai"). These were being mis-searched as products before.

FILES (exact repo paths):
  NEW      app/Models/BotMiss.php
  NEW      app/Support/BotMiss.php
  NEW      app/Console/Commands/BotMissesCommand.php
  NEW      database/migrations/2026_06_19_100001_create_bot_misses.php
  NEW      qa/bot_greeting_gu.php                  (dev test, optional)
  REPLACE  app/Services/Bot/GreetingDictionary.php
  REPLACE  app/Services/Bot/BotBrain.php

UPLOAD ON GITHUB
  Add file -> Upload files -> drag the "app", "database", "qa" folders -> Commit.
  No routes or config to edit. EasyPanel -> Deploy. The bot_misses migration runs automatically.

AFTER A FEW DAYS
  Run  php artisan bot:misses --tenant=2  to see Pal's real vocabulary gaps, ranked by how
  often they happened. Send me that list (or the product catalogue) and I'll turn the genuine
  ones into CatalogueMatcher aliases.

QA: php qa/bot_greeting_gu.php -> 13.
