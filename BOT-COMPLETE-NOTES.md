# CloudBSS bot — complete commerce/intent set (Issues 1–3 + Bugs 1–7)

Cumulative. The 8 files in app/Services/Bot/ are the full current bot. Deploying this set
gives EVERYTHING from the recent sessions; it supersedes all earlier bot bundles. No
migration (follow-up/checkout context lives in the conversation `state` JSON).

## This round — follow-up context lost

**ISSUE 1 — business inquiry intent.** "are you open / open for orders / what time do you
close / are you delivering today / are you working" → a business answer, never a product
search. "can opener" / "wine opener" stay product searches (they're real products). Optional:
set tenant settings `business_hours` and `address` to enrich the reply.

**ISSUE 2 & 3 — follow-up context layer.** After a list is shown, the engine records the
active context (`last_query`, `last_kind`) on the conversation. A follow-up phrase then
continues that context instead of searching the literal words:
- "more brands / show more / other options / what else / different size" → more of the same.
- "cheaper one / cheapest" → same context, cheapest first.
- "premium one" → same context, highest price first.
- "larger size / bigger" → same context, largest pack first.
- "smaller size" → smallest first.
"more rice" (names a product) is NOT a follow-up — it adds rice. So "wipes → more brands"
returns wipes (not products containing "brand"); "milk → other options" returns milk;
"Kakira sugar → larger size" lists 2kg before 1kg before 500g.

Follow-ups are checked before the selection step, so a numeric reply ("2") is still a
selection, never a follow-up.

## Carried forward (already in this set)
Bug 1 selection≠quantity · Bug 2 cart correction · Bug 3 size availability (+ normSize fix)
· Bug 4 catalog intent · Bug 5 high-confidence auto-resolve · Bug 6 location intelligence
· Bug 7 category intelligence.

## Checkout investigation (previous turn) — no code change here
The checkout path was traced and is correct: the order IS created (FS-6), order_items are
created, tenant_id is stamped, cart+state are reset, and NotifyOwnerNewOrder is dispatched.
A `deliveries` row is created at rider assignment, not at checkout (by design). If an order
is "not visible", it's tenant scoping (instance tenant vs panel-login tenant) or the branch
filter — see the SQL provided in that turn. One minor gap noted: bot orders don't capture
`customer_name` (phone only) — a small follow-up if you want names in the list.

## Files (deploy all 8 + optimize:clear; NO migration)
app/Services/Bot/: BotBrain.php, IntentClassifier.php, ShoppingEngine.php, CatalogueMatcher.php,
LocationDictionary.php, CartCorrection.php, CategoryDictionary.php, FollowUp.php

## Test results — 334 assertions, 0 failures
followup 34 · realcustomer 49 · intent 49 · location 34 · commerce-bugs 16 · phase-1 63 ·
defaults 18 · delivery 31 · decline 15 · final 25.

### Required tests (all pass)
"Are you open for orders?" → business answer · "Do you have wipes? / more brands" → more
wipes · "Do you have milk? / other options" → more milk · "Kakira sugar / larger size" →
larger sugar variants first.

## Honest scope
Pure logic (matcher, parser, engine, classifier, FollowUp + the dictionaries) is unit-tested
directly. The follow-up/business/category replies run through Laravel/Conversation — confirm
those live on WhatsApp. Follow-up re-lists from the catalogue afresh (it doesn't track which
items were already shown), so "show more" shows the same context list again rather than a
strictly new page; say the word if you want true pagination.
