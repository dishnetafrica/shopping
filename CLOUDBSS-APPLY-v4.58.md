# v4.58 — Asked for, not stocked

**No migration.** Unzip, `php artisan optimize:clear`. Completes Milestone 8.

---

## What it does

A new **Asked for** screen under Inventory: everything customers wanted in the last 90 days that you
did not have, added up — with the phone numbers of the people who asked.

Both halves were already being recorded. Neither had ever been counted.

- **Searches that found nothing** — `search_events`, already listed as terms on Storefront Insights.
- **Things asked of the bot** — `FollowupProposal` rows with trigger `part_request`, recorded **one
  per customer**, which is right for following that person up and useless for deciding what to stock.

---

## Why it was invisible

"Musa asked for brake pads" is a follow-up. "Nine customers asked for brake pads this month, and
here are their numbers" is a purchase decision. Nothing in the system did the second, because
nothing added the first up.

And it could not have, because of a smaller bug underneath: `PartRequestService::normKey` strips
every non-alphanumeric character, so **"brake pad" and "brake pads" were two different demands.**
Every aggregate built on it would have undercounted — quietly, in the direction of doing nothing,
because undercounting always looks like "no demand", which is indistinguishable from working.

`DemandKey` fixes that. Six phrasings — *brake pads · Brake Pad · do you have brake pads? · BRAKE
PADS!! · pad brake · how much for brake pad* — become one row. Sizes stay apart: *rice 5kg* and
*rice 25kg* are different products and stay different demands.

---

## The column that matters

**Customers, not Times.** One person asking ten times is usually one person having a bad day; ten
people asking once each is a product you should be selling. The ranking follows that — distinct
people count ten times as much as repetitions.

**And the phone numbers ride along.** Where a customer asked the bot by name, their number is on the
row. Stock the item, ring the people who asked. They are the ones who will buy it the week it lands,
and that is the difference between a report and a decision.

Rows sourced only from website searches read **"searched, no number"** — `search_events` has nowhere
to record who typed a query, so that demand is real and anonymous. The screen says so rather than
implying a callback list that does not exist.

---

## Files

| File | |
|---|---|
| `app/Services/Growth/DemandKey.php` | **new** — the grouping. Pure |
| `app/Services/Growth/UnmetDemandService.php` | **new** — reads both sources, counts people |
| `app/Capability/Process/DemandCapability.php` | **new** — the screen |
| `app/Capability/Registry/CoreCapabilityProvider.php` | registers it |
| `resources/v3/shell.js` + `public/assets/v3/shell.js` | menu wiring, both copies |
| `resources/panel/help-center.html` | a card explaining which column to read |

---

## Checks that ran

```
t458/demand.php      30 passed, 0 failed
t458/mutants.sh      16 killed, 0 survived
t458/contracts.sh    30 passed, 0 failed
t451–t457            all still green
```

One known wart, written into the tests rather than hidden: **"molasses" keys as "molass"**. The key
is never displayed, every spelling maps to it identically, and the label shown on screen is what a
person actually typed — so the grouping is right and the report reads correctly. An irregular-word
list would be exactly the cleverness that turns "gas" into "ga" and splits a demand instead of
joining it.

---

## What to expect

The bot has been capturing part requests for a while, so this may have real depth on day one.

**Sort by Customers and look only at rows above two.** If something you already stock appears there,
that is not a stocking gap — it is a naming gap, and the fix is keywords on the product rather than
a purchase order. Both are worth knowing and the screen cannot tell them apart; you can.
