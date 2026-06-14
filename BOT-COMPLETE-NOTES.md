# CloudBSS bot — complete set (+ Intent Override Layer)

Cumulative. Deploy = push files + `php artisan optimize:clear`. No migration.
Non-bot files included (deploy too): EvolutionGateway, WebhookController, ProcessIncomingMessage.

## NEW — Intent Override Layer (the priority-1 fix)
Before, while a numbered list was pending, ANY non-number reply got "Please reply with the number"
— even live buying signals. Now, when the reply isn't a selection, the bot classifies it and a real
intent INTERRUPTS the selection flow:

- **Delivery question** ("Do you do deliveries?", incl. plural / "do you do deliveries") ->
  delivery answer that invites a location pin. Options stay live.
- **Price question** ("how much is X") -> price answer. Options stay live.
- **Business / hours / location-of-shop** -> business answer. Options stay live.
- **Availability of the SHOWN list** ("Only those ones you have?", "is that all?") -> re-affirms the
  current list: "Yes 😊 those are the *Shan* options we currently have: 1. … Would you like to add any?"
- **New product / availability of ANOTHER item** ("You don't have cous cous") -> fresh search for
  that product, replacing the old options ("Yes, we have Cous Cous: …").
- **Greeting** -> greet + keep options. **Thanks / Okay / 👍 / Will check** -> warm close, drop list.
- **No / not interested** -> decline. **Checkout** -> checkout. **Location pin/text** -> capture it.
Only a genuinely unrecognised reply still gets the (kept-options) re-prompt.

## NEW — Tidy clarify headings
Headings no longer echo the raw sentence. "Oh I have forgotten kolam rice how much is it" now lists
under *Kolam rice* (the words the query and matched products share), not the whole sentence.

## Carried forward
Category-focused search (no snacks/blades in a rice search) · location pins -> Google Maps link ·
shopping-start intent · session expiry & cart recovery · fuzzy guard (Shell≠Hello) · multilingual
greetings · big-size follow-up · Cart Management Engine · follow-ups · Bugs 1–7.

## Priority order (yours)
1. Intent Override Layer ✅ (this build)  2. Search ranking (category-focus shipped; brand-grouping
needs a `brand` field)  3. Cart editing ✅  4. Checkout ✅  5. Delivery zones ✅ (+pins)  6–8. Voice/
Image/PDF (parked).

## Tests — 516 assertions, 0 failures
override 19 · search-focus 8 · session 22 · greeting 51 · cart 41 · followup 49 · realcustomer 49 ·
intent 75 · location 34 · commerce-bugs 16 · phase-1 63 · defaults 18 · delivery 31 · decline 15 · final 25.

## Honest scope
The classifier + detectors are unit-tested directly (delivery/price/business/availability + the
"is that all" detector). The override DISPATCH runs through Laravel/Conversation (loads the pending
options, keeps/clears state) and can't be executed here — confirm live: show a list, then send
"Do you do deliveries?" (delivery answer, list stays), "only those ones you have?" (re-affirm list),
"you don't have cous cous" (cous cous results), "thanks"/👍 (warm close). Not live until deployed.

## STILL OUTSTANDING (not code): silent-bot incident — infra (worker / WhatsApp session / logs).
