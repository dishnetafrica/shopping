# v4.54.1 — Hidden products

> **v4.54 broke the storefront. This is the fix.** The catalogue closure had no `use` list — it had
> never needed the tenant, because the global scope supplied it — so the visibility filter I added
> inside it referenced an undefined variable. Laravel turns that warning into an exception, and
> `/{shop}/catalogue` answered 500 to every customer. One line. If you applied v4.54, apply this
> immediately; if you have not, apply this instead of it.

**Needs a migration.** Unzip, `php artisan migrate`, `php artisan optimize:clear`.

> Ships on top of v4.52 and v4.53. The zip carries the latest version of every shared file, so
> applying all three in order is safe.

---

## What it does

A product or a whole department can now be taken **off the shelf without being taken out of the
system**. It keeps its stock, cost, margin, reports, order history and campaign eligibility. It
stops appearing anywhere a customer can browse their way into.

The model is an **unlisted** product, in the sense a video is unlisted: it is in no list, but a
direct link works and a customer who names it gets served. That is the difference between this and
`active = false`, which also removes it from your own screens and stops you selling it at all.

**A whole department:** Categories → the **Hide** button on that row. Anything added to that
category later is hidden too, so next month's new flavour needs nobody to remember.

**One item:** the product → Edit → **Hide from customers** → yes.

The department switch is not new. `categories.active` has quietly meant this on the storefront feed
for a long time — it just had no button outside the Filament backend and reached no other surface.
This extends the flag that existed rather than inventing a second one beside it.

---

## Where it now applies

**Sealed — enforced in code:** the storefront product feed (grid, the site's own search, category
tiles), the sitemap, category chips and their public counts, the `/c/` landing pages, the trending
rail, all three recommendation rails, the dealer feed, buy-again, the photo catalogue PDF the bot
sends, both price lists, the bot's category browsing, its photo-card browsing, its "here is what we
sell" summary, its daily offers, its upsells, and the external bot-bridge dump.

**Left alone, deliberately:** campaigns — this is *how* you sell a hidden line, so audiences and
sends are untouched. Order pricing and order confirmations, because filtering those would break the
sale the advert was for. Every staff screen. And the product's own page, so a link in a WhatsApp
advert still opens.

**Eight caches** hold product lists and only three were being dropped on a product change. All eight
are now dropped, plus the same set when a category is toggled. Without that, "hidden" would have
meant "hidden in about ten minutes".

---

## One honest limit

The website and the PDFs are **absolute** — a hidden product cannot appear, because the query cannot
return it.

The chat is **not absolute**. The bot keeps hidden products in the list it reasons from, marked
`OFF-CATALOGUE: never mention or list this unless the customer names it first`. It has to keep them,
or a customer who received your advert and asks for "two apple" by name gets told you do not stock
it — which breaks the exact sale the advert was for. That marking is an instruction to a language
model, and an instruction is not a guarantee. Expect it to hold nearly always and to slip
occasionally. If you would rather the bot never mentioned a hidden line under any circumstances,
say so and I will make that a switch — the cost is that it can no longer sell them either.

---

## Files

| File | |
|---|---|
| `app/Services/Catalog/Visibility.php` | **new** — the one definition every surface asks |
| `database/migrations/…_add_hidden_to_products.php` | **new** — nullable, no backfill |
| `app/Models/Product.php` | the flag, and five more caches dropped on save |
| `app/Models/Category.php` | **new hooks** — toggling a department drops the same caches |
| `app/Http/Controllers/Storefront/StorefrontController.php` | feed, sitemap, category counts, dealer feed, trending |
| `app/Services/ProductRecommendations.php` | one gate for all three rails |
| `app/Services/Storefront/BuyAgain.php` | the buy-again rail |
| `app/Services/Catalog/CatalogRenderer.php` | the photo catalogue, whole-shop and per-category |
| `app/Http/Controllers/V3/DocumentController.php` | catalogue and price-list PDFs |
| `app/Services/Bot/PriceListService.php` | the bot's text price list |
| `app/Services/Bot/BotBrain.php` | browse filtered, search deliberately not |
| `app/Services/Bot/AiBrain.php` | overview filtered, grounded list marked |
| `app/Services/Bot/ProductImageResponder.php` | photo browsing |
| `app/Services/Bot/ComboEngine.php` | upsells |
| `app/Services/Bot/Offers/DailyOfferService.php` | daily offers |
| `app/Http/Controllers/Api/BotBridgeController.php` | the external bot dump |
| `app/Capability/Process/CategoryListCapability.php` | the Hide button |
| `app/Capability/Process/ProductListCapability.php` | the product form field |
| `app/Capability/Object/ProductCapability.php` | its label and current value |
| `app/Services/Catalog/ProductWriteService.php` | persisting it |
| `resources/panel/help-center.html` | a card explaining it |

---

## Checks that ran

```
t454/hidden.php      30 passed, 0 failed
t454/mutants.sh      13 killed, 0 survived
t454/contracts.sh    63 passed, 0 failed
t451–t453            all still green
```

`contracts.sh` is the real artefact here. Every product-reading surface in the system is named in it
and is either filtered or exempt **with the reason written next to it**. One decision being right is
easy; sixty places asking it is the actual feature, and that file is what tells whoever adds the
sixty-first that it exists.

Two mutants came back **equivalent** on the first run and were rewritten rather than kept: one
landed in `categoryHidden()`, which the harness never calls, and would have passed for ever while
proving nothing.

---

## What is NOT proven

None of this has run against a database or a browser. The queries are right by reading; the ones I
would check first in the field are the storefront feed and the sitemap, because those are the two
that leak hardest and the two nobody looks at.
