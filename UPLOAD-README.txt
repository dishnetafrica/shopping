BOT FIX — customers don't have to say "checkout" anymore

THE PROBLEM
  The bot waited for the word "checkout". Real customers say "bas", "that's all", "ho gaya",
  "send it", "order karo", "deliver", "basi" (Swahili) — or just the total — so orders stalled
  in the cart.

THE FIX
  Widened the implicit-checkout detector to recognise the natural + Gujlish/Hindi/Swahili ways
  people signal they're done, and route them straight into the normal checkout flow:
     that's all / thats it / nothing else / no more / send it / deliver / bring it / ready
     bas / bas itnu / ho gaya / ho gayu / thai gayu / order karo / bhej do / send karo / le aao
     basi / nimemaliza / tuma   (Swahili)
  Carefully guarded so a real ADD is never mistaken for checkout:
     "send 2 kg almond", "rice 5kg", "send me the menu"  -> still treated as add/menu, NOT checkout.

FILES
  REPLACE  app/Services/Bot/IntentClassifier.php
  NEW      qa/checkout_intent.php   (38 assertions; optional)

UPLOAD ON GITHUB
  Add file -> Upload files -> drag "app" (and "qa") -> Commit -> Deploy.
  No migration / routes / config. Applies to every shop's bot.
