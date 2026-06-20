BOT FIX — delivery time & location references stop being product-searched

From `bot:misses`: "7 pm", "this location", "on this" were thrown at the catalogue (looked dumb).
These are the customer giving DELIVERY details, not searching for a product.

NOW (intercepted before the catalogue search, in keywordRespond):
  bare TIME            "7 pm" / "7:30pm" / "evening" / "after 6" / "sanje"
                       -> "Got it — I'll note delivery around *7 pm*. Add more or *checkout*."
                       -> the time is saved on the order ("… · Deliver ~7 pm") so shop+rider see it.
  LOCATION reference   "this location" / "on this" / "deliver here" / "ahiya"
                       -> if a pin was already shared: "same pinned location"
                       -> else: asks them to share the WhatsApp location pin (with steps).

GUARDED (qa/delivery_details.php, 16 assertions):
  "7up", "5kg", "2 kg sugar", "2 pm rice" are NOT treated as a time.
  "location of shop", "rice here please" are NOT treated as a location reference.

FILES
  REPLACE  app/Services/Bot/BotBrain.php
  NEW      qa/delivery_details.php

UPLOAD: GitHub -> Add file -> Upload files -> drag "app" (and "qa") -> Commit -> Deploy.
No migration / routes / config. (delivery_time rides on the existing order 'location' field.)
