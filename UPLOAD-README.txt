BOT FIX — conversational messages stop being product-searched

From `bot:misses`, these real messages were being run through the catalogue (and looked dumb):
   levanu che / joiye che   -> customer wants to BUY (but named nothing yet)
   ama bill ma nathi        -> "this isn't on my bill" (billing question)
   ha bolo / bolo           -> "go ahead, I'm listening"
   hu bar hati              -> "I was out" (busy)
   by mistake               -> they added something wrong, want to undo

NOW each routes to the right reply instead of a product search:
   levanu che / joiye che / lena hai  -> "Saru! Su joiye che e kaho…"   (prompt for products)
   bill ma nathi / hisab wrong        -> "Kaya item ni gadbad che? *cart* lakho."
   ha bolo / bolo                     -> "Ha kaho! Aaje su joiye che?"
   hu bar hati / kaam ma              -> "Vandho nahi! Jyare free hov tyare order kaho."
   by mistake / wrong one             -> "Tell me which item to remove — say *remove <product>*…"

GUARDED (proven by qa/conversational_routes.php, 18 assertions):
   "kaju levanu che" still SEARCHES (product named)   "nathi levanu" stays a DECLINE
   "bar of soap" is NOT mistaken for "I was out"

FILES
  REPLACE  app/Services/Bot/BotBrain.php
  NEW      qa/conversational_routes.php

UPLOAD: GitHub -> Add file -> Upload files -> drag "app" (and "qa") -> Commit -> Deploy.
No migration / routes / config.
