BOT FIX — "2 kg" now means 2 packs, not an unavailable size

THE PROBLEM (from a real Pal's chat)
  Customer: "2 kg almond, 3 kg walnut"
  Bot:      "2kg isn't available - we have 1kg, 500g, 250g"   <- treated 2kg as a PACK SIZE
  ...and after the customer agreed to 1kg packs, it added only 1 of each (lost the 2 and 3).

THE FIX
  When a requested amount doesn't match a single pack, the bot now COMPOSES it from whole
  packs, picking the largest pack that divides evenly:
     2 kg  + a 1kg pack   -> 2 x 1kg
     3 kg                 -> 3 x 1kg
     1.5 kg               -> 3 x 500g
     750 g                -> 3 x 250g
     2 ltr + a 1ltr pack  -> 2 x 1ltr
  If nothing divides evenly (e.g. 700g with 250/500/1000 packs) it falls back to the old
  "here are the sizes we have" message. Weight vs volume never mix.

  Now the customer's first message just works:
     "2 kg almond, 3 kg walnut"
     -> "2 x Almond 1 Kg - UGX 92,000, 3 x Walnut 1 Kg - UGX 180,000 ... say OK to confirm"

FILES
  REPLACE  app/Services/Bot/ShoppingEngine.php
  NEW      qa/shop_pack_compose.php   (dev test, 9 assertions; optional)

UPLOAD ON GITHUB
  Add file -> Upload files -> drag the "app" (and "qa") folder -> Commit -> Deploy.
  No migration / routes / config. Applies to every shop's bot (Pal's, Family Shoppers, etc.).
