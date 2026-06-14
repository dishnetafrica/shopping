# CloudBSS bot — complete set (+ WhatsApp location pins → Google Maps link)

Cumulative. Deploy = push files + `php artisan optimize:clear`. No migration.
This build changes 3 files OUTSIDE app/Services/Bot/ — deploy them too:
  app/Services/WhatsApp/EvolutionGateway.php
  app/Http/Controllers/Bot/WebhookController.php
  app/Jobs/ProcessIncomingMessage.php

## NEW — WhatsApp location pins (static + live) → Google Maps link
Why it did nothing before: the webhook dropped any message with empty text, and the Evolution
parser only read text — so a location pin never reached the bot.

Now:
- **Parser** extracts `locationMessage` / `liveLocationMessage` -> lat/lng (+ name/address).
- **Webhook** lets a pin through even with empty text.
- **Bot** builds a tappable Google Maps link `https://maps.google.com/?q=LAT,LNG`, snaps the pin to
  a delivery zone (existing ZoneResolver haversine) for the fee/ETA, stores the pin + link on the
  conversation, and:
    * mid-checkout -> places the order immediately using the pin;
    * otherwise -> confirms ("📍 Got your location: <link> — that's in *Zone*, delivery UGX X")
      and saves it so *checkout* delivers there.
- **Order.location** stores "Zone — <maps link>" (no new column), so the customer confirmation,
  the owner alert, and the rider's order view all show a link they just tap to navigate —
  exactly like the n8n flow. Live location is treated as a single pin (first fix); we don't track
  the moving stream.

Reverse geocoding is intentionally NOT used: the link is the deliverable; zone-snapping gives the
fee; the rider navigates by tapping. Tenants can still set their shop origin (settings lat/lng)
to get distance-based fees for per-km zones.

## Carried forward
Shopping-start intent · session expiry & cart recovery · fuzzy cross-match guard (Shell≠Hello) ·
multilingual greetings (incl. Arabic script; greet beats pending clarification) · price questions ·
"big size" follow-up · "do you sell X" + clear miss · Cart Management Engine · follow-ups · Bugs 1–7.

## Files
Bot (10): BotBrain, IntentClassifier, ShoppingEngine, CatalogueMatcher, LocationDictionary,
CartCorrection, CategoryDictionary, FollowUp, CartEditor, GreetingDictionary.
Plus: EvolutionGateway, WebhookController, ProcessIncomingMessage.

## Tests — 489 assertions, 0 failures
session 22 · greeting 51 · cart 41 · followup 49 · realcustomer 49 · intent 75 · location 34 ·
commerce-bugs 16 · phase-1 63 · defaults 18 · delivery 31 · decline 15 · final 25.
Pure parts of the pin path verified directly: maps-link format and pin→zone snap.

## Honest scope
The pin EXTRACTION (parser) + webhook guard + the bot handler run through Laravel and can't be
executed here — confirm live by sending a location pin from WhatsApp (mid-checkout it should place
the order with the link; otherwise it should confirm + save). The pure pieces (maps link, zone
snap by coordinates, fee) are unit-verified. The Evolution payload key for live location may vary
by version — if a live-location pin doesn't register, check `message.liveLocationMessage` shape in
storage/logs and tell me the exact keys.

## STILL OUTSTANDING (not code): silent-bot incident — infra (worker / WhatsApp session / logs).
