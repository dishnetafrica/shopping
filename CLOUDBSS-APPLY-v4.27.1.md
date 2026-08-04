# CloudBSS v4.27.1 — fixes the WhatsApp preview on product pages

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.27.1.zip (cumulative — supersedes v4.27; 143 files) · **Migration:** none · **Delta: 2 files.**

## What was wrong

v4.27 gave every product its own page, and most of it landed correctly — the title, the description, the canonical link and the product photo are all right on the live site. But sharing a product link still previewed as **"Family Shoppers — Browse the catalogue and order on WhatsApp"** instead of the product name.

This was my mistake in v4.27, and it is worth explaining because it is a subtle one.

The page already had a shop-wide block that writes the shop's name and tagline into the sharing tags. It runs first, and it inserts those tags immediately *after* the page title. The new per-product code then replaces the title element with the product's own title and sharing tags — so the product's tags went in, but the shop-wide ones stayed sitting right underneath them.

The result was two `og:title` tags on every product page: the product first, the shop second. Which one wins is up to the app doing the reading, and the one I tested with took the **last** — the shop name. WhatsApp and Facebook would have been a coin toss. Not a broken page, but not the thing I told you to expect either.

## The fix

The shop-wide block is now skipped on product pages, so a product page carries exactly one set of sharing tags — its own. Category pages and the shop homepage are untouched and still show the shop name, as they should.

**One improvement while in there:** a product with no photo used to fall back to your shop logo for the preview image, because the shop-wide block supplied it. Suppressing that block would have left those products with a blank preview, so the product page now does the fallback itself. The structured data Google reads keeps the *real* photo only and stays empty when there isn't one — a logo must never be passed off as a product image.

## After you deploy

Paste a product link into WhatsApp — for example `familyshoppers.net/p/shan-mango-pickle-320g-66051`. The preview should now read **Shan Mango Pickle 320g** with the pickle jar, not "Family Shoppers".

WhatsApp caches previews aggressively. If you shared that same link before, it may keep showing the old card for a while — test with a product you have not shared yet, or add `?x=1` to the end of the URL to force a fresh read.

Nothing else changes. No migration, no settings, no visible difference in the shop itself.

## Verified before ship

36-check harness running the shipped head-injection code **verbatim** — extracted line-for-line out of the controller and executed against the real `shop.html`, not a retyped copy.

The same harness was first run against the v4.27 code and **failed exactly where it should**: two `og:title` and two `og:description` on a product page. Against v4.27.1 all 36 pass.

Covered: exactly one `og:title`, `og:description`, `og:image`, `<title>` and JSON-LD block on a product page, all carrying product values; a photo-less product falling back to the logo for the preview while its structured data omits the image entirely; the homepage and category pages still emitting the shop-wide tags unchanged; a deleted product still returning a hard 404 with `noindex`; a product named `Kakira "Gold" Sugar 2kg & Co` escaping correctly in every attribute while its JSON-LD still parses; `stock = null` reading as in stock; and correct self-referencing URLs under a platform base as well as a custom domain. `php -l` clean.
