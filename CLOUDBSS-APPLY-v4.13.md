# CloudBSS v4.13 — Catalogue 2.0: the design-system catalogue

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.13.zip (cumulative — supersedes v4.12 and everything before it; **120 files** — adds 3 Inter font files) · **Migration:** same two guarded ones (skip if already run). Changed code: one file — `app/Services/Catalog/CatalogRenderer.php` (+ `public/fonts/inter/` fonts).

## What this is

The generated PDF catalogue is rebuilt around a **design system** — one set of colours, one type scale, one card component — used identically on every page, instead of pages styled one by one:

- **Colours:** dark green / white / black / light grey. WhatsApp green appears only on order elements. **Red appears only on HOT DEAL promotions.**
- **Typography: real Inter** (the same font as your storefront), embedded in the PDF in exactly 3 weights (regular / semibold for names / extra-bold for titles and prices). If the font files are ever missing the catalogue silently falls back to the old font — it can never fail over typography.
- **One product card everywhere:** photo on white (~60% of the card), name, **blue OEM chip**, green **"✓ Fits …" line**, FROM + price in green extra-bold, SKU, and a **stock chip** (IN STOCK / LOW STOCK / ON REQUEST — read live from your stock numbers).
- **Page order per the spec:** cover → "What's inside" index → category intro pages (deep-green plates) → 3×3 product pages → **OEM Cross-Reference** → How to order → closing contact page.

## New pages & features

- **Real motorcycle photos** (BIKE tenant only): the index page shows a "PARTS FOR THESE MOTORCYCLES" strip with your actual Boxer/TVS photos, and the generated cover uses the hero bike shot. Grocery tenants never see any of this — their catalogues get the same design system with wholesale copy.
- **OEM Cross-Reference pages** appear automatically once ≥6 products have OEM numbers (from the v4.7.7 `oem_number` field): manufacturer number → part → your SKU → page. This is how mechanics order.
- **Footer on every page:** WhatsApp number + website + a small scan-to-order QR + price-validity + catalogue version.
- **Closing contact page:** full dark-green plate with the big WhatsApp number, QR and your business details (hours, delivery, payment — from Settings).

## What did NOT change

- **Your uploaded cover art still wins.** A portrait cover image (what eaautopart.com uses today) is still the entire page 1, untouched.
- Same public API, same 6-hour cache, same 3-stage failure ladder (a broken image or unreachable QR service can never kill the document), same photo pipeline and page budgets. Grocery/FMCG catalogues keep MOQ lines and wholesale copy.

## One honest design call

The spec asked for a QR on every product card. A scannable QR needs ~12 mm of print; at 9 cards per page that would shrink photos to ~40% of the card and add 9 identical-looking codes per page. The shipped design keeps **images at ~60%** and puts one scan-to-order QR in every page footer + the order guide + cover + contact page. If you'd rather have per-card QRs at 6 bigger cards per page, say the word — the component supports it.

## Deploy + test

GitHub upload → Rebuild. Then in WhatsApp ask the bot for the catalogue (or open `/catalogue`): page 1 = your uploaded cover; page 2 = index with your bikes; product pages = new cards with OEM chips and stock; back = cross-reference + order guide + green contact page. First render takes ~10–30 s (photo cache), after that it's instant for 6 h.

**Note:** the sample PDF sent in chat uses drawn placeholder part images (the sandbox can't reach boraboda.com) — on your server every card shows the real part photo, exactly like today.

## Verified before ship

23-page BIKE render (cover art mode + generated-cover mode), grocery regression render (no bike content, wholesale copy, MOQ lines intact), OEM cross-reference pagination, Inter embedding confirmed via pdffonts (4 subsets) with DejaVu fallback path, stock chips from live column, php -l, zip md5-verified against the repo.
