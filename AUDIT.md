# CloudBSS bot — state vs intent audit + FAQ

## Verdict: it is a HYBRID that is already state-first *where it matters* — but only one
## piece of "active state" exists, and a few real gaps let intent leak through. Fixed below.

The control flow (`BotBrain::respond` -> `keywordRespond`) runs in this order:

1. **Mid-checkout** (`state.step == 'awaiting_location'`) -> the next message is ALWAYS taken as
   the delivery location -> `placeOrder`. *(state-first)*
2. `handleSessionLifecycle` — expiry / cart-recovery / `awaiting_cart_choice`.
3. **Cart commands** (`CartEditor::isEditIntent`: remove / clear / change qty) -> `tryCartEdit`,
   never a product search. *(state-ish, keyword-driven)*
4. **Follow-ups** ("more brands", "cheaper", "bigger") -> continue the last list.
5. **Active clarification** (`state.options` set) -> resolve the reply against the shown list
   FIRST; only a non-selection falls to the **Intent Override Layer** (`pendingOverride`), which
   answers delivery/price/business/FAQ/greeting/checkout and KEEPS the list live. *(state-first)*
6. Only with NO active list does it run `IntentClassifier::classify` -> switch -> SHOPPING fallback.
   *(intent-first)*

So: **state-first when a list is pending or mid-checkout; intent-first otherwise.** That is the
right shape for a commerce engine. The reason live felt "stateless" is mostly that the LIVE bot is
running OLD code without steps 4–6 — plus the genuine gaps now closed.

## The five states

| State | Where it lives | Status | Gap found / fix |
|---|---|---|---|
| 1. Product list | `state.options` (flat numbered list) | state-first | All 3 list paths persist `options`; numeric reply resolves first. OK. |
| 2. Variant selection | same `state.options` | state-first | **FIXED:** a size reply ("6kg") now resolves to the variant whose *name* carries that size BEFORE the digit is read as a row number. |
| 3. Cart editing | `CartEditor` keywords; clears `options` | OK | Runs before classify; explicit remove/clear/change never search. |
| 4. Delivery | `businessKind`=delivery / `deliveryArea`; pin -> maps | OK | "How much delivery to Ntinda?" -> delivery quote (not search). Pin handled. |
| 5. Checkout | `state.step=awaiting_location` | state-first | `respond()` short-circuits the next message to `placeOrder`. "checkout" -> CHECKOUT in classify too. |

## Your examples — current behaviour (after this deploy)

- **"checkout"** -> CHECKOUT (command word + classifier). Never a search.
- **"How much delivery to Ntinda?"** -> BUSINESS(delivery) -> a delivery-fee/pin answer. Never a search.
- **"6kg" after Shell Gas variants** -> resolves to *Shell Gas 6KG LPG* (size-preferred selection).
- **"5"** after a list -> row 5 of that list (number resolves against `state.options`).
- **Multi-line** ("5 Coke / 10 Rice / 2 Sugar") -> all parsed as items and added.

## Honest remaining gaps (recommended, NOT yet done)

1. **Multi-line where a cart command sits among shopping lines** (e.g. "add 5 coke \n clear cart"):
   `CartEditor` scans the whole message first, so the cart command can win over the earlier add.
   *Recommendation:* split on newlines and process each line in order through the same pipeline,
   so earlier shopping requests aren't overridden by a later command. (Needs a small loop in
   `keywordRespond`; flagged because it changes message handling and deserves its own test pass.)

2. **"Active state" is implicit** (just `options` + `step`). It works, but for a true workflow
   engine the durable win is an explicit `state.mode` enum (browsing | selecting | editing_cart |
   awaiting_location | confirming) persisted on the conversation, with a single dispatcher that
   routes by mode first and only consults intent inside a mode. That would make the priority order
   provable and testable rather than emergent from call order. Larger refactor — recommend after
   the current fixes are deployed and stable.

3. **Service requests that aren't products** (e.g. "give me a soft-copy receipt") are still treated
   as an add attempt. A real "email/PDF my receipt" feature is separate work.

## New: FAQ engine (`FaqDictionary`)

A deterministic matcher for the everyday questions a shopper used to answer in person. It runs only
AFTER classification decides a message is NOT a product search (the QUESTION / UNKNOWN paths, and
inside `pendingOverride` so it answers while a list stays live) — so "is the milk fresh?" gets the
freshness answer while "fresh milk" still searches.

Topics: payment methods, pay-on-delivery, delivery time, delivery areas, opening hours, how to
order, minimum order, discounts/wholesale, freshness/quality, wrong/missing/damaged + returns,
"is this legit/scam", and "talk to a person". Answers use the tenant's real settings when present
(`payment_methods`, `business_hours`, `delivery_areas`, `min_order`, `delivery_note`,
`pay_on_delivery`) and fall back to sensible defaults otherwise.

*Recommendation:* make the FAQ tenant-editable in the panel later (a simple Q&A table the owner can
add to), so each shop can tune answers. The matcher already accepts per-tenant context to support that.

## Tests
567 assertions, 0 failures — incl. `qa/chatnoise.php` (the live-log phrases) and `qa/faq.php`.

## Deploy
Overwrite `app/Services/Bot/*.php` + `app/Services/Delivery/ZoneResolver.php`, then
`php artisan optimize:clear`. No migration. (The live bot is stale — this is the complete current
bot, so one push gives it everything.)
