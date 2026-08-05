# CloudBSS v4.37 — three things the catalogue was getting wrong

**Date:** 2026-08-05 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.37.zip (cumulative — supersedes v4.36; 189 files) · **Migration:** none · **Delta: 9 files.**

From auditing the 376-product export. Every finding below is in that file.

## Your cups say "lead" where they mean "lid"

*5 Star Disposable Glasses With lead 300ML*, and four siblings. Lead is a toxic heavy metal and these are food-contact drinking cups. The word is not only in the product name — it is in the keywords and in the auto-generated description too, so it was on the storefront, in your search index, and in whatever the bot said about them.

It is also spelled three ways across five products: *With lead*, *Without lead*, *With Out lead*.

```
php artisan products:fix-lid-typo --tenant=17 --dry-run    # shows every change, writes nothing
php artisan products:fix-lid-typo --tenant=17              # applies them
```

It fixes the name, the keywords and the description, and collapses the three spellings into one. **It only touches products that are containers or drinkware** — a stationery shop sells pencil lead, and that is a real word about a real product. Anything ambiguous is listed and skipped rather than guessed at.

## Your brand pages were showing the wrong prices

`brand.html` rounded to whole units with no decimal places. On a USD shop that meant a **$1.20 packet of cloves advertised as "USD 1"** — a 17% understatement — and $0.86 cinnamon as "USD 1", which is 16% the other way.

**63% of your catalogue is under $10 and 76 products are under $2.50.** This was not an edge case.

The main shop page has handled this correctly since v4.31; the brand pages and the autoparts page each had their own copy that never got the fix. All three now use the same rule: zero-decimal currencies (UGX, SSP, KES…) stay whole, everything else keeps its cents, and USD gets its symbol.

## The admin importer would have eaten your catalogue

Three problems in one place, and they compounded:

**`Cost` was mapped to `base_price`.** Not `cost_price` — `base_price`, which is the field the storefront product page and the panel price column read *in preference to* the actual price. Export your catalogue, edit it, import it back, and the shop would start advertising what you paid as what you charge, with every cost price left null.

**"Replace the whole catalogue" defaulted to ON.** And "replace" there is a hard `DELETE` of every product row — cost prices, extra photos, OEM numbers, dealer prices, and the links from past order lines. One click, no confirmation, no undo.

**Neither of those was guarded.** Now: Cost maps to `cost_price` and is actually written, on both insert and merge. Replace defaults to OFF, and turning it on reveals a box you must type `REPLACE` into. All three entry points (the products list, the tenant row action, the tenant edit page) got the same treatment.

The panel importer at Finance → products was always correct on the Cost mapping and always required `confirm=RESTART`. It was the admin one that was dangerous.

## And the export header was lying

It said `Price UGX` for every business, hardcoded. You sell in USD, so 376 dollar prices came out under a shilling heading — an invitation for whoever opens the file to "correct" them by a factor of 3,655.

The heading now names the currency the numbers are actually in: `Price USD`, `Cost USD` — or `Price UGX` for a shilling tenant, because it reads the business's own setting. Every importer strips a trailing currency code before matching, so `Price USD`, `Price UGX`, `Price (USD)` and a bare `Price` all still load, and **no spreadsheet anyone has saved stops working.**

## About that cost column

Of the 51 products that have a cost at all, **8 are recorded in UGX while the other 43 are in USD** — in the same column, with nothing to tell them apart. The gross-profit report reads all of them as dollars, so those eight carry **$800,740 of cost where the truth is $219**.

There is no currency tag on a product cost anywhere in the schema, so nothing could have caught this. The worksheet delivered alongside this note lists all eight, with the converted figure already worked out.

## After you deploy

Run the lid fix. Then reload a brand page and check a spice price shows its cents.

## Verified before ship

**99 behaviour checks** on the shipped code — 26 driving the three storefront price formatters, 24 on the lid-repair regexes, 49 on the export/import round-trip — plus **32 source checks**, plus **three deliberately broken copies** to prove the tests bite: one that sends Cost back to `base_price`, one that restores whole-unit rounding, one that lets the lid fix treat a pencil as a container. Each was caught.

Covered: $0.86, $1.20 and $2.74 keeping their cents; $65 rendering as $65.00; UGX and SSP staying whole with no dollar sign; an unlisted currency keeping its code; a currency passed as an object or absent entirely; all three storefront pages agreeing on the same four prices. The five live product names transforming exactly, "With Out lead" collapsing to "Without lid", the fix reaching keywords and descriptions, and pencil lead, lead-acid batteries and the word "Leadership" all left alone. `Cost` → `cost_price` and explicitly *not* `base_price`; `Price USD`, `Cost UGX`, `Price (USD)` and the legacy `Price UGX` all resolving; the currency-stripping not making `Size` ambiguous with `Pack Size`.

One v4.28 round-trip check was **deliberately amended**: it asserted `Cost → base_price`, which encoded the bug. It now asserts the opposite, and that Cost does *not* land in `base_price`.

`php -l` on 6 PHP files · `node --check` on both storefront pages · v4.36's 72 and 33, v4.35's 46 and 31, v4.34's 39 and 25, v4.33's 134 and 46, v4.32's 65, v4.31.4's 22, v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61 and 26, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
