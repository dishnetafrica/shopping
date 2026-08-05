# CloudBSS v4.40 — the bot stops inventing product facts

**Date:** 2026-08-05 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.40.zip (cumulative — supersedes v4.39; 193 files) · **Migration:** none · **Delta: 1 file.**

A customer asked for a foil container they could heat in a microwave. The bot said: *"Got it! We have Aluminum Foil Containers that are microwave-safe."*

**Nothing in your catalogue says that.** The word "microwave" does not appear against any of your 376 products. And aluminium in a microwave arcs — metal reflects the waves instead of absorbing them, which throws sparks, can wreck the oven and can start a fire. That customer asked *because* they wanted to reheat food in one.

## It was not the model going rogue. The prompt asked for it.

One instruction told the model that when it shows products it should add a few plain words on what each is used for, taking them from the product's own description "when shown; **otherwise use sound real-world knowledge**" — and it used aluminium foil as its own worked example. The model did what it was told.

Meanwhile every *"never invent a spec"* rule in that prompt sits inside a sentence about **prices**. There was no rule anywhere covering a physical property. That rule now exists:

> **NEVER STATE A PRODUCT PROPERTY THAT IS NOT IN PRODUCTS.** Material, heat or cold tolerance, microwave/oven/freezer/dishwasher suitability, food-grade or BPA status, waterproofing, shelf life, certification, compliance, or fitness for any specific purpose may be stated ONLY if it appears in that product's own line. If a customer asks whether an item can be heated, frozen, microwaved, used with food, or used for anything particular, and PRODUCTS does not say so, **DO NOT ANSWER FROM GENERAL KNOWLEDGE** — say plainly that you will confirm the exact specification with the team. A wrong answer here can hurt someone or ruin their kitchen. Saying "I'll check" is always the right answer when the data is silent.

And the line that licensed the claim is bounded: describe only the obvious everyday use of that kind of product, never a property.

## Your descriptions were good. The bot was seeing 60 characters of them.

This is the part that stings. You have written, for that exact product:

> Aluminum Foil Container Medium-8389 — Medium — 890ml, 20.7x14.1x4.1cm, 1000pcs/carton. **Food-grade aluminium, ideal for takeaway, catering & restaurant use.** Wholesale supply across Uganda & South Sudan; order by carton on WhatsApp. Family Shoppers, Kampala & Juba.

The bot received the first 60 characters:

> `Aluminum Foil Container Medium-8389 — Medium — 890ml, 20.7x1`

The name repeated, then a dimension cut off mid-number. **All 311 of your descriptions begin with the product name**, so that window was spent on a duplicate for every single product, every time — and the sentence with the actual facts in it never arrived. The bot was reaching for general knowledge because the field that would have grounded it was being truncated into noise.

It now drops the repeated name, drops the marketing tail (the bot *is* the sales channel — it never needs to relay "order by carton on WhatsApp"), and spends the budget on what is left:

> `use: Medium — 890ml, 20.7x14.1x4.1cm, 1000pcs/carton. Food-grade aluminium, ideal for takeaway, catering & restaurant use`

121 characters, and every one of them useful. That is what the model will now read.

## "Sure, sending a photo now 📷" — and nothing was sent

A photo only attaches when the reply wraps the exact product name in `*asterisks*`. One prompt line said exactly that; another handed the model the phrase *"Sure, sending a photo now 📷"* and asked for no marker at all. The second one won, and the customer waited for a photo that was never coming.

Three changes. The prompt now demands the asterisks in the same breath as the phrase. If they are missing, the system tries the product name out of our own reply text before giving up. And if a photo was promised and nothing was sent, **the customer is told** — *"Apologies — I could not attach that photo. Our team will send it to you shortly"* — and an owner alert goes out. The same coupling the price list has always had.

## "I need one but not now"

That was a warm lead for a $61 carton with a stated intention to buy, and nothing recorded it. Every automatic follow-up you have keys off a cart, a quotation or a past order; a customer who says "later" without doing any of those matched none of them and vanished.

The bot now files it. When someone says they will buy later, it writes to that customer's **follow-up** field — the one your Customers screen already shows:

> `WANTS: 1 carton Aluminum Foil Container Medium-8389 | will call when needed — said 04 Aug`

It never overwrites an escalation, and never overwrites a follow-up that is still open — the first thing someone asked for is usually the thing to chase.

## Worth doing, not shipped here

`product_specs` and `spec_definitions` already exist in your database and would model *Microwave safe: no · Oven safe: yes · Food grade: yes* properly, as fields rather than prose. They are wired to the storefront and to quotations, and **not to the bot at all**. Filling them for the disposables range would turn "I'll confirm with the team" into a real answer. That is a project; this drop makes the wrong answer impossible in the meantime.

**Check with your supplier before you publish any microwave claim.** Some manufacturers do rate specific shallow foil trays for microwave use under controlled conditions, but the general guidance for takeaway trays is oven and freezer, not microwave — and the only claim worth making is the one on the manufacturer's own spec sheet.

## After you deploy

Nothing. Next time someone asks whether a container is microwave-safe, the bot will say it needs to confirm with the team — which is the truth.

## Verified before ship

**48 behaviour checks** on the shipped code, running the real methods against your real product description — plus **three deliberately broken copies** to prove the tests bite: one that restores the 60-character truncation, one that removes the product-property rule, one that promises a photo and stays silent when none is sent. Each was caught.

Covered: the food-grade sentence reaching the model, the repeated name and the whole marketing tail gone, the result inside a 200-product budget; a description that is only a name, only marketing, empty, or whitespace all yielding nothing; an over-long one capped and marked; the property rule naming microwave, oven, freezer, food-grade, certification and fitness-for-purpose, forbidding general knowledge and stating the alternative; the exact sentence the bot used detected as a photo promise along with "picture", "here is the image" and a bare 📷, while a price answer and an unrelated mention of photography are not; the correction firing exactly when a promise was made and nothing sent; and the follow-up marker being stripped from the reply before it is sent, written as `WANTS:`, and never landing on top of an escalation.

`php -l` on 1 PHP file · v4.39's 46, v4.38's 49 and 25, v4.37's 99 and 32, v4.36's 72 and 33, v4.35's 46 and 31, v4.34's 39 and 25, v4.33's 134 and 46, v4.32's 65, v4.31.4's 22, v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61 and 26, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
