# CloudBSS v4.41 — a purchase remembers what you bought

**Date:** 2026-08-05 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.41.zip (cumulative — supersedes v4.40; 195 files) · **Migration:** none · **Delta: 3 files.**

Two different bugs were producing the same symptom — the word "Purchase" where an item name should be — which is why it looked inconsistent.

## "Record purchase" had no item at all

It writes one row whose only description is a free-text note, with no product link, no quantity and no unit cost. And the form **auto-filled that note with "Purchase from SPJAYP INVESTMENT LTD"**, so the supplier's name landed in the Item column. Clear the box and the list falls back to printing the literal word *Purchase*, which is what PUR-0412 shows.

It now has a real **Item** field — pick from your catalogue or type any description — with optional quantity and cost each. The note is a note again, and nothing overwrites the item. The row is stored with its product link, its quantity, its unit cost and a proper *Purchase* category, the same as the panel purchases screen has always done.

The same button on the Suppliers **list** got the same treatment: no more auto-filled supplier name, and the field now asks *"Item / description — what did you buy?"*

## "Buy product" lost the name off line 1 only

The button immediately to its left already does everything you were asking for — catalogue picker, quantity, unit cost, **four items in one purchase**, and it updates each product's cost price as it goes.

Its bug: the browser turns the picked product into an id and then **deletes the name from what it sends**, so line 1 arrived nameless while lines 2, 3 and 4 kept theirs. Both halves of a two-line purchase, one named and one not.

The server now recovers the name from the product id. That is deliberately the server side rather than the browser: whatever the front end does or does not send, a line that names a real product gets that product's name.

## Which button to use

**Buy product** for stock — anything you want counted, costed and linked to a catalogue item. Up to four lines per purchase.

**Record purchase** for a bill with no item detail — transport, a service charge, a lump-sum invoice. It now stores an item name too, so even those read properly in the list.

More than four lines in one purchase still needs the classic screen at **/panel/purchases**, which takes as many as you like. A repeating line editor for the workspace forms is a bigger piece of work and is not in this drop.

## Worth checking on that supplier

The two unpaid bills on SPJAYP INVESTMENT LTD total **783,000 UGX**. At your UGX rate that is **$214.23**, but the balance owed reads **$112.60** — an implied rate of 6,954 shillings to the dollar, which is close to the 7,000 hardcoded as the **SSP** fallback.

Open that supplier's **Payments** tab. A payment of about **$101.63** explains it completely and nothing is wrong. If there is no such payment, those bills were converted at nearly double the real rate and what you owe this supplier is understated by roughly a hundred dollars. This drop does not change any existing row — it is a number to look at, not a bug I can fix blind.

## After you deploy

Nothing. Open a supplier and the Record purchase form has an Item box at the top.

Rows already recorded keep whatever note they have; the fix applies from here. The ones showing "Purchase" can be corrected with the pencil on each row as before.

## Verified before ship

**26 behaviour checks** on the shipped source, plus **three deliberately broken copies** to prove they bite: one that stops recovering line 1's name, one that drops the product link off a recorded bill, one that restores the auto-note that was overwriting the item. Each was caught.

Covered: an empty name with a product id recovered by lookup and recovered *before* the line is built; the reverse lookup for slots 2–4 still working; a typed item resolved to a product and a picked product resolved to a name; product, quantity, unit cost and category all written; the generic "Purchase from" note recognised and beaten by a real item name while anything you typed yourself still wins; neither supplier screen auto-filling the supplier's name any more; the product list built before the action that uses it; and Buy product keeping its four slots, its purchase grouping, and the line ids that v4.35's invoice attach depends on.

`php -l` on 3 PHP files · v4.40's 48, v4.39's 46, v4.38's 49 and 25, v4.37's 99 and 32, v4.36's 72 and 33, v4.35's 46 and 31, v4.34's 39 and 25, v4.33's 134 and 46, v4.32's 65, v4.31.4's 22, v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61 and 26, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
