# CloudBSS v4.11 — Real URLs (the # is gone) + per-part Google & WhatsApp SEO

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.11.zip (cumulative — supersedes v4.10.5 and everything before it) · **Migration:** same two guarded ones (skip if already run). Changed: storefront file + StorefrontController + routes.

## Why the # existed, and why it's gone

Everything after `#` never reaches the server — so to Google, your whole site was ONE page. Now every part, category and search has a real address:

- **Before:** `eaautopart.com/#/part/13` (invisible to Google)
- **Now:** `eaautopart.com/part/13` — its own indexable page

## What each part URL now serves (this is the Google + WhatsApp win)

Open view-source on any part URL after deploy and you'll see, server-rendered:

- **Its own title** — *"Drive Chain (428H-112L) — OEM JB151076 | East Africa Auto Parts"*
- **Its own description** with the price
- **og:image = the part's photo** → **sharing a part link on WhatsApp now previews the actual part with its name** — huge for a WhatsApp-first market
- **Product structured data** (JSON-LD): name, OEM as `mpn`, SKU, brand, price, currency, in-stock/out-of-stock — the format Google uses for rich product results
- Correct canonical per URL; unknown part IDs return a real **404**; search/cart/AI pages are marked **noindex** (Google guidelines)

**sitemap.xml now lists every active part** (`/part/{id}`, up to 5,000) plus `/parts` — submit it once in Google Search Console and indexing starts.

## Nothing breaks

- **Old `#/` links keep working** — WhatsApp messages, bookmarks, printed QR codes: the site translates them to the new URLs instantly on arrival.
- Refresh and direct-open work on every page (the server now answers all storefront paths; your existing endpoints are untouched — the catch-all only matches storefront views).
- Back/forward buttons work naturally; **open-in-new-tab works** on part links (product names, result rows and category cards are now real links — which is also how Google's crawler discovers pages beyond the sitemap).

## Deploy + the 2-minute test

GitHub upload → Rebuild. Then: (1) open `eaautopart.com/part/…` (any id from the catalogue) directly — the part loads; (2) view-source → see the Product JSON-LD; (3) share that URL to any WhatsApp chat → the preview shows the part; (4) open `eaautopart.com/sitemap.xml` → parts listed; (5) an old `#/` link still lands correctly. Then add the sitemap in **Google Search Console** (free) to start indexing.

## Honest expectations

Google indexing is not instant — with the sitemap submitted, expect parts to start appearing over days-to-weeks. Rich results also depend on Google's discretion. What's certain immediately: shareable per-part links with real WhatsApp previews.

## Verified before ship

Real-URL navigation (search → part → back), direct deep-load of `/part/{id}`, legacy `#/` translation, crawlable hrefs (name links, result rows, category cards), new-tab modifiers preserved, in-row Add-to-cart stays on the page (a navigation bug the harness caught: an anchor's default action isn't stopped by stopPropagation — fixed with preventDefault) · all six feature journeys green, zero console errors · **overflow matrix still perfect (16 routes × 9 widths, all 0px)** · php -l ×2 · node --check ×4 + bundle · zip 91 files md5-verified.
