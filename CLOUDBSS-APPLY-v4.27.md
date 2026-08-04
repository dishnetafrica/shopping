# CloudBSS v4.27 — Family Shoppers gets real URLs, like the parts site

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.27.zip (cumulative — supersedes v4.26; 143 files) · **Migration:** none · **Delta: 3 files.**

## The problem

On the grocery/FMCG shop, opening a product changed the address to `…/#p412`. Google treats everything after a `#` as the *same page*, so your whole catalogue was **one URL with one title**. Nothing per-product could be indexed, a shared link never previewed the item, and refreshing a product lost it.

The auto-parts site got real URLs in v4.11. This gives Family Shoppers the same treatment.

## What changes

**Every product gets its own page:** `familyshoppers.net/p/sugar-2kg-kakira-412`

Served by the server with its own **title**, **meta description**, **canonical**, **og:image of the product photo** (so a WhatsApp share previews the actual item) and full **Product JSON-LD** — name, brand, SKU, category, price, currency, in-stock/out-of-stock and seller. That is what Google needs to show a product with its price in results.

**The trailing id is what resolves the page.** Rename a product and the old indexed link still works — it loads the right item and the canonical quietly updates to the new wording. No 404s from editing your own catalogue.

**Product cards are real links now.** They were `onclick` handlers, which a crawler cannot follow — so even with product pages existing, Google would never have found them. Each card's image is an `<a href>`; clicking still opens the instant overlay, but ctrl/cmd/middle-click opens a new tab like any normal link, and crawlers can walk the whole catalogue.

**Your sitemap now lists every product** (up to 5,000), alongside the homepage and category pages that were already there. Submit `familyshoppers.net/sitemap.xml` in Google Search Console.

**Old `#p412` links keep working.** Anything already shared or bookmarked is silently rewritten to the real URL and opens as before.

**Back, refresh and share all behave.** Opening a product pushes a real history entry, Back closes it, refresh reopens the same product, and the address bar always shows something worth copying.

## Nothing about the shopping experience changes

Same page, same overlay, same speed. Only the address bar and what the server puts in `<head>` are different.

## After you deploy

1. Open the shop and click any product — the address should read `/p/{name}-{id}`, not `#p…`.
2. Visit `familyshoppers.net/sitemap.xml` and confirm products are listed.
3. **Google Search Console** → submit the sitemap. Indexing takes days to weeks; this is the groundwork, not an instant result.
4. Paste a product URL into WhatsApp — the preview should show the product photo, name and price.

## Verified before ship

SEO block executed verbatim from the shipped controller against two real-shaped products: an in-stock item with no description (falls back to a generated one with category and price) and an out-of-stock item with a long description, quote marks in its name and no image. Titles, descriptions and OG tags HTML-escape correctly; both JSON-LD blocks parse as valid JSON with the right `@type`, `availability` and canonical `url`; a missing image degrades to `twitter:card=summary` rather than a broken preview.

23-check harness on the routing functions extracted verbatim from the page: slug generation (lowercase, hyphenated, punctuation and quotes stripped, capped at 60 characters, no double hyphens, non-latin names fall back to `item-{id}`); id parsing from a slug path, a bare id path, and a slug that itself contains digits (`promo-2026-offer-77` → 77); the shop root and category pages correctly yield no product; everything works under a `/{shop}` platform base as well as a custom domain; legacy `#p412` migrates to the real URL and still opens; `goDetail` pushes the URL and opens the overlay; popstate onto a product opens it and back to root closes it without error; a modified click falls through to a real navigation while a plain click is intercepted. `php -l` ×2 · every script block in the page parsed · placeholder replaced exactly once (and always, so it can never leak to a browser).
