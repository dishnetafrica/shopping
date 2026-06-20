BOT FIX — Gujlish dry-fruit orders with fractions (recovers a real lost order)

THE MISS (from `php artisan bot:misses --tenant=2`)
   "250gm pista1/2 kg khahoor,1/2kaju,1/2badam"   -> bot understood NOTHING, order lost.
WHY
   The words were glued to the numbers (pista1/2, 1/2kaju, 1/2badam) so the tokenizer
   never saw clean "pista" / "kaju" / "badam"; and "1/2" (half) wasn't understood at all.
   The aliases were already correct — the INPUT repair was missing.

THE FIX  (new ShoppingParser::preNormalize, runs before parsing)
   - un-glues numbers/words around fractions & units:  pista1/2 -> pista 1/2 , 250gm -> 250 gm
   - half / haf / paav  ->  1/2 , 1/4
   - splits a run-on order:  "250gm pista 1/2 kg khajoor" -> two items
   - fractions of a kg -> whole grams:  1/2 kg -> 500 gm , 1/4 kg -> 250 gm , 3/4 kg -> 750 gm
   - a bare fraction before a product defaults to kg:  1/2 kaju -> 500 gm kaju
   Result: that message now resolves to  250g pistachio, 500g dates, 500g cashew, 500g almond.
   Also added the spelling "khahoor"/"khjoor" -> dates (customer wrote dates with an h).

   Guarded: "7up" stays "7up" (not "7 up"); a single "pista 1/2 kg" stays one item.

FILES
  REPLACE  app/Services/Bot/ShoppingParser.php
  REPLACE  app/Services/Bot/CatalogueMatcher.php
  NEW      qa/gujlish_normalize.php   (11 assertions; optional)

UPLOAD: GitHub -> Add file -> Upload files -> drag "app" (and "qa") -> Commit -> Deploy.
No migration / routes / config. Applies to every shop's bot.
