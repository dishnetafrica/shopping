# CloudBSS bot — complete set (+ category-focused search, ack rule)

Cumulative. Deploy = push files + `php artisan optimize:clear`. No migration.
Includes 3 non-bot files (deploy too): EvolutionGateway, WebhookController, ProcessIncomingMessage.

## NEW — Category-focused product search (no more snacks/blades in a rice search)
Root cause: the parser split "Indian gate rice Chenab, super, brown rice, SWT P,1,LG ,Ravi rice,MB"
into 7 fragments (incl. noise "super","lg","mb"), each searched separately -> noise fragments
matched snacks/gum/blades. A per-fragment filter can't fix that.

Fix: a whole-message **category browse**. When a long query (>=4 content tokens) is dominated by
ONE product category at >=70% of the match score, the bot shows ONLY that category's products
(max 20, best/exact-brand first) as a clean numbered list, and ignores unrelated products that
merely collide on tokens. Verified on the exact query: returns 11 Rice products, ZERO non-rice
(snacks/baby cereal/gum/blades/hing all excluded), and includes "India Gate Basmati Feast"
(rice by category even though its name has no "rice"). Guards: "rice 2kg"/"milk" (short/precise)
and genuinely multi-category queries ("rice sugar oil soap") do NOT trigger it — they go to the
normal per-item add path. General (category-driven), not rice-hardcoded.

## NEW — Acknowledgement rule
While a numbered list is showing, "Thanks / Okay / Noted / 👍 / Will check / asante / webale" no
longer re-prompts "reply with a number". It closes warmly and drops the list:
  "You're welcome 😊  Let me know if you'd like to order any item or search for another product."
(A bare number still selects; a greeting greets + keeps options.)

## HONEST gaps vs the full spec (need data we don't have yet)
- **Brand sub-headers** (India Gate / Chenab / Ravi / MB as group headings): products have no
  `brand` field, only `name` + `category`. I present a clean single numbered Rice list instead of
  per-brand groups. Exact grouping like your mock-up needs a `brand` column (small migration +
  populate from the name, or set in admin). Say the word and I'll add it — buildOptions already
  supports multiple labelled groups with continuous numbering, so the list would render exactly
  like your example.
- **Popularity ranking**: there's no sales/order-count field, so ranking is exact-brand/name >
  category-relevance (popularity not included). I can add it once order counts are tracked.

## Carried forward
Location pins -> Google Maps link · shopping-start intent · session expiry & cart recovery ·
fuzzy cross-match guard (Shell≠Hello) · multilingual greetings · price questions · big-size
follow-up · do-you-sell + clear miss · Cart Management Engine · follow-ups · Bugs 1–7.

## Tests — 497 assertions, 0 failures
search-focus 8 · session 22 · greeting 51 · cart 41 · followup 49 · realcustomer 49 · intent 75 ·
location 34 · commerce-bugs 16 · phase-1 63 · defaults 18 · delivery 31 · decline 15 · final 25.

## Honest scope
categoryBrowse + the matcher are unit-tested directly (incl. the exact failing query). The reply
path runs through Laravel — confirm live by sending the rice query (should list rice only) and by
replying "thanks"/👍 to a list (should close warmly, not re-prompt). Not live until deployed.

## STILL OUTSTANDING (not code): silent-bot incident — infra (worker / WhatsApp session / logs).
