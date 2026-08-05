# CloudBSS v4.39 — stop asking people who already told us

**Date:** 2026-08-05 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.39.zip (cumulative — supersedes v4.38; 193 files) · **Migration:** none · **Delta: 6 files.**

v4.38 taught the bot to ask "which town or area is Zyad shop in?" instead of guessing. This is the other half: for a customer who has answered that before, it should not be asking at all.

## You already had the answer

`customer_profiles.address`, keyed by phone. Since v4.0, with `relationship_memory` on for Family Shoppers, an extractor has run over your chat history every fifteen minutes looking for *"shop name/number + market or area if mentioned — e.g. Shop 307, Konyo Konyo"* and writing it there when the field was blank.

**Nothing read it back.** The memory block the bot receives on every message touched that field exactly once, to test whether it was *empty*, so it could add "their shop + area (needed for delivery)" to the list of things to ask. The value itself never reached the prompt. The bot knew the address was missing. It could not see it when it wasn't.

Now it does:

> We have delivered to them at: Shop 307, Konyo Konyo. When delivery comes up, OFFER this back for confirmation ("same place — Shop 307, Konyo Konyo?") rather than asking where they are. If they name somewhere different, use the new one.

A customer confirming an address is faster, and far less irritating, than a customer typing one.

## The moment it was confirmed was the moment it was destroyed

When a customer names an area mid-order, the scripted brain stores it properly in conversation state — and then `$convo->state = []` runs at the end of checkout and wipes the lot. The single most reliable delivery fact you will ever get about someone, thrown away at the instant it was proven.

Both checkout paths now keep it against the customer first. The AI brain does the same at the point the quotation is built, which is where its delivery text has always been available and always been dropped after one message.

**Fill-blank-only.** Whatever a human typed on the Customers screen outranks anything a bot infers from chat, permanently — the same rule the extractor already follows. A customer who moves shop needs someone to edit the record; that is the deliberate trade for never having a one-off delivery address quietly overwrite somebody's real one.

**And not everything gets stored.** "yes", "ok", "same", "thanks", an emoji, a bare number, anything under three characters — none of those are places, and none of them get written. A shop name *is* a place: "Zyad Shop" is exactly what a rider needs, so the test is not "is this a town", it is "did they tell us anything at all".

## A fallback that was written but never wired

`profileFor()` in the storefront carries the comment *"Fall back to the most recent order's name/location if no profile row yet."* It fetches the last order. It falls back for the name. The location is read and dropped on the floor on the next line.

Now it does what it says.

## What this does not fix

**A WhatsApp order still never becomes an order row.** Since quote-first mode it becomes a quotation, so `orders.location` is never populated by the bot at all, and `quotations.delivery_address` is still only ever written by the storefront checkout — the bot writes `meta->delivery`, which nothing reads. Your staff open the portal and still see a quotation with no delivery address on it. That is the next thing here, and it is a bigger change than this one.

**`delivery_zones` is still empty.** The good table — match keywords, coordinates, radius, per-zone fees — has never had a row created for you, so the free-delivery decision still runs off the two comma-separated town lists in Settings. Worth filling in when you next look at delivery.

## After you deploy

Nothing. But it is worth knowing how much you are sitting on:

```sql
SELECT COUNT(*) AS total,
       COUNT(NULLIF(TRIM(address), '')) AS with_address,
       COUNT(NULLIF(TRIM(city), ''))    AS with_city
FROM customer_profiles WHERE tenant_id = 17;
```

Whatever `with_address` says is the number of customers the bot will stop interrogating from the moment you deploy. Everyone else starts accumulating one from their next order.

## Verified before ship

**46 behaviour checks** on the shipped code, run against a stubbed profile table — plus **four deliberately broken copies** to prove the tests bite: one that lets a new address overwrite an existing one, one that accepts "yes" as a place, one that goes back to hiding the address from the bot, and one that remembers the area *after* the state wipe rather than before. Each was caught.

Covered: a shop name and a shop-plus-market both stored whole; whitespace tidied; a three-letter town surviving; fourteen confirmation words, an emoji, a bare number, an empty string, a two-character fragment and "yes." with a full stop all refused; a 400-character essay capped at 200. A blank profile filled and saved exactly once; an address a human typed left standing; a profile with only a city treated as already answered; no phone, no place, and an unknown customer each writing nothing. Plus: the memory block stating the address and instructing the model to offer rather than ask while still letting the customer correct it; the gap prompt surviving for customers we know nothing about; the storefront fallback actually using the last order's location; and the scripted brain remembering at both checkouts, before the wipe rather than after.

`php -l` on 6 PHP files · v4.38's 49 and 25, v4.37's 99 and 32, v4.36's 72 and 33, v4.35's 46 and 31, v4.34's 39 and 25, v4.33's 134 and 46, v4.32's 65, v4.31.4's 22, v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61 and 26, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
