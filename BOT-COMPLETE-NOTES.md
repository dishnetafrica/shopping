# CloudBSS bot — complete set (+ P1 auto-add fix, delivery pricing, location help)

Cumulative. Deploy = push files + `php artisan optimize:clear`. No migration.
Non-bot files included (deploy too): EvolutionGateway, WebhookController, ProcessIncomingMessage.

## P1 FIX — a bare search must NEVER auto-add to cart
"Rice" was adding "India Gate Basmati Rice" to the cart (the owner-default / auto-pick path fired
without an add intent). Now a PURE search (no add verb, no quantity, no specific size) always
CLARIFIES — it never adds. The default / auto-pick / high-confidence resolution only applies when
the customer signalled intent to add:
  - "rice"            -> shows rice options (nothing added)
  - "add rice" / "2 rice" / "10 rice" -> adds (uses owner default / auto-pick)
  - "rice 2kg"        -> adds the 2kg SKU (specific size)
  - "5 coke 10 rice 2 sugar" -> adds all three
(The defaults/final-regression suites were updated to this contract; tenant-isolation,
idempotency and OOS coverage preserved via the add path.)

## NEW — Delivery price to a named area
"How much to Ntinda?" / "How much delivery to Kisaasi?" / "delivery fee to Bugolobi" now answer
with the zone fee + ETA when that area is a configured zone, otherwise ask for a pin:
  - zone configured -> "🛵 Delivery to *Ntinda* is *UGX X* (~Y min). Tell me what you'd like."
  - not configured  -> "🛵 Yes, we deliver to *Ntinda*! … drop your location pin and I'll calculate it."
Requires a price cue ("how much" / fee / charge / cost), so "Deliver to Jebel" stays a LOCATION
capture (the customer stating where to deliver), not a price query.

## NEW — Location-pin help
"Can I send a location pin?" / "share location" / "send my location" ->
  "Yes 😊 please send your WhatsApp *location pin* and I'll calculate the exact delivery fee.
   Tap 📎 → Location → Send your current location."
Works both normally and while a selection list is pending (keeps the list).

## Carried forward
Intent Override Layer (delivery/price/business/availability/greeting/thanks/decline/checkout while
a list is pending) · category-focused search (no snacks/blades in a rice search) · tidy clarify
headings · location pins -> Google Maps link · shopping-start intent · session expiry & cart
recovery · fuzzy guard (Shell≠Hello) · multilingual greetings · Cart Management Engine · Bugs 1–7.

## Tests — 529 assertions, 0 failures
override 29 · search-focus 8 · session 22 · greeting 51 · cart 41 · followup 49 · realcustomer 49 ·
intent 75 · location 34 · commerce-bugs 16 · phase-1 63 · defaults 21 · delivery 31 · decline 15 · final 25.

## Honest scope
The classifier/engine logic is unit-tested directly (incl. the P1 bare-search-vs-add cases and the
delivery-area / location-help detectors). The dispatch + state run through Laravel — confirm live:
"Rice" (options, nothing added), "10 Rice / 5 Coke 10 Rice 2 Sugar" (cart updated), "How much to
Ntinda?" (fee or pin ask), "Can I send a location pin?" (instructions). Not live until deployed.

## Bug 3 (the silence) is STILL infra, not code
"Can I send a location pin?" / "2 Kolam Rice" getting NO reply = the message pipeline stopped
(queue worker crashed, Evolution WhatsApp session dropped, or an uncaught exception). Every one of
those inputs now has a defined handler here, so if they still go silent live, it is NOT the bot
logic — check the queue worker, the WhatsApp/Evolution connection, and storage/logs/laravel.log.
