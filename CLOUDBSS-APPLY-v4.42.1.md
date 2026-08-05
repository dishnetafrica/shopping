# CloudBSS v4.42.1 — as many items on a purchase as the invoice has

**Date:** 2026-08-05 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.42.1.zip (cumulative — supersedes v4.41 and v4.42; 195 files) · **Migration:** none · **Delta: 5 files.**

v4.41 fixed the missing item name and gave *Record purchase* a product picker. On reflection that was the wrong shape: **Record purchase should stay a one-line bill**, and the unlimited rows belong on **Buy product**, which is where you enter stock. This drop moves them.

## Buy product takes rows now

An **add-a-row table**: item, quantity, cost each, a running line total, a ✕ to remove, and **+ Add item** for as many as you need. It opens with two empty rows.

It replaces four fixed slots, which meant a five-line delivery had to be split into two purchases. The four slots are also where the old bug lived: the form turned the first product into an id and then **deleted the name it had just mapped from**, so line 1 arrived without one while lines 2–4 kept theirs.

Two things the table does that the slots did not:

**The bill amount follows the rows.** Type 3 × 24,200 and the amount fills itself in. It cannot end up disagreeing with the lines it is made of.

**An empty purchase is refused.** Nothing to save means a message, not a silent save.

Rows post as the same `lines` array the classic Purchases screen has always sent, so the endpoint did not change — grouping, cost-price updates and the v4.35 invoice attach all keep working exactly as they did.

## Record purchase stays one line, deliberately

It is for a bill with no item breakdown — transport, a service charge, a lump-sum invoice. One amount, one description. The v4.41 fix stands: the box below the amount is the **item**, and nothing overwrites it. It previously auto-filled with *"Purchase from SPJAYP INVESTMENT LTD"*, which is how the supplier's name kept appearing in the Item column, and why a cleared box left the list printing the bare word *Purchase*.

The reason it stays simple is now written in the file, so the next person to look does not helpfully turn it back into a second Buy product.

## The two buttons are named for what they do

They were **Buy product** and **Record purchase** — near-synonyms, on the same card, doing very different things. That is why the wrong one got used. They now read:

**Buy stock** — anything you want counted, costed and linked to a catalogue item. Unlimited rows.

**Record a bill** — an amount with no items in it: transport, a service charge, a lump-sum invoice.

## Also in this drop

The server still recovers a line's name from its product id even if nothing sends one, so the old failure cannot come back through another door. And `store()` keeps the ability to accept `product_id`, `qty` and `unit_cost` that v4.41 added — the Demand Plan screen has been posting a `product_id` all along and having it silently dropped.

## Worth checking on SPJAYP INVESTMENT LTD

Repeating from v4.41 because it is a real number, not a code change. The two unpaid bills total **783,000 UGX** = **$214.23** at your rate, but the balance owed reads **$112.60** — an implied 6,954 shillings to the dollar, close to the 7,000 hardcoded as the **SSP** fallback. Open that supplier's **Payments** tab: a payment near **$101.63** explains it and all is well. No such payment means those bills were converted at nearly double the real rate.

## After you deploy

Nothing. Open a supplier: the two buttons now read **Buy stock** and **Record a bill**, and Buy stock opens the item table.

## Verified before ship

**51 behaviour checks** — 21 driving the line editor itself, lifted out of the shipped `shell.js` and run against a stubbed DOM, and 30 on the two screens' shape — plus **five deliberately broken copies** to prove they bite: one that stops recovering a line's name, one that puts the supplier-name auto-note back, one that takes the line editor away, one whose footer never totals, and one that lets the last row be deleted. Each was caught.

Covered: two rows to start and ten after adding; 3 × 24,200 totalling to 72,600 on its own row while an untouched row shows nothing rather than zero; the footer counting items and money; the bill amount filling itself and keeping up when a quantity changes; a name with no quantity and a quantity with no name both refusing to count, while a **zero-cost item still counts** because free is a real answer; a middle row removed and the total following; the last row cleared rather than deleted so there is always somewhere to type; only complete rows posted; a catalogue name carrying its product id; and an empty purchase refused with a message.

`php -l` on 3 PHP files · `node --check` on both copies of `shell.js`, which are byte-identical · v4.40's 48, v4.39's 46, v4.38's 49 and 25, v4.37's 99 and 32, v4.36's 72 and 33, v4.35's 46 and 31, v4.34's 39 and 25, v4.33's 134 and 46, v4.32's 65, v4.31.4's 22, v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61 and 26, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
